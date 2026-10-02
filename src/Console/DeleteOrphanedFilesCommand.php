<?php

declare(strict_types=1);

namespace ADT\Files\Console;

use ADT\Files\Entities\File;
use Doctrine\ORM\EntityManagerInterface;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
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
	private string $dataDir;
	private ?string $privateDataDir;

	public function __construct(EntityManagerInterface $em, string $dataDir, ?string $privateDataDir = null)
	{
		parent::__construct();

		$this->em = $em;
		$this->dataDir = $dataDir;
		$this->privateDataDir = $privateDataDir;
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

		$configured = array_values(array_unique(array_filter([$this->dataDir, $this->privateDataDir])));
		$directories = [];

		foreach ($configured as $directory) {
			// adresar vznika az pri prvnim ulozeni souboru, takze dokud aplikace zadny
			// privatni soubor nema, privatni adresar proste neni - neni co prochazet,
			// ale neni to ani duvod skoncit
			if (is_dir($directory)) {
				$directories[] = $directory;
			} else {
				$output->writeln("'{$directory}' does not exist, skipping.");
			}
		}

		if (!$directories) {
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
		$failures = [];

		foreach ($directories as $directory) {
			$output->writeln($directory);

			foreach ($this->scan($directory) as $relativePath => $file) {
				if (isset($known[$relativePath])) {
					continue;
				}

				// soubor vznika az v postPersist, takze mezi zapisem na disk a commitem
				// transakce vypada rozdelany upload jako osirely - stari ho ochrani
				if ($minAge > 0 && $file->getMTime() > time() - $minAge) {
					continue;
				}

				$orphans++;
				$bytes += $file->getSize();

				if (!$exec) {
					$output->writeln('  ' . $relativePath);
					continue;
				}

				if (!@unlink($file->getPathname())) {
					$failures[] = $relativePath;
				}
			}
		}

		$output->writeln(sprintf(
			'%s %d orphaned files, %s.',
			$exec ? 'Deleted' : 'Would delete',
			$orphans,
			$this->formatSize($bytes)
		));

		if ($failures) {
			$output->writeln(sprintf('Could not be deleted (%d): %s', count($failures), implode(', ', $failures)));

			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}

	/**
	 * Klicem je cesta relativni k adresari, protoze presne to je obsah sloupce filename.
	 *
	 * @return iterable<string, SplFileInfo>
	 */
	protected function scan(string $directory): iterable
	{
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
		);

		foreach ($iterator as $file) {
			if (!$file->isFile()) {
				continue;
			}

			yield substr($file->getPathname(), strlen($directory) + 1) => $file;
		}
	}

	/**
	 * Jmena ze VSECH file entit dohromady, nerozlisene podle adresare. Soubor, na ktery
	 * ukazuje jakykoli radek, tak prezije i kdyz lezi "ve spatnem" adresari - u prikazu,
	 * ktery maze, je lepsi nechat navic nez omylem smazat.
	 *
	 * @return array<string, true>
	 */
	protected function getKnownFilenames(): array
	{
		$known = [];

		foreach ($this->getFileClasses() as $class) {
			foreach ($this->em->createQuery('SELECT e.filename FROM ' . $class . ' e WHERE e.filename IS NOT NULL')->getSingleColumnResult() as $filename) {
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
