<?php
declare(strict_types=1);

namespace ADT\Files;

use Nette\Utils\Random;
use Nette\Utils\Strings;

class Helpers
{
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
