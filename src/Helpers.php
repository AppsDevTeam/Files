<?php

namespace ADT\Files;

class Helpers extends \Nette\Object
{
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
		if (strlen($name) < $maxLen) {
			return $name;
		}

		$pathinfo = pathinfo($name);

		if (! isset($pathinfo['extension'])) {
			return substr($name, 0, $maxLen);
		}

		// omezení extension
		$pathinfo['extension'] = substr($pathinfo['extension'], 0, $maxExtLen);

		return substr($pathinfo['filename'], 0, $maxLen - strlen($pathinfo['extension']) - 1) .'.'. $pathinfo['extension'];
	}

	/**
	 * Pro daný ActiveRow (id, originalName) vrátí název a cestu k souboru.
	 * Adresářová struktura je vytvářena tak, aby nebylo v jednom adresáři přiliš
	 * mnoho záznamů (souborů a složek).
	 * @param \Nette\Database\Table\ActiveRow $row
	 * @return string
	 */
	public static function getName(\Nette\Database\Table\ActiveRow $row) {

		if (static::$salt === NULL) {
			throw new \Nette\InvalidStateException('Add \ADT\Files\Helpers::setSalt to your bootstrap!');
		}

		$id = (string)$row->id;
		$idPart = implode(DIRECTORY_SEPARATOR, str_split($id, static::ID_SPLIT_LEN));
		$namePart = static::resizeName($row->originalName, static::NAME_LEN - strlen($idPart) - static::HASH_LEN - 2);
		$hashPart = substr(md5($id . $namePart . static::$salt), 0, static::HASH_LEN);

		return $idPart .'_'. $hashPart .'_'. $namePart;
	}

}
