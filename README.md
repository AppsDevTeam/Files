Files
=========

Installation
---------

`$ composer require adt/files`

* Create instance of `\ADT\Files\Listeners\FileListener` - parameters:
    * `$dataDir` is path to directory where files will be saved
    * `$dataUrl` is URL leading to same directory
    * implementation of `Doctrine\ORM\EntityMangerInterface`
* Register `\ADT\Files\Listeners\FileListener` into `Doctrine\Common\EventManger`. 
    If you are using kdyby ORM extension, you can do that by added tag `kdyby.subscriber` like this:
    ```
    services:
        -
            factory: ADT\Files\Listeners\FileListener(%dataFolder%/files, 'files')
            tags: [kdyby.subscriber]
    ```   
* Create your File entity for example:
    ```php
        use ADT\Files\Entities\IFileEntity;
        use ADT\Files\Entities\TFileEntity;
        use Doctrine\ORM\Mapping as ORM;
        
        /**
         * @ORM\Entity()
         */
        class File implements IFileEntity
        {
        
            use TFileEntity;
        
        }
    ``` 
    Feel free to add any aditional columns you need and dont forget about id/PK/identifier.

Usage
---------

```php
// create instance of entity
$file = new File();

// set binary data to entity as variable 
$file->setTemporaryContent($binaryContentInString, $originalFileName);

// or set path to temporary file, for example after receiving submitted form with file input 
$file->setTemporaryFile($pathToTemporaryFile, $originalFileName);

$entityManager->persist($file);
$entityManager->flush();
```

Mime type
---------

`getMimeType()` always returns a string. The type is detected from the contents of the file
when it is saved, not from its name, so `photo.png` holding a text file reports `text/plain`.
`mime_content_type()` only fails when the file cannot be read at all - unrecognized contents
come back as `application/octet-stream` on their own - and that one case falls back to
`Helpers::DEFAULT_MIME_TYPE`, which is the same thing.

### Naming a file you only have the bytes of

`setTemporaryContent()` and friends take the name from the caller, which is fine for an
upload but not for a blob coming out of an API or a generator - a hardcoded name ends up
lying about what is inside. `Helpers::getNameByContents()` takes the name you want and
gives it the extension the contents call for, replacing a wrong one if it is already there:

```php
// 'shift_file.png' for a png, regardless of what the caller guessed
$file->setTemporaryContent($contents, ADT\Files\Helpers::getNameByContents($contents, 'shift_file'));
```

The mime type to extension table is `symfony/mime`'s - PHP has none of its own, and keeping
one per project is what this avoids. A type it does not know becomes
`Helpers::DEFAULT_EXTENSION`; the real type is in `mimeType` anyway. Override a single type
through `Helpers::$extensions`, which is consulted first and empty by default:

```php
ADT\Files\Helpers::$extensions['text/plain'] = 'log';
```

An extension that `$blockedExtensions` rejects is never used, whichever of the two it came
from. That matters: `symfony/mime` maps executable types as readily as any other
(`application/x-httpd-php` gives `php`), so without that check it would be enough to submit
content detected as php to get a `.php` file written to disk.

### Upgrading from a version with a nullable mime type

The column was nullable until the type was made a plain `string`, so a database written by an
older version has rows with no mime type and hydrating those now fails. **Fill them in before
deploying this version**, with the old one still running:

```
$ php bin/console files:fill-mime-type
```

Register `\ADT\Files\Console\FillMimeTypeCommand` with the same data directories as the
listener - it deliberately does not load entities, so it runs on both the old and the new
version:

```
services:
    - ADT\Files\Console\FillMimeTypeCommand(%dataDir%, %dataPrivateDir%)
```

It goes through every mapped entity implementing `ADT\Files\Entities\File`, reads the rows with
no mime type and detects it from the file on the disk. Rows whose file is missing get
`application/octet-stream` and are listed at the end, so that a handful of dead rows cannot
block the migration. Once it is done, deploy this version together with a migration making the
column not nullable.

* `--dry-run` reports what would be filled in without writing anything
* `--entity` limits the run to a single entity class
* `--batch-size` is how many rows are read and written at once, 500 by default

Security
---------

Files keep the extension from the client-supplied filename, so a file could be executed as code
if it ends up under the document root. Extensions listed in `Helpers::$blockedExtensions`
(PHP ones by default) are therefore rejected — `setTemporaryFile()`, `setTemporaryContent()`
and `setStream()` throw `ADT\Files\BlockedExtensionException`. The comparison is
case-insensitive and the last extension decides, so `photo.jpg.php` is rejected too.

Catch it where you accept the file and turn it into a validation error, otherwise it ends up
as an unhandled error:

```php
try {
    $file->setTemporaryFile($fileUpload->getTemporaryFile(), $fileUpload->getUntrustedName());
} catch (ADT\Files\BlockedExtensionException $e) {
    $form->addError('This file type is not allowed.');
    return;
}
```

Add your own (for example if you serve files from a server that also executes other languages):

```php
ADT\Files\Helpers::$blockedExtensions[] = 'svg';
```
