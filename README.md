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
