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

Deleting files no row points to
---------

Rows can lose their file, and files can lose their row - an interrupted upload, a restore
from a dump taken before they were added. The second kind never goes away on its own, so
`files:delete-orphans` walks the data directories and reports every file no row references:

```
services:
    - ADT\Files\Console\DeleteOrphanedFilesCommand(%dataDir%, %dataPrivateDir%)
```

**It deletes nothing without `--exec`.** Run it, read the list, and only then pass the flag.
A data directory pointing one level too high turns a cleanup into an outage, and a list is
cheap to throw away.

With `--exec` it prints the same list, each file just before it is deleted - redirect it to a
file and an interrupted run still tells you what is already gone.

> **Does your application write anything else into the data directory?** Thumbnails next to
> the originals, generated previews, anything at all - this command knows about rows and
> nothing else, so all of it is an orphan to it and `--exec` will delete it. Keep generated
> files outside the data directories, or do not run this with `--exec`.

Directories left empty by the sweep are removed as well - names are split into directories
by id, so cleaning up files alone would leave a skeleton of empty ones behind. A directory
that still holds anything stays, and the data directories themselves are never touched.

Files modified within the last day are left alone (`--min-age`, in seconds). The file is
written in `postPersist`, so between that write and the commit of the surrounding transaction
an upload that is about to succeed looks exactly like an orphan.

The command also refuses to run when no row references a file at all - next to a directory
full of files that is a misconfigured data dir far more often than a storage with nothing
left to keep.

### Upgrading from a version with a nullable mime type

The column was nullable until the type was made a plain `string`, so a database written by an
older version has rows with no mime type and hydrating those now fails. **Fill them in before
deploying this version**, with the old one still running:

```
$ php bin/console files:fill-mime-type           # reports what it found
$ php bin/console files:fill-mime-type --exec    # and this writes it
```

Register
`\ADT\Files\Console\FillMimeTypeCommand` with the same data directories as the listener -
it deliberately does not load entities, so it runs on both the old and the new version:

```
services:
    - ADT\Files\Console\FillMimeTypeCommand(%dataDir%, %dataPrivateDir%)
```

It goes through every mapped entity implementing `ADT\Files\Entities\File`, reads the rows with
no usable mime type and detects it from the file on the disk. Rows whose file is missing get
`Helpers::DEFAULT_MIME_TYPE` and are listed at the end, so that a handful of dead rows cannot
block the migration. Once it is done, deploy this version together with a migration making the
column not nullable.

Running it the other way round is survivable. A migration making the column not nullable has
to put something into the rows that are still empty, and the only honest value is the default
one - so the command treats that value as "not known yet" rather than as an answer, and finds
the real types on a later run. It only leaves a row alone when it has nothing better to say
about it than what is already there, which also makes repeated runs free.

* `--exec` writes; without it the run only reports what it found
* `--entity` limits the run to a single entity class
* `--batch-size` is how many rows are read and written at once, 500 by default

Archiving
---------

Files nobody needs at hand can be moved out of the data directory into an archive - typically a
compressed NFS share (`/mnt/nfs/share` on our hosts) - and keep working through the same entity:
`getPath()` and `getContents()` point to the archive once the file is there.

```
services:
    - ADT\Files\Listeners\FileListener(%dataDir%, 'files', %dataPrivateDir%, archiveDir: '/mnt/nfs/share/<project>/files')
    - ADT\Files\Archiver

backgroundQueue:
    callbacks:
        archiveFile: [@ADT\Files\Archiver, processArchive]
```

```php
$archiver->archive($file);   // only publishes a background job
```

`archive()` publishes a job and returns. Inside a transaction the job is sent only after the
commit, so a rollback cancels the archiving as well. The job then

1. copies the file into a temporary file in the archive, `fsync()`s it and checks every step,
2. compares the size and hash of the copy with the original,
3. renames it to its final name and sets `isArchived` on the row,
4. deletes the local copy - only after the commit, the same way deleting files works.

**Why all of that.** The archive is a soft NFS mount: when the server is down, calls fail after
a few seconds instead of hanging the process - but a write that made it only into the client cache
is lost, and `fwrite()`/`fclose()` may well report success for it. Only a checked `fsync()` says
the data is on the server. Any failure throws, background-queue retries the job later (1, 2, 4,
… 16 minutes) and the local copy stays until the archive one is verified. Running the job again is
always safe; a run that died between the database and the delete finishes the delete next time.

Writing to the archive yourself? Do the same: write to a temporary file, `fsync()` it, check the
result, then `rename()`. Anything less can lose data silently when the archive goes away.

* The archive is not under the document root, `getUrl()` of an archived file throws - serve it
  through the application.
* Private files go to `privateArchiveDir` when it is set, otherwise to `archiveDir` as well -
  set both to keep the layout of the data directories in the archive, e.g.
  `archiveDir: '/mnt/nfs/share/<project>/www/data', privateArchiveDir: '/mnt/nfs/share/<project>/data'`.
  The directory is chosen by `isPrivate`, so do not change it on an archived file - its path
  would then point to the other directory.
* `archiveDir` has to be configured in every process that loads archived files (web and
  consumers), otherwise their path points to the data directory, where they no longer are.
* Reading an archived file fails while the archive is unavailable. Do not read it in a request
  that must not fail.
* Deleting an archived entity deletes the file in the archive; if the archive is unavailable at
  that moment, the file stays there as an orphan.

### Upgrading

The `isArchived` column (`is_archived`, not null, default 0) is new - add it with a migration
when updating.

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
