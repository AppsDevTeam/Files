<?php

declare(strict_types=1);

namespace ADT\Files\Console;

use ADT\Files\Entities\File;
use ADT\Files\Helpers;
use ADT\Files\Listeners\FileListener;
use ADT\Files\Storage\LocalStorage;
use ADT\Files\Storage\Storage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Prenese soubory z puvodnich ulozist (adresare, ze kterych aplikace zacala) do novych -
 * typicky z disku do S3. Cile jsou bud argumenty prikazu (prenos pred prepnutim listeneru),
 * nebo, kdyz chybi, uloziste FileListeneru (prenos po prepnuti). Jde po radcich, ne po
 * adresari: soubor bez radku je sirotek (files:delete-orphans), ne neco k prenosu.
 *
 * Bez --exec jen spocita, co by se preneslo. S --exec kopiruje a zdroj NECHAVA, takze
 * se konfigurace da vratit; --delete zdroj po overene kopii smaze (i pri dalsim behu u
 * souboru, ktere uz v cili jsou). Kazdy beh je mozne opakovat: soubor, ktery uz v cili
 * lezi ve spravne velikosti, se preskoci. Chyby se vypisou s id a beh pokracuje.
 *
 * Aplikace muze mezitim bezet: s cili v argumentech jde vsechno nove porad do puvodnich
 * ulozist a prenos se dokonci dalsim behem po prepnuti (soubor uz v cili se preskoci);
 * po prepnuti jdou nove soubory rovnou do cile a stare se "nenajdou", dokud nejsou preneseny.
 */
#[AsCommand(
	name: 'files:migrate-storage',
	description: 'Copies (and with --delete moves) the files of every row from the former storages into the ones FileListener uses now'
)]
class MigrateStorageCommand extends Command
{
	private EntityManagerInterface $em;
	private FileListener $listener;
	private Storage $from;
	private ?Storage $privateFrom;
	private ?Storage $archiveFrom;
	private ?Storage $privateArchiveFrom;
	private ?Storage $to;
	private ?Storage $privateTo;
	private ?Storage $archiveTo;
	private ?Storage $privateArchiveTo;

	/**
	 * Zdroje i cile maji stejny vyznam jako argumenty FileListeneru. Bez $to jsou cilem
	 * uloziste listeneru (jeho soucasna konfigurace); s $to plati ctverice cilu z argumentu
	 * a listener se nepouzije.
	 */
	public function __construct(EntityManagerInterface $em, FileListener $listener, string|Storage $from, string|Storage|null $privateFrom = null, string|Storage|null $archiveFrom = null, string|Storage|null $privateArchiveFrom = null, string|Storage|null $to = null, string|Storage|null $privateTo = null, string|Storage|null $archiveTo = null, string|Storage|null $privateArchiveTo = null)
	{
		parent::__construct();

		$this->em = $em;
		$this->listener = $listener;
		$this->from = self::storage($from);
		$this->privateFrom = self::storage($privateFrom);
		$this->archiveFrom = self::storage($archiveFrom);
		$this->privateArchiveFrom = self::storage($privateArchiveFrom);
		$this->to = self::storage($to);
		$this->privateTo = self::storage($privateTo);
		$this->archiveTo = self::storage($archiveTo);
		$this->privateArchiveTo = self::storage($privateArchiveTo);
	}

	private static function storage(string|Storage|null $storage): ?Storage
	{
		return is_string($storage) ? new LocalStorage($storage) : $storage;
	}

	protected function configure(): void
	{
		$this
			->addOption('exec', null, InputOption::VALUE_NONE, 'Actually copy the files; without it the run only reports what it would do')
			->addOption('delete', null, InputOption::VALUE_NONE, 'Delete the source once the copy is verified (with --exec)')
			->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Process only this entity class instead of every file entity')
			->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'How many rows to read at once', '500');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$exec = (bool) $input->getOption('exec');
		$delete = $exec && $input->getOption('delete');
		$batchSize = max(1, (int) $input->getOption('batch-size'));
		$prefix = $exec ? '' : '[nothing is copied without --exec] ';

