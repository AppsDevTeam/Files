Files
=========

Installation
---------

`$ composer require adt/files:~1.0`

To your `bootstrap.php` add:

`\ADT\Files\Helpers::setSalt('mySalt');`

and change `mySalt` by random 6 character long string.

Usage
---------

Use `\ADT\Files\Helpers::resizeName($fileUpload->getName())` in your `File` model or wherever else you need it.

