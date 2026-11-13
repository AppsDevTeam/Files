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
