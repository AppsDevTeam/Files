<?php

declare(strict_types=1);

namespace ADT\Files\Console;

use ADT\Files\Entities\File;
use ADT\Files\Storage\LocalStorage;
use ADT\Files\Storage\Storage;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Projde uloziste a smaze soubory, ke kterym uz neni zadny radek - protejsek
 * FillMimeTypeCommand, ktery resi opacnou nerovnovahu.
 *
 * Ve vychozim stavu NIC NEMAZE, jen vypisuje. Mazani se zapina --exec, protoze chybne
 * nastaveny datovy adresar nebo aplikace, ktera si do nej uklada i neco jineho, by jinak
 * znamenaly tiche smazani veci, ktere nejdou vratit.
 *
 * Uloziste jsou stejna jako ve FileListeneru - adresare nebo Storage (S3 bucket).
 */
#[AsCommand(
	name: 'files:delete-orphans',
	description: 'Lists (and with --exec deletes) files in the data directories that no row points to'
)]
class DeleteOrphanedFilesCommand extends Command
{
	/* Jak dlouho se soubor povazuje za rozdelany upload */
	public const int DEFAULT_MIN_AGE = 86400;

	private EntityManagerInterface $em;
	private Storage $dataStorage;
	private ?Storage $privateDataStorage;

	public function __construct(EntityManagerInterface $em, string|Storage $dataDir, string|Storage|null $privateDataDir = null)
	{
		parent::__construct();

		$this->em = $em;
		$this->dataStorage = $dataDir instanceof Storage ? $dataDir : new LocalStorage($dataDir);
		$this->privateDataStorage = is_string($privateDataDir) ? new LocalStorage($privateDataDir) : $privateDataDir;
	}

