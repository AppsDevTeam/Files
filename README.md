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

A file of an *archived* row left behind in the data storage (the delete after archiving failed,
see below) is kept too, the row names it - `--archived` treats it as an orphan, the row says
its file is in the archive. The archive itself is never scanned.

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

The command reads the file from the disk, so it takes directories, not storages - the rows it
is for are from before the mime type column, which is before S3.

Storages
---------

A file lives in a *storage*: a directory on the disk, or a bucket in S3 (Garage, MinIO, AWS).
Wherever `FileListener` takes a directory it takes an `ADT\Files\Storage\Storage` as well, so
the data directory, the private one and the archives can each be either - files on the disk and
an archive in a bucket, everything in buckets, or the directories you have today, which keep
working unchanged (a string is wrapped in `LocalStorage` for you).

```
services:
    filesStorage: ADT\Files\Storage\S3Storage::create(
        endpoint: %s3.endpoint%,      # http://10.0.21.1:3900
        key: %s3.key%,
        secret: %s3.secret%,
        bucket: %s3.bucket%,
        region: garage,
        prefix: files,                # optional directory inside the bucket
        url: 'https://files.example.com/files' # optional, what getUrl() returns; leave out for no URL
    )
    filesArchive: ADT\Files\Storage\S3Storage::create(%archive.endpoint%, %archive.key%, %archive.secret%, %archive.bucket%, protocol: 's3-archive')
    - ADT\Files\Listeners\FileListener(dataDir: @filesStorage, privateDataDir: @filesStorage, archiveDir: @filesArchive)
    - ADT\Files\Console\DeleteOrphanedFilesCommand(@filesStorage)
```

Name the storages - the listener needs more than one of the same class, and autowiring by type
would not know which is which. `$dataUrl` belongs to a directory; with a storage it throws, the
storage carries its URL.

`S3Storage::create()` builds the client with path style addressing, which Garage and MinIO
need; pass an `Aws\S3\S3ClientInterface` of your own to the constructor when you have one.
It needs `aws/aws-sdk-php`, which is only suggested - a project on the disk does not pay for it.

What changes with a bucket:

* `getPath()` is an `s3://bucket/key` URL. PHP file functions (`fopen()`, `file_get_contents()`,
  `copy()`, Nette's `FileResponse`) read it like a path - the SDK registers a stream wrapper -
  but anything outside PHP (`exec` of ImageMagick, ffmpeg...) cannot, copy the file to a temporary
  one first. `getContents()` works everywhere.
* The wrapper is one per protocol and a protocol has one server. Two storages on the *same*
  server (data and archive buckets in one Garage) share it; a storage on *another* server
  gets its own `protocol: 's3-archive'`.
* `getUrl()` is the `url` of the storage plus the file name, so serve the bucket through nginx
  (or the Garage website endpoint) under that URL. Without `url` it throws as it does for a
  missing `$dataUrl`.
* Uploads send `Content-MD5`, so the server rejects a body that got damaged on the way, and the
  size is checked afterwards; an object under its final name is always complete.
* `files:delete-orphans` takes a storage as well and lists the bucket. `files:fill-mime-type` is
  for rows from before the column existed, which predate S3 - it stays on directories.
* Files over `S3Storage::$multipartThreshold` (1 GB) go up in parts - a single PUT is limited to
  5 GB - and are checked by size only, there is no `Content-MD5` of the whole.
* Files are not tiered inside one bucket. One Garage cluster has one layout for all its buckets,
  so "the archive on the cheap node" is a *second* cluster (or any other server) with its own
  `S3Storage` as the archive, see below.
* The entity holds its storage, and an `S3Storage` holds the client - a `File` entity put into
  the session or otherwise serialized will not survive it (it would not have with the callbacks
  `setOnAfterSave()` keeps either). Store the id.
* Saving goes through a temporary file whatever the storage, and archiving a bucket file downloads
  it into one. They live in `sys_get_temp_dir()` unless `Helpers::$temporaryDirectory` says
  otherwise - set it where `/tmp` is small or tmpfs and the files are not.

The tests against a real S3 need one: `eval "$(tests/s3/garage.sh up)"` starts a single node
Garage in Docker and exports what the tests read, without it they are skipped.

### Moving the files you already have

Switching the listener to a bucket does nothing to the files saved before - they sit in the
directory and the rows now point into the bucket, where they are not. `files:migrate-storage`
carries them over. It takes the *former* storages the way the listener takes its own (data,
private, archive, private archive) and, without targets of its own, copies into the storages
of the listener as it is configured now:

```
services:
    - ADT\Files\Console\MigrateStorageCommand(from: %dataDir%/files, privateFrom: %dataPrivateDir%/files, archiveFrom: '/mnt/nfs/share/<project>/files')
```

To copy *before* switching the listener, name the targets as well (`to`, `privateTo`,
`archiveTo`, `privateArchiveTo`, the same four) - the listener is not consulted then, and
`--delete` is refused: the application still reads from the source. Rows in an archive need
`archiveTo` as well, they are reported otherwise rather than copied into `to`, where the
listener with an archive would not look for them:

```
    - ADT\Files\Console\MigrateStorageCommand(from: %dataDir%/files, privateFrom: %dataPrivateDir%/files, to: @filesStorage, privateTo: @filesPrivateStorage)
```

```
$ php bin/console files:migrate-storage                  # counts what it would copy
$ php bin/console files:migrate-storage --exec           # copies, the sources stay
$ php bin/console files:migrate-storage --exec --delete  # copies, then deletes the source of every file that is in the target
```

It goes by rows, not by the directory (a file without a row is an orphan, see above), checks each
copy against the row - the hash from the database is what `put()` verifies the copy with, so a
file that no longer matches its row is reported instead of copied - and can be run again any
time: a file already in the target with the right size is skipped. Problems (a missing file,
a failed copy, a row without a file) are listed with their ids at the end and the run goes on.
Rows pointing into an archive need `archiveFrom`, without it they are reported.

Two ways to switch. Targets in the arguments: copy while the application still writes into
the directory, switch the listener, run once more to pick up what was saved in between. Targets
from the listener: switch, then run - between the switch and the end of the run the old files
are not found. `--delete` is for when the new storage has proven itself; until then the directory
is the way back.

Archiving
---------

Files nobody needs at hand can be moved out of the data storage into an archive - a compressed
NFS share (`/mnt/nfs/share` on our hosts) or a bucket on another server - and keep working
through the same entity: `getPath()` and `getContents()` point to the archive once the file
is there. Any combination works: disk to NFS, disk to bucket, bucket to bucket, bucket to disk.

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

1. checks the size and hash of the file against the row (a bucket file is downloaded first),
2. puts it into the archive - a directory copies into a temporary file, `fsync()`s it, checks
   every step and the hash of the copy and only then renames it to its final name; a bucket
   uploads with `Content-MD5` and checks the size - so under the final name there is never
   anything but the complete file,
3. sets `isArchived` on the row,
4. deletes the original - only after the commit, the same way deleting files works.

**Why all of that.** The archive is a soft NFS mount: when the server is down, calls fail after
a few seconds instead of hanging the process - but a write that made it only into the client cache
is lost, and `fwrite()`/`fclose()` may well report success for it. Only a checked `fsync()` says
the data is on the server. Any failure throws, background-queue retries the job later (1, 2, 4,
… 16 minutes) and the original stays until the archive one is verified. Running the job again is
always safe; a run that died between the database and the delete finishes the delete next time
(and only when the archive copy is there with the right size).

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
  consumers), otherwise their path points to the data storage, where they no longer are.
