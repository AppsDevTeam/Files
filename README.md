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