		if ($input->getOption('delete') && !$exec) {
			$output->writeln('--delete has no effect without --exec, nothing is deleted.');
		}

		$classes = $this->getFileClasses();

		if ($entity = $input->getOption('entity')) {
			if (!in_array($entity, $classes, true)) {
				$output->writeln("'{$entity}' is not a mapped entity implementing " . File::class . '.');

				return Command::FAILURE;
			}

			$classes = [$entity];
		}

		$copied = 0;
		$bytes = 0;
		$present = 0;
		$deleted = 0;
		$same = 0;
		/** @var array<string, array<string, list<mixed>>> $problems trida => duvod => id */
		$problems = [];

		foreach ($classes as $class) {
			$identifier = $this->em->getClassMetadata($class)->getSingleIdentifierFieldName();
			$output->writeln($prefix . $class);
			$lastId = null;

			// po davkach podle id, ne vsechno najednou - miliony radku se do pameti nevejdou
			while ($chunk = $this->getRows($class, $identifier, $lastId, $batchSize)) {
				$lastId = end($chunk)['id'];

				foreach ($chunk as $row) {
					if ($row['filename'] === null) {
						$problems[$class]['Rows with no filename, the upload never finished'][] = $row['id'];
						continue;
					}

					$source = $this->sourceFor($row);
					$target = $this->targetFor($row);

					if ($source === null) {
						$problems[$class]['Archived rows, but no archive source is configured'][] = $row['id'];
						continue;
					}

					if ($source->getPath('') === $target->getPath('')) {
						$same++;
						continue;
					}

					$name = $row['filename'];
					$sourceSize = $source->getSize($name);
					$targetSize = $target->getSize($name);
					$expected = $row['size'] ?? $sourceSize;

					if ($targetSize !== null && $targetSize === $expected) {
						$present++;
						if ($delete && $sourceSize !== null) {
							$this->deleteSource($source, $name, $row, $class, $problems) && $deleted++;
						}
						continue;
					}

					if ($sourceSize === null) {
						$problems[$class]['Missing in the source'][] = $row['id'];
						continue;
					}

					$copied++;
					$bytes += $sourceSize;
					$output->writeln('  ' . $source->getPath($name) . ' -> ' . $target->getPath($name), OutputInterface::VERBOSITY_VERBOSE);

					if (!$exec) {
						continue;
					}

					try {
						$this->copy($source, $target, $name, $row);
					} catch (Throwable $e) {
						$copied--;
						$bytes -= $sourceSize;
						$problems[$class]['Copy failed: ' . $e->getMessage()][] = $row['id'];
						continue;
					}

					if ($delete) {
						$this->deleteSource($source, $name, $row, $class, $problems) && $deleted++;
					}
				}
			}
		}

		$output->writeln($prefix . sprintf(
			'%s %d files, %s; %d already in the target, %d deleted from the source, %d rows whose source and target are the same storage.',
			$exec ? 'Copied' : 'Would copy',
			$copied,
			$this->formatSize($bytes),
			$present,
			$deleted,
			$same
		));

		// id pohromade a oddelena carkou schvalne - seznam, se kterym bude nekdo dal pracovat
		foreach ($problems as $class => $byReason) {
			$output->writeln($class);

			foreach ($byReason as $reason => $ids) {
				$output->writeln(sprintf('  %s (%d): %s', $reason, count($ids), implode(', ', $ids)));
			}
		}