* Any of the four can be a `Storage` instead of a directory - an archive in a bucket:
  `archiveDir: ADT\Files\Storage\S3Storage::create(..., protocol: 's3-archive')`.
* Reading an archived file fails while the archive is unavailable. Do not read it in a request
  that must not fail.
* Deleting an archived entity deletes the file in the archive; if the archive is unavailable at
  that moment, the file stays there as an orphan.
* Deleting the original after the row was marked archived can fail the same way (the bucket is
  down for a moment). The row is right, the file is in the archive, the original just stays -
  the next run of the job removes it, or `files:delete-orphans --archived` does.

### Upgrading

The `isArchived` column (`is_archived`, not null, default 0) is new - add it with a migration
when updating.

### Upgrading to 7.0

The entity no longer carries a directory and a URL but a `Storage`: `setBaseDirectoryPath()` and
`setBaseDirectoryUrl()` are gone, `setStorage()` took their place, and `FileListener::getArchiveDir()`,
`getArchiveDirFor()` and `getLocalDataDir()` became `getArchiveStorage()`, `getArchiveStorageFor()`
and `getDataStorageFor()`. A project that only configures the listener and uses the trait does
not notice. Saving a file now goes through a temporary file and a verified copy (`fsync()`),
whatever the storage.

A project with its own listener on top of this one:

* a different URL for some files (private, archived) is the URL of their storage -
  `new LocalStorage($privateDir, $privateUrl)` for the private data directory and
  `new LocalStorage($archiveDir, $privateUrl)` for the archive, instead of `setBaseDirectoryUrl()`
  in `setupEntity()`. An archive with a URL serves `getUrl()` of archived files; one without throws
  as before,
* a directory chosen per entity is `getDataStorageFor()` returning a `LocalStorage` for it
  (it is called every time, keep them in a map),
* a naming scheme of its own is `resolveFilename()`, there is no need to copy `saveFile()`.

Content images
---------

Images in the HTML of a WYSIWYG editor (TinyMCE, Summernote) as files that belong to **exactly one
entity** - the one whose HTML shows them. A pasted base64 image stops bloating the database, and
HTML copied from one entity to another gets files of its own, so deleting an image in one never
breaks the other. Everything lives in `ADT\Files\ContentImages`; take only what the project needs:

* `Html` - pure functions over the HTML, no database: the sources of every `<img>`, rewriting them
  without touching the rest of the HTML, decoding a base64 data URI (png, jpeg, gif, webp - **no SVG**,
  served from the data directory it can carry a script) and `findInvalidImages()` for a form validator.
* `ContentImageSynchronizer` - `sync($owner)` turns the images of an owner into its files:
  * a base64 data URI becomes a new file,
  * a file under the data URL that the owner has stays (a relative `../../data/files/...`, which TinyMCE
    writes, or an absolute URL on one of `$ownHosts` is normalised to the data URL), any other one is
    **copied** - a file of another entity, an upload without an owner, a file without a row under `$dataDir`,
  * an external image is downloaded only when a `Downloader` is given (opt-in, 10 MB) and remembered by its
    `sourceUrl`, so an import writing the same URL again does not download it again; without one it stays
    on its URL,
  * a `blob:` URI, a static path and anything else are left alone,
  * files of the owner no longer in any version of its HTML are deleted - the stored row counts too, an owner
    loaded by Gedmo Translatable in another than the default locale holds the translation, not the default value
    (a field with an unsaved change, `sync()` called before a flush, wins over the row).

  It returns the sources it had to leave as they were (a download failed, not an image, a missing file).
  A failure of PCRE (a pathological HTML) throws, it never saves an empty HTML instead of the real one.
* `StreamDownloader` - the `Downloader` the library ships. The URL comes from HTML anybody with the editor
  typed in, so it guards against SSRF: the host is resolved first and a private, loopback, link-local,
  reserved or shared (100.64.0.0/10) address is refused, the request goes to the very address that was checked
  (no second DNS lookup), redirects are followed by hand and every hop is checked again. `allowPrivateNetworks`
  is for a local environment only.
* `ContentImageListener` - runs it without the project calling anything: onFlush notes every owner whose
  HTML changed (or a translation of it, or that still holds a base64 image from before - any save converts it)
  and the synchronizer runs once the outermost transaction commits.
  It cannot run inside the flush - a new file gets its URL and a new owner its id only after they are
  saved, and Doctrine 3 dispatches `postFlush` before it clears the unit of work. It therefore needs the
  `ADT\DoctrineComponents\EntityManager` (`TransactionCallbacksInterface`). A deleted owner takes its files
  along in the same flush, a soft deleted one (Gedmo SoftDeleteable) keeps them until it is deleted for good.
  `pause()` / `resume()` switch it off for bulk operations, `$onWarning[]` gets the sources left as they were.
  The synchronization runs after the save has been committed, so `$onError[]` should get its failures - the
  data are saved, the user must not see a failed save. Without a handler the error is thrown.
* `GedmoTranslations` - translations of the HTML fields kept by Gedmo Translatable.

### Setup

An owner implements `ContentImageOwner` and names its HTML fields - a string column, or a JSON column
with HTML strings inside, which are walked recursively:

```php
class Article implements ContentImageOwner
{
    public function getContentImageFields(): array
    {
        return ['content', 'faq'];
    }
}
```

The project maps `ContentImage` to its own `File` entity. The property keeps the interface type (PHP does not let
a child class change it), the entity class goes to `targetEntity`. Declare the owner index there as well, not every
Doctrine version inherits one from a mapped superclass:

```php
#[ORM\Entity]
#[ORM\Index(name: 'content_image_owner', fields: ['ownerClass', 'ownerId'])]
class ContentImage extends \ADT\Files\ContentImages\ContentImage
{
    use Identifier;

    #[ORM\ManyToOne(targetEntity: App\Model\Entities\File::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected \ADT\Files\Entities\File $file;
}
```

and registers the services next to `FileListener`:

```neon
services:
    - ADT\Files\ContentImages\GedmoTranslations # only with Gedmo Translatable
    - ADT\Files\ContentImages\ContentImageSynchronizer(
        fileClass: App\Model\Entities\File,
        contentImageClass: App\Model\Entities\ContentImage,
        dataUrl: '/data/files',
        dataDir: %dataDir%/files,
        ownHosts: [%baseUrl%],
        downloader: ADT\Files\ContentImages\StreamDownloader() # leave out to keep external images on their URL
    )
    - ADT\Files\ContentImages\ContentImageListener
```

A form refuses what cannot be saved - a `blob:` URI lives only in the browser tab that created it
(TinyMCE shows one in its source code view for every base64 image, so a block copied from there
into another tab is dead):

```php
$form->addTextArea('content')
    ->addRule(fn ($control) => !Html::findInvalidImages($control->getValue()), 'Insert the image again, please.');
```

Content saved before the listener is converted by calling `ContentImageSynchronizer::sync()` on every
owner once (the listener does not run it a second time on the synchronizer's own flush); `describe()` counts
the images by kind without writing anything.

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