	protected function configure(): void
	{
		$this
			->addOption('exec', null, InputOption::VALUE_NONE, 'Actually delete the files; without it nothing is touched')
			->addOption('min-age', null, InputOption::VALUE_REQUIRED, 'Leave files modified in the last N seconds alone', (string) self::DEFAULT_MIN_AGE);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$exec = (bool) $input->getOption('exec');
		$minAge = max(0, (int) $input->getOption('min-age'));

		/** @var Storage[] $storages */
		$storages = [];

		foreach ([$this->dataStorage, $this->privateDataStorage] as $storage) {
			// verejny a privatni adresar muze byt tentyz - prochazet jen jednou
			if ($storage === null || isset($storages[$storage->getPath('')])) {
				continue;
			}

			// adresar vznika az pri prvnim ulozeni souboru, takze dokud aplikace zadny
			// privatni soubor nema, privatni adresar proste neni - neni co prochazet,
			// ale neni to ani duvod skoncit
			if ($storage instanceof LocalStorage && !is_dir($storage->getDirectory())) {
				$output->writeln("'{$storage->getDirectory()}' does not exist, skipping.");
				continue;
			}

			$storages[$storage->getPath('')] = $storage;
		}

		if (!$storages) {
			$output->writeln('None of the configured data directories exists. Nothing was touched.');

			return Command::FAILURE;
		}

		$known = $this->getKnownFilenames();

		if (!$known) {
			// prazdna tabulka a plny adresar znamena spis spatne zadany adresar nebo entitu,
			// ktera se nenamapovala, nez ze je opravdu vsechno k zahozeni
			$output->writeln('No row references any file at all, which looks more like a misconfiguration than an empty table. Nothing was touched.');

			return Command::FAILURE;
		}

		$output->writeln(($exec ? '' : '[nothing is deleted without --exec] ')
			. sprintf('%d files referenced by a row.', count($known)));

		$orphans = 0;
		$bytes = 0;
		$emptyDirectories = 0;
		$failures = [];

		foreach ($storages as $storage) {
			$output->writeln($storage->getPath(''));

			foreach ($storage->list() as $name => [$size, $modified]) {
				if (isset($known[$name])) {
					continue;
				}

				// soubor vznika az v postPersist, takze mezi zapisem do uloziste a commitem
				// transakce vypada rozdelany upload jako osirely - stari ho ochrani
				if ($minAge > 0 && $modified > time() - $minAge) {
					continue;
				}

				$orphans++;
				$bytes += $size;

				// plna cesta, ne relativni - aplikace s verejnym i privatnim adresarem ma
				// v obou stejne pojmenovane sekce, takze z relativni cesty nejde poznat, kde
				// soubor lezi, a vypis nejde rovnou predat dal (ls, chown, zaloha...)
				//
				// i s --exec a jeste pred smazanim: prochazeni milionu souboru trva dlouho,
				// bez vypisu nejde poznat, ze se neco deje, a kdyz se beh prerusi, je videt,
				// co uz je pryc
				$path = $storage->getPath($name);
				$output->writeln('  ' . $path);

				if (!$exec) {
					continue;
				}

				try {
					$storage->delete($name);
				} catch (Throwable) {
					$failures[] = $path;
					continue;
				}
				if ($storage->getSize($name) !== null) {
					$failures[] = $path;
				}
			}

			if ($exec && $storage instanceof LocalStorage) {
				$emptyDirectories += $storage->removeEmptyDirectories();
			}
		}

		$output->writeln($exec
			? sprintf('Deleted %d orphaned files, %s, and %d empty directories.', $orphans, $this->formatSize($bytes), $emptyDirectories)
			: sprintf('Would delete %d orphaned files, %s, plus whatever directories that leaves empty.', $orphans, $this->formatSize($bytes)));

		if ($failures) {
			$output->writeln(sprintf('Could not be deleted (%d): %s', count($failures), implode(', ', $failures)));

			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}

	/**
	 * Jmena ze VSECH file entit dohromady, nerozlisene podle adresare. Soubor, na ktery
	 * ukazuje jakykoli radek, tak prezije i kdyz lezi "ve spatnem" adresari - u prikazu,
	 * ktery maze, je lepsi nechat navic nez omylem smazat.
	 *
	 * Primo SQL nad tabulkou, ne DQL: DQL pouziva zapnute Doctrine filtry a soft-delete
	 * filtr by skryl smazane radky, ktere si soubor na disku zamerne nechavaji (kvuli
	 * obnoveni, viz FileListener::preRemove). Jejich soubory by pak vypadaly jako osirele.
	 *
	 * @return array<string, true>
	 */
	protected function getKnownFilenames(): array
	{
		$known = [];
		$connection = $this->em->getConnection();
		$platform = $connection->getDatabasePlatform();
		$quoteStrategy = $this->em->getConfiguration()->getQuoteStrategy();
		$queried = [];

		foreach ($this->getFileClasses() as $class) {
			$metadata = $this->em->getClassMetadata($class);

			// u dedicnosti je sloupec v tabulce tridy, ktera pole deklaruje - u single table
			// je to ta sama tabulka pro vsechny potomky, takze kazdou dotazovat jen jednou
			$declaringClass = $metadata->fieldMappings['filename']->inherited ?? $class;
			$declaringMetadata = $this->em->getClassMetadata($declaringClass);

			$table = $quoteStrategy->getTableName($declaringMetadata, $platform);
			$column = $quoteStrategy->getColumnName('filename', $declaringMetadata, $platform);

			if (isset($queried[$table])) {
				continue;
			}
			$queried[$table] = true;

			foreach ($connection->executeQuery("SELECT $column FROM $table WHERE $column IS NOT NULL")->iterateColumn() as $filename) {
				$known[$filename] = true;
			}
		}

		return $known;
	}

	/**
	 * @return class-string<File>[]
	 */
	protected function getFileClasses(): array
	{
		$classes = [];

		foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
			if ($metadata->isMappedSuperclass || $metadata->getReflectionClass()->isAbstract()) {
				continue;
			}

			if (!$metadata->getReflectionClass()->implementsInterface(File::class)) {
				continue;
			}

			$classes[] = $metadata->getName();
		}

		return $classes;
	}

	protected function formatSize(int $bytes): string
	{
		$units = ['B', 'kB', 'MB', 'GB', 'TB'];
		$unit = 0;

		while ($bytes >= 1024 && $unit < count($units) - 1) {
			$bytes = intdiv($bytes, 1024);
			$unit++;
		}

		return $bytes . ' ' . $units[$unit];
	}
}
