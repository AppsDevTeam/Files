<?php

declare(strict_types=1);

namespace ADT\Files\Storage;

use Exception;

/**
 * Misto, kde lezi soubory - datovy adresar na disku, S3 bucket. Soubor je v nem
 * identifikovany svym jmenem (sloupec filename, relativni cesta s lomitky, kterou generuje
 * Helpers::getName()). FileListener uklada a maze, Archiver presouva mezi dvema ulozisti,
 * entita pres nej cte.
 *
 * put() je jedina operace, ktera zapisuje, a musi byt overena: kdyz vrati, je soubor v
 * ulozisti cely a shodny se zdrojem, jinak vyhodi vyjimku a pod finalnim jmenem nic
 * nedopsaneho nenecha. Na tom stoji Archiver - lokalni kopii smaze, jakmile put() projde.
 */
interface Storage
{
	/**
	 * Ulozi lokalni soubor $localPath pod jmenem $name. Zdroj zustava, presun si resi volajici.
	 * $contentType si uloziste muze zapamatovat (S3 ho vraci v Content-Type), nemusi.
	 * @throws Exception kdyz se soubor nepodarilo ulozit cely a overeny
	 */
	public function put(string $name, string $localPath, ?string $contentType = null): void;

	/**
	 * Otevre soubor ke cteni.
	 * @return resource
	 * @throws Exception kdyz soubor neexistuje nebo nejde otevrit
	 */
	public function read(string $name);

	/**
	 * Velikost souboru v bajtech, null kdyz neexistuje.
	 */
	public function getSize(string $name): ?int;

	/**
	 * Smaze soubor; neexistujici soubor neni chyba.
	 */
	public function delete(string $name): void;

	/**
	 * Cesta, pres kterou soubor ctou funkce PHP (fopen, file_get_contents, copy...) - na disku
	 * obycejna cesta, v S3 URL stream wrapperu. Vraci ji i pro soubor, ktery neexistuje.
	 */
	public function getPath(string $name): string;

	/**
	 * Verejna URL souboru, null kdyz uloziste zadnou nema (archiv, privatni soubory).
	 */
	public function getUrl(string $name): ?string;

	/**
	 * Vsechny soubory v ulozisti: jmeno => [velikost v bajtech, cas posledni zmeny (unix)].
	 * Pro files:delete-orphans.
	 * @return iterable<string, array{int, int}>
	 */
	public function list(): iterable;
}
