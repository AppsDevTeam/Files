<?php
declare(strict_types=1);

namespace ADT\Files;

use Exception;

/**
 * Vyhozeno při nastavení souboru s příponou z Helpers::$blockedExtensions.
 * Aplikace ji má odchytit na hranici (např. ve zpracování formuláře) a přeložit
 * na validační chybu, jinak skončí jako neošetřená 500.
 */
class BlockedExtensionException extends Exception
{
}
