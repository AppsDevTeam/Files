<?php

declare(strict_types=1);

namespace ADT\Files\Tests\ContentImages;

use ADT\Files\ContentImages\ContentImageListener;
use ADT\Files\ContentImages\ContentImageOwner;
use ADT\Files\ContentImages\ContentImageSynchronizer;
use ADT\Files\ContentImages\Downloader;
use ADT\Files\ContentImages\GedmoTranslations;
use ADT\Files\Tests\Fixtures\Entity\TestArticle;
use ADT\Files\Tests\Fixtures\Entity\TestContentImage;
use ADT\Files\Tests\Fixtures\Entity\TestFile;
use ADT\Files\Tests\Fixtures\EntityManagerFactory;
use Doctrine\ORM\EntityManagerInterface;
use Gedmo\Translatable\Entity\Translation;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContentImagesTest extends TestCase
{
	private const string DATA_URL = '/data/files';

	private string $dataDir;

	private EntityManagerInterface $em;

	private ContentImageSynchronizer $synchronizer;

	private ContentImageListener $listener;

	private object $downloader;

	protected function setUp(): void
	{
		$this->dataDir = sys_get_temp_dir() . '/adt-files-test-' . bin2hex(random_bytes(8));
		mkdir($this->dataDir, 0777, true);

		$this->em = EntityManagerFactory::create($this->dataDir, dataUrl: self::DATA_URL);

		$this->downloader = new class implements Downloader {
			/** @var array<string, string> */
			public array $responses = [];

			/** @var string[] */
			public array $requested = [];

			public function download(string $url, int $maxSize): ?string
			{
				$this->requested[] = $url;
				return $this->responses[$url] ?? null;
			}
		};

		$translations = new GedmoTranslations($this->em);
		$this->synchronizer = new ContentImageSynchronizer($this->em, TestFile::class, TestContentImage::class, self::DATA_URL, $this->dataDir, ['https://shop.test/'], $this->downloader, $translations);
		$this->listener = new ContentImageListener($this->em, $this->synchronizer, $translations);
		$this->em->getEventManager()->addEventSubscriber($this->listener);
	}

	protected function tearDown(): void
	{
		self::removeDirectory($this->dataDir);
	}

	private static function img(string $src): string
	{
		return '<p><img style="width: 100%;" src="' . htmlspecialchars($src) . '"></p>';
	}

	private static function dataUri(string $content): string
	{
		return 'data:image/png;base64,' . base64_encode($content);
	}

	/**
	 * @return string[]
	 */
	private static function sources(?string $html): array
	{
		preg_match_all('~<img[^>]*\ssrc="([^"]*)"~', (string) $html, $matches);

		return array_map(fn (string $src) => html_entity_decode($src), $matches[1]);
	}

	private function createArticle(?string $content, ?array $faq = null): TestArticle
	{
		$article = new TestArticle()->setContent($content)->setFaq($faq);
		$this->em->persist($article);
		$this->em->flush();

		return $article;
	}

	/**
	 * @return TestContentImage[]
	 */
	private function owned(ContentImageOwner $owner): array
	{
		return $this->synchronizer->findOwned($owner);
	}

	private function fileByUrl(string $url): TestFile
	{
		$file = $this->em->getRepository(TestFile::class)->findOneBy(['filename' => substr($url, strlen(self::DATA_URL) + 1)]);
		self::assertNotNull($file, 'No file for ' . $url);

		return $file;
	}

	#[Test]
	public function savingAnOwnerTurnsBase64IntoItsFile(): void
	{
		$png = HtmlTest::png('a');

		$article = $this->createArticle(self::img(self::dataUri($png)));

		$sources = self::sources($article->getContent());
		self::assertCount(1, $sources);
		self::assertStringStartsWith(self::DATA_URL . '/', $sources[0]);
		self::assertStringContainsString('style="width: 100%;"', $article->getContent());

		$owned = $this->owned($article);
		self::assertCount(1, $owned);
		self::assertSame($sources[0], $owned[0]->getFile()->getUrl());
		self::assertSame($png, $owned[0]->getFile()->getContents());
	}

	#[Test]
	public function changingTheHtmlSyncsAgainAndOtherChangesDoNot(): void
	{
		$article = $this->createArticle('<p>text</p>');
		self::assertCount(0, $this->owned($article));

		$article->setTitle('only the title');
		$this->em->flush();
		self::assertCount(0, $this->owned($article));

		$article->setContent(self::img(self::dataUri(HtmlTest::png('b'))));
		$this->em->flush();
		self::assertCount(1, $this->owned($article));
	}

	#[Test]
	public function base64SavedBeforeTheListenerIsConvertedOnAnySave(): void
	{
		$content = self::img(self::dataUri(HtmlTest::png('r')));
		$this->listener->pause();
		$article = $this->createArticle($content);
		$this->listener->resume();

		$article->setTitle('only the title');
		$this->em->flush();

		self::assertStringStartsWith(self::DATA_URL . '/', self::sources($article->getContent())[0]);
		self::assertCount(1, $this->owned($article));
	}

	#[Test]
	public function sameImageTwiceIsOneFile(): void
	{
		$src = self::dataUri(HtmlTest::png('c'));

		$article = $this->createArticle(self::img($src) . self::img($src));

		$sources = self::sources($article->getContent());
		self::assertSame($sources[0], $sources[1]);
		self::assertCount(1, $this->owned($article));
	}

	#[Test]
	public function copiedHtmlGetsFilesOfItsOwn(): void
	{
		$original = $this->createArticle(self::img(self::dataUri(HtmlTest::png('d'))) . self::img(self::dataUri(HtmlTest::png('e'))));
		$originalSources = self::sources($original->getContent());

		$copy = $this->createArticle($original->getContent());
		$copySources = self::sources($copy->getContent());

		self::assertCount(2, $copySources);
		self::assertSame([], array_intersect($originalSources, $copySources));
		self::assertCount(2, $this->owned($copy));
		self::assertCount(2, $this->owned($original));
		foreach ($copySources as $i => $src) {
			self::assertSame($this->fileByUrl($originalSources[$i])->getContents(), $this->fileByUrl($src)->getContents());
		}
	}

	#[Test]
	public function relativeAndAbsoluteUrlsOfAnOwnedFileAreNormalised(): void
	{
		$article = $this->createArticle(self::img(self::dataUri(HtmlTest::png('f'))));
		$url = self::sources($article->getContent())[0];

		$article->setContent(self::img('../../..' . $url) . self::img('https://shop.test' . $url));
		$this->em->flush();

		self::assertSame([$url, $url], self::sources($article->getContent()));
		self::assertCount(1, $this->owned($article));
	}

	#[Test]
	public function fileLeftOutOfTheHtmlIsDeleted(): void
	{
		$article = $this->createArticle(self::img(self::dataUri(HtmlTest::png('g'))) . self::img(self::dataUri(HtmlTest::png('h'))));
		[$kept, $removed] = self::sources($article->getContent());
		$removedPath = $this->fileByUrl($removed)->getPath();

		$article->setContent(self::img($kept));
		$this->em->flush();

		$owned = $this->owned($article);
		self::assertCount(1, $owned);
		self::assertSame($kept, $owned[0]->getFile()->getUrl());
		self::assertFileDoesNotExist($removedPath);
	}

	#[Test]
	public function externalImageIsDownloadedOnce(): void
	{
		$url = 'https://cdn.example.com/photo.png?v=2&s=1';
		$this->downloader->responses[$url] = HtmlTest::png('i');

		$article = $this->createArticle(self::img($url));
		$file = $this->fileByUrl(self::sources($article->getContent())[0]);
		self::assertSame(HtmlTest::png('i'), $file->getContents());
		self::assertStringEndsWith('photo.png', $file->getFilename());
		self::assertSame($url, $this->owned($article)[0]->getSourceUrl());

		// an import writing the same external HTML again
		$article->setContent(self::img($url));
		$this->em->flush();

		self::assertSame([$url], $this->downloader->requested);
		self::assertSame([$file->getUrl()], self::sources($article->getContent()));
		self::assertCount(1, $this->owned($article));
	}

	#[Test]
	public function unavailableExternalImageStaysAndWarns(): void
	{
		$warnings = [];
		$this->listener->onWarning[] = function (ContentImageOwner $owner, string $src) use (&$warnings): void {
			$warnings[] = $src;
		};
		$url = 'https://cdn.example.com/missing.png';

		$article = $this->createArticle(self::img($url));

		self::assertSame([$url], $warnings);
		self::assertSame([$url], self::sources($article->getContent()));
		self::assertCount(0, $this->owned($article));
	}

	#[Test]
	public function fileOfOursWithoutARowIsCopiedFromTheDataDirectory(): void
	{
		mkdir($this->dataDir . '/legacy');
		file_put_contents($this->dataDir . '/legacy/banner.png', HtmlTest::png('j'));

		$article = $this->createArticle(self::img(self::DATA_URL . '/legacy/banner.png'));

		$src = self::sources($article->getContent())[0];
		self::assertNotSame(self::DATA_URL . '/legacy/banner.png', $src);
		self::assertSame(HtmlTest::png('j'), $this->fileByUrl($src)->getContents());
		self::assertFileExists($this->dataDir . '/legacy/banner.png');
	}

	#[Test]
	public function htmlInsideJsonIsProcessed(): void
	{
		$article = $this->createArticle(null, ['intro' => 'text', 'items' => [['answer' => self::img(self::dataUri(HtmlTest::png('k')))]]]);

		$faq = $article->getFaq();
		self::assertSame('text', $faq['intro']);
		$src = self::sources($faq['items'][0]['answer'])[0];
		self::assertSame(HtmlTest::png('k'), $this->fileByUrl($src)->getContents());
	}

	#[Test]
	public function translationsBelongToTheOwner(): void
	{
		$article = $this->createArticle(null);

		$translation = new Translation();
		$translation->setLocale('en');
		$translation->setObjectClass(TestArticle::class);
		$translation->setField('content');
		$translation->setForeignKey((string) $article->getId());
		$translation->setContent(self::img(self::dataUri(HtmlTest::png('l'))));
		$this->em->persist($translation);
		$this->em->flush();

		$owned = $this->owned($article);
		self::assertCount(1, $owned);
		self::assertSame([$owned[0]->getFile()->getUrl()], self::sources($translation->getContent()));
	}

	#[Test]
	public function deletingTheOwnerDeletesItsFiles(): void
	{
		$article = $this->createArticle(self::img(self::dataUri(HtmlTest::png('m'))));
		$file = $this->owned($article)[0]->getFile();
		$fileId = $file->getId();
		$path = $file->getPath();

		$this->em->remove($article);
		$this->em->flush();

		$this->em->clear();
		self::assertNull($this->em->find(TestFile::class, $fileId));
		self::assertSame([], $this->em->getRepository(TestContentImage::class)->findAll());
		self::assertFileDoesNotExist($path);
	}

	#[Test]
	public function blobAndStaticImagesAreLeftAlone(): void
	{
		$content = self::img('../../images/icon.png') . self::img('blob:https://shop.test/abc') . '<img data-src="data:image/png;base64,AAAA" alt="">';

		$article = $this->createArticle($content);

		self::assertSame($content, $article->getContent());
		self::assertCount(0, $this->owned($article));
	}

	#[Test]
	public function pausedListenerLeavesTheHtmlAlone(): void
	{
		$content = self::img(self::dataUri(HtmlTest::png('n')));

		$this->listener->pause();
		$article = $this->createArticle($content);
		$this->listener->resume();

		self::assertSame($content, $article->getContent());
		self::assertCount(0, $this->owned($article));
	}

	#[Test]
	public function syncWaitsForTheOuterTransaction(): void
	{
		$this->em->beginTransaction();
		$article = $this->createArticle(self::img(self::dataUri(HtmlTest::png('o'))));

		self::assertStringStartsWith('<p><img style="width: 100%;" src="data:', $article->getContent());

		$this->em->commit();

		self::assertStringStartsWith(self::DATA_URL . '/', self::sources($article->getContent())[0]);
	}

	#[Test]
	public function rolledBackSaveDoesNotSync(): void
	{
		$this->em->beginTransaction();
		$article = $this->createArticle(self::img(self::dataUri(HtmlTest::png('p'))));
		$this->em->rollback();

		self::assertSame([], $this->em->getRepository(TestContentImage::class)->findAll());
	}

	#[Test]
	public function listenerNeedsTransactionCallbacks(): void
	{
		$em = EntityManagerFactory::create($this->dataDir, adt: false, dataUrl: self::DATA_URL);
		$em->getEventManager()->addEventSubscriber(new ContentImageListener($em, new ContentImageSynchronizer($em, TestFile::class, TestContentImage::class, self::DATA_URL)));

		$this->expectException(LogicException::class);
		$em->persist(new TestArticle()->setContent('<p>x</p>'));
		$em->flush();
	}

	#[Test]
	public function describeCountsImagesByKind(): void
	{
		$this->listener->pause();
		$article = $this->createArticle(
			self::img(self::dataUri(HtmlTest::png('q')))
			. self::img('blob:https://shop.test/x')
			. self::img(self::DATA_URL . '/1/a.png')
			. self::img('https://cdn.example.com/b.png')
			. self::img('../../images/icon.png'),
		);

		self::assertSame(['data' => 1, 'blob' => 1, 'own' => 1, 'external' => 1], $this->synchronizer->describe($article));
	}

	private static function removeDirectory(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		}
		rmdir($dir);
	}
}
