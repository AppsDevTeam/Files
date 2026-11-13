<?php

declare(strict_types=1);

namespace ADT\Files\Tests\ContentImages;

use ADT\Files\ContentImages\Html;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class HtmlTest extends TestCase
{
	public static function png(string $seed): string
	{
		// the signature is all finfo needs to call it a png
		return "\x89PNG\r\n\x1a\n\0\0\0\rIHDR" . md5($seed, true);
	}

	#[Test]
	public function sourcesAreReadInOrderAndDecoded(): void
	{
		$html = '<p><img src="/a.png" alt=""></p><img data-src="/lazy.png" src=\'/b.png?x=1&amp;y=2\'><img data-mce-src="/c.png">';

		self::assertSame(['/a.png', '/b.png?x=1&y=2'], Html::getSources($html));
		self::assertSame([], Html::getSources('<p>no image</p>'));
		self::assertSame([], Html::getSources(null));
	}

	#[Test]
	public function replaceKeepsTheRestOfTheHtml(): void
	{
		$html = '<p class="x"><img style="width: 100%;" src="/a.png"> text <img src="/b.png"></p>';

		$replaced = Html::replaceSources($html, fn (string $src) => $src === '/a.png' ? '/new.png?a=1&b=2' : null);

		self::assertSame('<p class="x"><img style="width: 100%;" src="/new.png?a=1&amp;b=2"> text <img src="/b.png"></p>', $replaced);
	}

	#[Test]
	public function srcIsFoundWhereverTheAttributesAre(): void
	{
		$cases = [
			"<img alt='x' src='/b.png' />" => ['/b.png'],
			'<IMG SRC = "/c.png">' => ['/c.png'],
			'<img alt src="/d.png">' => ['/d.png'],
			'<img width=26 height="23" src="/e.png">' => ['/e.png'],
			'<img alt="a > b" src="/f.png">' => ['/f.png'],
			'<img src="/g.png" data-src="/h.png">' => ['/g.png'],
			'<img srcset="/s.png 2x" src="/i.png">' => ['/i.png'],
			"<img\nclass=\"x\"\nsrc=\"/l.png\">" => ['/l.png'],
			'<img data-src="/only.png">' => [],
		];

		foreach ($cases as $html => $expected) {
			self::assertSame($expected, Html::getSources($html), $html);
		}
	}

	#[Test]
	public function imageOverThePcreBacktrackLimitIsFoundAndReplaced(): void
	{
		$value = 'data:image/png;base64,' . base64_encode(self::png('big') . str_repeat("\0", 2 * 1024 * 1024));
		self::assertGreaterThan((int) ini_get('pcre.backtrack_limit'), strlen($value));
		$html = '<img data-mce-src="' . $value . '" src="' . $value . '" alt="x"><p>text</p><img src="blob:https://shop.test/1">';

		self::assertSame([$value, 'blob:https://shop.test/1'], Html::getSources($html));
		self::assertSame([2], Html::findInvalidImages($html));
		self::assertSame(
			'<img data-mce-src="' . $value . '" src="/new.png" alt="x"><p>text</p><img src="blob:https://shop.test/1">',
			Html::replaceSources($html, fn (string $src) => str_starts_with($src, 'data:') ? '/new.png' : null),
		);
	}

	#[Test]
	public function invalidImagesAreBlobsAndBrokenOrUnsupportedDataUris(): void
	{
		$html = '<img src="data:image/png;base64,' . base64_encode(self::png('a')) . '">'
			. '<img src="blob:https://shop.test/10633d49">'
			. '<img src="data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>') . '">'
			. '<img src="data:image/png;base64,not-base64">'
			. '<img src="../../images/icon.png">';

		self::assertSame([2, 3, 4], Html::findInvalidImages($html));
	}

	#[Test]
	public function declaredTypeOfDataUriIsNotTrusted(): void
	{
		self::assertSame(self::png('a'), Html::decodeDataUri('data:image/gif;base64,' . base64_encode(self::png('a'))));
		self::assertNull(Html::decodeDataUri('data:image/png;base64,' . base64_encode('<?php echo 1;')));
	}

	#[Test]
	public function mapWalksStringsInsideArrays(): void
	{
		$value = ['q' => 'a', 'items' => [['answer' => 'b'], 3, null]];

		self::assertSame(['q' => 'A', 'items' => [['answer' => 'B'], 3, null]], Html::map($value, strtoupper(...)));
		self::assertSame(['a', 'b'], Html::strings($value));
		self::assertSame(5, Html::map(5, strtoupper(...)));
	}
}
