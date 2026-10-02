<?php
declare(strict_types=1);

namespace ADT\Files;

use finfo;
use Nette\Utils\Random;
use Nette\Utils\Strings;
use Symfony\Component\Mime\MimeTypes;

class Helpers
{
	/*
	 * Mime type použitý tam, kde ho nelze z obsahu souboru zjistit. Sloupec je not nullable,
	 * takže konzument nikdy nedostane null - "neznámá binárka" je poctivější odpověď než
	 * prázdná hodnota, se kterou stejně nikdo nic neudělá.
	 */
	const string DEFAULT_MIME_TYPE = 'application/octet-stream';

	/* Maximální délka sloupce originalName */
	const int ORIGINAL_NAME_LEN = 255;

	/* Maximální délka sloupce name */
	const int NAME_LEN = 255;

	/* Délka hashe, který se přidá k názvu souboru, aby nebyl název souboru uhodnutelný */
	const int HASH_LEN = 5;

	/*
	 * Po kolika znacích bude IDčko rozděleno na stromovou strukturu složek.
	 * Maximální počet záznamů (souborů + složek) M v jedné složce je dán vztahem
	 * M = 2 * 10^(ID_SPLIT_LEN)
	 */
	const int ID_SPLIT_LEN = 3;

	/**
	 * Přípony, které nelze uložit - soubor by šel spustit jako kód, kdyby skončil pod
	 * document rootem a webserver by ho předal interpretru. Porovnává se case-insensitive.
	 * Přenastavitelné aplikací.
	 * @var string[]
	 */
	public static array $blockedExtensions = [
		'php', 'php3', 'php4', 'php5', 'php7', 'php8',
		'phtml', 'pht', 'phps', 'phar', 'phpt',
	];

	/* Přípona pro obsah, jehož typ neumíme pojmenovat */
	const string DEFAULT_EXTENSION = 'bin';

	/**
	 * Mime type -> přípona NAD RÁMEC toho, co zná symfony/mime, pripadne misto toho.
	 * Prazdne pole je spravna vychozi hodnota - tabulku udrzuje symfony/mime, tohle je
	 * jen ustupova cesta pro aplikaci, ktera u konkretniho typu chce neco jineho.
	 * @var array<string, string>
	 */
	public static array $extensions = [];

	/**
	 * Má soubor zakázanou příponu? Rozhoduje poslední přípona, takže "x.jpg.php" je zakázané.
	 */
	public static function isBlockedExtension(string $originalName): bool
	{
		$extension = pathinfo($originalName, PATHINFO_EXTENSION);

		return $extension !== '' && in_array(mb_strtolower($extension), static::$blockedExtensions, true);
	}

	/**
	 * Přípona odpovídající obsahu souboru. Tabulku mime type -> přípona drží symfony/mime
	 * (PHP žádnou v core nemá a udržovat si vlastní v každém projektu je přesně to, čemu
	 * se tu vyhýbáme), $extensions ji jen případně přebije. Pro typ, který nezná ani jedna,
	 * vrací DEFAULT_EXTENSION - skutečný typ stejně nese sloupec mimeType, takže je
	 * poctivější přiznat "neznámá binárka" než hádat.
	 */
	public static function detectExtension(string $contents): string
	{
		$mimeType = new finfo(FILEINFO_MIME_TYPE)->buffer($contents);

		if ($mimeType === false) {
			return static::DEFAULT_EXTENSION;
		}

		// getExtensions() vraci pripony serazene podle preference, prvni je ta kanonicka
		$extension = static::$extensions[$mimeType] ?? MimeTypes::getDefault()->getExtensions($mimeType)[0] ?? null;

		// symfony/mime mapuje i spustitelne typy (application/x-httpd-php -> php), takze
		// se vysledek musi proverit proti $blockedExtensions - jinak by stacilo podstrcit
		// obsah, ktery se tak detekuje, a soubor by skoncil na disku s priponou .php
		if ($extension === null || static::isBlockedExtension('x.' . $extension)) {
			return static::DEFAULT_EXTENSION;
		}

		return $extension;
	}

	/**
	 * Název souboru s příponou odpovídající obsahu. Případnou příponu v $name nahradí,
	 * takže se dá volat i s názvem, který ji už má - a má ji špatně.
	 */
	public static function getNameByContents(string $contents, string $name): string
	{
		return pathinfo($name, PATHINFO_FILENAME) . '.' . static::detectExtension($contents);
	}

	/**
	 * Zadané jméno souboru zkrátí na maximální délku tak, že pokud je $name
	 * kratší, vrátí ho nezměněné. Pokud je delší, nejprve zkrátí extension na
	 * maximálně $maxExtLen znaků a zkracuje řetězec $name od konce před
	 * extension. Název souboru $name nemusí mít příponu. Předpokládá, že $name
	 * neobsahuje žádnou cestu, pouze název souboru.
	 */
	public static function resizeName($name, int $maxLen = self::ORIGINAL_NAME_LEN, int $maxExtLen = 10): string
	{
		if (mb_strlen($name) < $maxLen) {
			return $name;
		}

		$pathinfo = pathinfo($name);

		if (!isset($pathinfo['extension'])) {
			return mb_substr($name, 0, $maxLen);
		}

		// omezení extension
		$pathinfo['extension'] = mb_substr($pathinfo['extension'], 0, $maxExtLen);

		return mb_substr(
				$pathinfo['filename'],
				0,
				$maxLen - mb_strlen($pathinfo['extension']) - 1
			) . '.' . $pathinfo['extension'];
	}

	/**
	 * Pro daný ActiveRow (id, originalName) vrátí název a cestu k souboru.
	 * Adresářová struktura je vytvářena tak, aby nebylo v jednom adresáři přiliš
	 * mnoho záznamů (souborů a složek). Pokud je místo $id zadán callback, je
	 * použit způsob generování id náhodně. Tento callback pak ověřuje, zda je
	 * náhodně vygenerované id již použito nebo ne.
	 * @param string $originalName
	 * @param callable|integer $id Id, které se má použít a nebo callback
	 *        ověřující, zda je náhodně vygenerované id již použité:
	 *        function(array $id) {}. Id je pole - číslo rozdělené po ID_SPLIT_LEN
	 *        dekadických číslicích. Vrací boolean.
	 * @return string
	 */
	public static function getName(string $originalName, callable|int $id): string
	{
		if (is_scalar($id)) {
			$id = str_split((string)$id, static::ID_SPLIT_LEN);

		} else {
			if (is_callable($id)) {
				$isIdUsedCallback = $id;
				unset($id);

				$length = 0;
				do {
					$length++;
					$id = [];
					for ($i = 0; $i < $length; $i++) {
						$id[] = rand(0, pow(10, static::ID_SPLIT_LEN) - 1);
					}
				} while ($isIdUsedCallback($id));
			}
		}

		$pathinfo = pathinfo($originalName);
		$originalName = Strings::webalize($pathinfo['filename']);
		if (isset($pathinfo['extension'])) {
			$originalName .= '.' . $pathinfo['extension'];
		}

		$idPart = implode(DIRECTORY_SEPARATOR, $id);
		$namePart = static::resizeName(
			$originalName,
			static::NAME_LEN - strlen($idPart) - static::HASH_LEN - 2
		);
		$hashPart = Random::generate(self::HASH_LEN);

		return $idPart . '_' . $hashPart . '_' . $namePart;
	}
}