		return $problems ? Command::FAILURE : Command::SUCCESS;
	}

	/**
	 * Kopie overena proti radku: put() dostane hash z DB (na disku se s nim porovna kopie,
	 * S3 ho overi jako Content-MD5), takze zdroj, ktery uz radku neodpovida, neprojde.
	 *
	 * @param array{id: mixed, filename: string, isPrivate: bool, isArchived: bool, mimeType: ?string, hash: ?string, size: ?int} $row
	 * @throws \Exception
	 */
	protected function copy(Storage $source, Storage $target, string $name, array $row): void
	{
		$downloaded = !$source instanceof LocalStorage;
		$path = $downloaded ? Helpers::downloadToTemporaryFile($source, $name) : $source->getPath($name);

		try {
			$target->put($name, $path, $row['mimeType'], $row['hash']);
		} finally {
			if ($downloaded) {
				@unlink($path);
			}
		}
	}

	/**
	 * @param array<string, array<string, list<mixed>>> $problems
	 */
	protected function deleteSource(Storage $source, string $name, array $row, string $class, array &$problems): bool
	{
		try {
			$source->delete($name);
		} catch (Throwable $e) {
			$problems[$class]['Delete of the source failed: ' . $e->getMessage()][] = $row['id'];

			return false;
		}

		return true;
	}

	/**
	 * Cilove uloziste radku - z argumentu, kdyz jsou, jinak listener.
	 *
	 * @param array{isPrivate: bool, isArchived: bool} $row
	 */
	protected function targetFor(array $row): Storage
	{
		if ($this->to === null) {
			return $this->listener->getStorage($row['isPrivate'], $row['isArchived']);
		}

		return FileListener::resolve($this->to, $this->privateTo, $this->archiveTo, $this->privateArchiveTo, $row['isPrivate'], $row['isArchived']);
	}

	/**
	 * Puvodni uloziste radku - stejne pravidlo jako ve FileListeneru, jen nad puvodni
	 * ctverici. Archivovany radek bez archivniho zdroje nema odkud prenaset.
	 *
	 * @param array{isPrivate: bool, isArchived: bool} $row
	 */
	protected function sourceFor(array $row): ?Storage
	{
		if ($row['isArchived'] && ($row['isPrivate'] ? $this->privateArchiveFrom ?? $this->archiveFrom : $this->archiveFrom) === null) {
			return null;
		}

		return FileListener::resolve($this->from, $this->privateFrom, $this->archiveFrom, $this->privateArchiveFrom, $row['isPrivate'], $row['isArchived']);
	}

	/**
	 * @param mixed $after id posledniho radku predchozi davky, null = od zacatku
	 * @return array<array{id: mixed, filename: ?string, isPrivate: bool, isArchived: bool, mimeType: ?string, hash: ?string, size: ?int}>
	 */
	protected function getRows(string $class, string $identifier, mixed $after, int $limit): array
	{
		$query = $this->em
			->createQuery(
				'SELECT e.' . $identifier . ' AS id, e.filename AS filename, e.isPrivate AS isPrivate, e.isArchived AS isArchived,'
				. ' e.mimeType AS mimeType, e.hash AS hash, e.size AS size FROM ' . $class . ' e'
				. ($after !== null ? ' WHERE e.' . $identifier . ' > :after' : '')
				. ' ORDER BY e.' . $identifier
			)
			->setMaxResults($limit);

		if ($after !== null) {
			$query->setParameter('after', $after);
		}

		return $query->getArrayResult();
	}

	/**
	 * U dedicnosti se radky potomka vraci i dotazem na rodice - kazda tabulka (trida, ktera
	 * filename deklaruje) jen jednou, jinak by se jeji soubory prochazely dvakrat.
	 *
	 * @return class-string<File>[]
	 */
	protected function getFileClasses(): array
	{
		$classes = [];
		$tables = [];

		foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
			if ($metadata->isMappedSuperclass || $metadata->getReflectionClass()->isAbstract()) {
				continue;
			}

			if (!$metadata->getReflectionClass()->implementsInterface(File::class)) {
				continue;
			}

			$table = $metadata->fieldMappings['filename']->inherited ?? $metadata->getName();
			if (isset($tables[$table])) {
				continue;
			}
			$tables[$table] = true;

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
