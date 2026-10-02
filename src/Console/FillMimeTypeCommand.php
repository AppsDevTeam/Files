<?php

declare(strict_types=1);

namespace ADT\Files\Console;

use ADT\Files\Entities\File;
use ADT\Files\Helpers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Doplní mime type řádkům, které vznikly dřív, než se sloupec zaváděl.
 *
 * Schválně nehydratuje entity a staví cestu k souboru sama ze scalar selectu: jakmile je
 * $mimeType v FileTrait not nullable, hydratace řádku s NULL skončí na TypeError, takže
 * command, který entity načítá, by se nedal pustit právě na datech, která má opravit.
 */
#[AsCommand(
	name: 'files:fill-mime-type',
	description: 'Detects and stores the mime type of files saved before the column was introduced'
)]
class FillMimeTypeCommand extends Command
{
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
			->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Process only this entity class instead of every file entity')
			->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'How many rows to read and write at once', '500')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be filled in, write nothing');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int
	{
		$dryRun = (bool) $input->getOption('dry-run');
		$batchSize = max(1, (int) $input->getOption('batch-size'));
		$prefix = $dryRun ? '[dry run] ' : '';

		$classes = $this->getFileClasses();

		if ($entity = $input->getOption('entity')) {
			if (!in_array($entity, $classes, true)) {
				$output->writeln("'{$entity}' is not a mapped entity implementing " . File::class . '.');

				return Command::FAILURE;
			}

			$classes = [$entity];
		}

		if (!$classes) {
			$output->writeln('There is no mapped entity implementing ' . File::class . '.');

			return Command::SUCCESS;
		}

		$detected = 0;
		$unchanged = 0;
		$fallbacks = [];

		foreach ($classes as $class) {
			$identifier = $this->em->getClassMetadata($class)->getSingleIdentifierFieldName();
			$rows = $this->getRowsToFill($class, $identifier);

			$output->writeln($prefix . sprintf('%s: %d rows with no usable mime type', $class, count($rows)));

			foreach (array_chunk($rows, $batchSize) as $chunk) {
				// seskupené podle mime typu, aby batch stál tolik dotazů, kolik je různých
				// typů, místo jednoho dotazu na řádek
				$idsByMimeType = [];

				foreach ($chunk as $row) {
					[$mimeType, $reason] = $this->resolveMimeType($row);

					if ($reason === null) {
						$detected++;
					} else {
						$fallbacks[] = $class . ' #' . $row['id'] . ': ' . $reason;
					}

					// radky, ktere uz tu hodnotu maji, se nepreepisuji toutez hodnotou -
					// jinak by kazdy beh zbytecne prepsal vsechno, co zjistit nejde
					if ($mimeType === $row['mimeType']) {
						$unchanged++;
						continue;
					}

					$idsByMimeType[$mimeType][] = $row['id'];
				}

				foreach ($idsByMimeType as $mimeType => $ids) {
					if (!$dryRun) {
						// nízkoúrovňový update schválně, stejně jako to po uploadu dělá
						// listener - flush by v aplikaci bouchnul timestampy a change logy
						// úplně všech souborů
						$this->em->createQuery('UPDATE ' . $class . ' e SET e.mimeType = :mimeType WHERE e.' . $identifier . ' IN (:ids)')
							->setParameter('mimeType', $mimeType)
							->setParameter('ids', $ids)
							->execute();
					}

					$output->writeln(sprintf('  %s: %d', $mimeType, count($ids)));
				}
			}
		}

		$output->writeln($prefix . sprintf(
			'Done: %d detected from the file, %d left at %s, %d already had the value they ended up with.',
			$detected,
			count($fallbacks),
			Helpers::DEFAULT_MIME_TYPE,
			$unchanged
		));

		foreach ($fallbacks as $fallback) {
			$output->writeln('  ' . $fallback);
		}

		if (!$dryRun) {
			$output->writeln('No row is left without a mime type, so the column can be made not nullable.');
		}

		return Command::SUCCESS;
	}

	/**
	 * Soubory, které na disku nejsou, dostanou výchozí mime type místo toho, aby zůstaly
	 * prázdné - jinak by se sloupec nedal přepnout na not nullable kvůli hrstce řádků,
	 * se kterými se už stejně nedá nic dělat.
	 *
	 * @param array{id: mixed, filename: ?string, isPrivate: bool} $row
	 * @return array{string, ?string} mime type a důvod, proč je to jen fallback
	 */
	protected function resolveMimeType(array $row): array
	{
		if ($row['filename'] === null) {
			return [Helpers::DEFAULT_MIME_TYPE, 'the row has no filename, the upload never finished'];
		}

		$baseDirectory = $row['isPrivate'] ? $this->privateDataDir : $this->dataDir;

		if ($baseDirectory === null) {
			return [Helpers::DEFAULT_MIME_TYPE, 'the file is private, but no private data dir is configured'];
		}

		$path = $baseDirectory . '/' . $row['filename'];

		if (!is_file($path) || !is_readable($path)) {
			return [Helpers::DEFAULT_MIME_TYPE, 'file is missing'];
		}

		if (!$mimeType = @mime_content_type($path)) {
			return [Helpers::DEFAULT_MIME_TYPE, 'mime type could not be detected'];
		}

		return [$mimeType, null];
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

	/**
	 * Krome prazdnych bere i radky, ktere uz drzi DEFAULT_MIME_TYPE. Ta hodnota znamena
	 * "nezjisteno", ne "zjisteno, ze je to binarka": zapisuje ji listener, kdyz soubor
	 * neslo precist, a hlavne ji po radcich s NULL rozsype migrace prepinajici sloupec na
	 * not null. Kdyby se tu braly jen NULLy, po takove migraci uz by command nemel co
	 * doplnit a skutecne typy by zustaly nezjistene navzdy.
	 *
	 * @return array<array{id: mixed, filename: ?string, isPrivate: bool, mimeType: ?string}>
	 */
	protected function getRowsToFill(string $class, string $identifier): array
	{
		return $this->em
			->createQuery('SELECT e.' . $identifier . ' AS id, e.filename AS filename, e.isPrivate AS isPrivate, e.mimeType AS mimeType FROM ' . $class . ' e WHERE e.mimeType IS NULL OR e.mimeType = :default')
			->setParameter('default', Helpers::DEFAULT_MIME_TYPE)
			->getArrayResult();
	}
}
