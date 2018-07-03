<?php

namespace ADT\Files;

class Helpers
{
	use \Nette\SmartObject;
	
	/* Maximální délka sloupce originalName */
	const ORIGINAL_NAME_LEN = 255;

	/* Maximální délka sloupce name */
	const NAME_LEN = 255;

	/* Délka hashe, který se přidá k názvu souboru, aby nebyl název souboru uhodnutelný */
	const HASH_LEN = 5;

	/*
	 * Po kolika znacích bude IDčko rozděleno na stromovou strukturu složek.
	 * Maximální počet záznamů (souborů + složek) M v jedné složce je dán vztahem
	 * M = 2 * 10^(ID_SPLIT_LEN)
	 */
	const ID_SPLIT_LEN = 3;

	protected static $salt = NULL;
	public static function setSalt($salt) {
		static::$salt = $salt;
	}

	/**
	 * Zadané jméno souboru zkrátí na maximální délku tak, že pokud je $name
	 * kratší, vrátí ho nezměněné. Pokud je delší, nejprve zkrátí extension na
	 * maximálně $maxExtLen znaků a zkracuje řetězec $name od konce před
	 * extension. Název souboru $name nemusí mít příponu. Předpokládá, že $name
	 * neobsahuje žádnou cestu, pouze název souboru.
	 * @param string $name
	 * @param integer $maxLen
	 * @param integer $maxExtLen
	 * @return string
	 */
	public static function resizeName($name, $maxLen = self::ORIGINAL_NAME_LEN, $maxExtLen = 10) {
		if (mb_strlen($name) < $maxLen) {
			return $name;
		}

		$pathinfo = pathinfo($name);

		if (! isset($pathinfo['extension'])) {
			return mb_substr($name, 0, $maxLen);
		}

		// omezení extension
		$pathinfo['extension'] = mb_substr($pathinfo['extension'], 0, $maxExtLen);

		return mb_substr($pathinfo['filename'], 0, $maxLen - mb_strlen($pathinfo['extension']) - 1) .'.'. $pathinfo['extension'];
	}

	/**
	 * Pro daný ActiveRow (id, originalName) vrátí název a cestu k souboru.
	 * Adresářová struktura je vytvářena tak, aby nebylo v jednom adresáři přiliš
	 * mnoho záznamů (souborů a složek). Pokud je místo $id zadán callback, je
	 * použit způsob generování id náhodně. Tento callback pak ověřuje, zda je
	 * náhodně vygenerované id již použito nebo ne.
	 * @param string $originalName
	 * @param integer|callable $id Id, které se má použít a nebo callback
	 *		ověřující, zda je náhodně vygenerované id již použité:
	 *		function(array $id) {}. Id je pole - číslo rozdělené po ID_SPLIT_LEN
	 *		dekadických číslicích. Vrací boolean.
	 * @return string
	 */
	public static function getName($originalName, $id) {

		if (static::$salt === NULL) {
			throw new \Nette\InvalidStateException('Add \ADT\Files\Helpers::setSalt to your bootstrap!');
		}

		if (is_scalar($id)) {
			$id = str_split((string)$id, static::ID_SPLIT_LEN);

		} else if (is_callable($id)) {
			$isIdUsedCallback = $id;
			unset($id);

			$length = 0;
			do {
				$length++;
				$id = [];
				for ($i = 0; $i < $length; $i++) {
					$id[] = rand(0, pow(10, static::ID_SPLIT_LEN) - 1);
				}
			} while($isIdUsedCallback($id));
		}

		$pathinfo = pathinfo($originalName);
		$originalName = \Nette\Utils\Strings::webalize($pathinfo['filename']);
		if (isset($pathinfo['extension'])) {
			$originalName .= '.'. $pathinfo['extension'];
		}

		// TODO: přidat volitelný callback na zjištění IS_PRODUCTION, aby si každý mohl nastavit dle potřeby.
		$localhostPart = (!isset($_SERVER['SERVER_ADDR']) || $_SERVER['SERVER_ADDR'] === '127.0.0.1' ? 'loc/' : '');
		$idPart = implode(DIRECTORY_SEPARATOR, $id);
		$namePart = static::resizeName($originalName, static::NAME_LEN - strlen($localhostPart) - strlen($idPart) - static::HASH_LEN - 2);
		$hashPart = substr(md5($idPart . $namePart . static::$salt), 0, static::HASH_LEN);

		return $localhostPart . $idPart .'_'. $hashPart .'_'. $namePart;
	}

}
