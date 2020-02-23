<?php

declare(strict_types=1);

namespace Flysystem\FTP;

use DateTime;
use Flysystem\Config;
use Flysystem\DirectoryAttributes;
use Flysystem\FileAttributes;
use Flysystem\FilesystemAdapter;
use Flysystem\MimeType;
use Flysystem\PathPrefixer;
use Flysystem\StorageAttributes;
use Flysystem\UnableToCopyFile;
use Flysystem\UnableToCreateDirectory;
use Flysystem\UnableToDeleteDirectory;
use Flysystem\UnableToDeleteFile;
use Flysystem\UnableToMoveFile;
use Flysystem\UnableToReadFile;
use Flysystem\UnableToRetrieveMetadata;
use Flysystem\UnableToSetVisibility;
use Flysystem\UnableToWriteFile;
use Flysystem\UnixVisibility\PortableVisibilityConverter;
use Flysystem\UnixVisibility\VisibilityConverter;
use Generator;
use Throwable;

class FtpAdapter implements FilesystemAdapter
{
    private const SYSTEM_TYPE_WINDOWS = 'windows';
    private const SYSTEM_TYPE_UNIX = 'unix';

    /**
     * @var FtpConnectionOptions
     */
    private $connectionOptions;

    /**
     * @var FtpConnectionProvider
     */
    private $connectionProvider;

    /**
     * @var ConnectivityChecker
     */
    private $connectivityChecker;

    /**
     * @var resource|false
     */
    private $connection = false;

    /**
     * @var PathPrefixer
     */
    private $prefixer;

    /**
     * @var VisibilityConverter
     */
    private $visibilityConverter;

    /**
     * @var bool|null
     */
    private $isPureFtpdServer;

    /**
     * @var null|string
     */
    private $systemType;

    public function __construct(
        FtpConnectionOptions $connectionOptions,
        FtpConnectionProvider $connectionProvider = null,
        ConnectivityChecker $connectivityChecker = null,
        VisibilityConverter $visibilityConverter = null
    ) {
        $this->connectionOptions = $connectionOptions;
        $this->connectionProvider = $connectionProvider ?: new FtpConnectionProvider();
        $this->connectivityChecker = $connectivityChecker ?: new NoopCommandConnectivityChecker();
        $this->visibilityConverter = $visibilityConverter ?: new PortableVisibilityConverter();
        $this->prefixer = new PathPrefixer($connectionOptions->root());
    }

    /**
     * @return resource
     */
    private function connection()
    {
        start:
        if ( ! is_resource($this->connection)) {
            $this->connection = $this->connectionProvider->createConnection($this->connectionOptions);
        }

        if ($this->connectivityChecker->isConnected($this->connection) === false) {
            $this->connection = false;
            goto start;
        }

        ftp_chdir($this->connection, $this->connectionOptions->root());

        return $this->connection;
    }

    private function isPureFtpdServer(): bool
    {
        if ($this->isPureFtpdServer !== null) {
            return $this->isPureFtpdServer;
        }

        $response = ftp_raw($this->connection, 'HELP');

        return $this->isPureFtpdServer = stripos(implode(' ', $response), 'Pure-FTPd') !== false;
    }

    public function fileExists(string $path): bool
    {
        try {
            $this->fileSize($path);

            return true;
        } catch (UnableToRetrieveMetadata $exception) {
            return false;
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $writeStream = fopen('php://temp', 'w+b');
            fwrite($writeStream, $contents);
            rewind($writeStream);
            $this->writeStream($path, $writeStream, $config);
        } finally {
            is_resource($writeStream) && fclose($writeStream);
        }
    }

    public function writeStream(string $path, $resource, Config $config): void
    {
        try {
            $this->ensureParentDirectoryExists($path, $config->get(Config::OPTION_DIRECTORY_VISIBILITY));
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, 'creating parent directory failed', $exception);
        }

        $location = $this->prefixer->prefixPath($path);

        if ( ! ftp_fput($this->connection(), $location, $resource, $this->connectionOptions->transferMode())) {
            throw UnableToWriteFile::atLocation($path, 'writing the file failed');
        }

        if ( ! $visibility = $config->get(Config::OPTION_VISIBILITY)) {
            return;
        }

        try {
            $this->setVisibility($path, $visibility);
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, 'setting visibility failed', $exception);
        }
    }

    public function read(string $path): string
    {
        $readStream = $this->readStream($path);
        $contents = stream_get_contents($readStream);
        fclose($readStream);

        return $contents;
    }

    public function readStream(string $path)
    {
        $location = $this->prefixer->prefixPath($path);
        $stream = fopen('php://temp', 'w+b');
        $result = @ftp_fget($this->connection(), $stream, $location, $this->connectionOptions->transferMode());

        if ( ! $result) {
            fclose($stream);

            throw UnableToReadFile::fromLocation($path);
        }

        rewind($stream);

        return $stream;
    }

    public function delete(string $path): void
    {
        $connection = $this->connection();
        $this->deleteFile($path, $connection);
    }

    /**
     * @param resource $connection
     */
    private function deleteFile(string $path, $connection): void
    {
        $location = $this->prefixer->prefixPath($path);
        $success = @ftp_delete($connection, $location);

        if ($success === false && ftp_size($connection, $location) !== -1) {
            throw UnableToDeleteFile::atLocation($path, 'the file still exists');
        }
    }

    public function deleteDirectory(string $path): void
    {
        /** @var StorageAttributes[] $contents */
        $contents = $this->listContents($path, true);
        $connection = $this->connection();
        $directories = [$path];

        foreach ($contents as $item) {
            if ($item->isDir()) {
                $directories[] = $item->path();
                continue;
            }
            try {
                $this->deleteFile($item->path(), $connection);
            } catch (Throwable $exception) {
                throw UnableToDeleteDirectory::atLocation($path, 'unable to delete child', $exception);
            }
        }

        rsort($directories);

        foreach ($directories as $directory) {
            if ( ! @ftp_rmdir($connection, $this->prefixer->prefixPath($directory))) {
                throw UnableToDeleteDirectory::atLocation($path, "Could not delete directory $directory");
            }
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->ensureDirectoryExists($path, $config->get('visibility'));
    }

    public function setVisibility(string $path, $visibility): void
    {
        $location = $this->prefixer->prefixPath($path);
        $mode = $this->visibilityConverter->forFile($visibility);

        if ( ! @ftp_chmod($this->connection(), $mode, $location)) {
            throw UnableToSetVisibility::atLocation($path);
        }
    }

    private function fetchFileMetadata(string $path, string $type): FileAttributes
    {
        $path = ltrim($path, '/');
        $dirname = dirname($path);
        $attributes = null;

        if ($dirname === '.') {
            $dirname = '';
        }

        /** @var StorageAttributes[] $items */
        $items = $this->listContents($dirname, false);

        foreach ($items as $attributes) {
            if ($attributes->path() === $path) {
                break;
            }
        }

        if ( ! $attributes instanceof FileAttributes) {
            throw UnableToRetrieveMetadata::create(
                $path,
                $type,
                'expected file, ' . ($attributes instanceof DirectoryAttributes ? 'directory found' : 'nothing found')
            );
        }

        return $attributes;
    }

    public function mimeType(string $path): FileAttributes
    {
        try {
            $contents = $this->read($path);
            $mimetype = MimeType::detectMimeType($path, $contents);

            return new FileAttributes($path, null, null, null, $mimetype);
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::mimeType($path, '', $exception);
        }
    }

    public function lastModified(string $path): FileAttributes
    {
        $location = $this->prefixer->prefixPath($path);
        $connection = $this->connection();
        $lastModified = @ftp_mdtm($connection, $location);

        if ($lastModified < 0) {
            throw UnableToRetrieveMetadata::lastModified($path);
        }

        return new FileAttributes($path, null, null, $lastModified);
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->fetchFileMetadata($path, FileAttributes::ATTRIBUTE_VISIBILITY);
    }

    public function fileSize(string $path): FileAttributes
    {
        $location = $this->prefixer->prefixPath($path);
        $connection = $this->connection();
        $fileSize = @ftp_size($connection, $location);

        if ($fileSize < 0) {
            throw UnableToRetrieveMetadata::fileSize($path);
        }

        return new FileAttributes($path, $fileSize);
    }

    public function listContents(string $path, bool $deep): Generator
    {
        $path = ltrim($path, '/');
        $path = $path === '' ? $path : trim($path, '/') . '/';

        if ($deep && $this->connectionOptions->recurseManually()) {
            yield from $this->listDirectoryContentsRecursive($path);
        } else {
            $location = $this->prefixer->prefixPath($path);
            $options = $deep ? '-alnR' : '-aln';
            $listing = $this->ftpRawlist($options, $location);
            yield from $this->normalizeListing($listing, $path);
        }
    }

    private function normalizeListing(array $listing, string $prefix = ''): Generator
    {
        $base = $prefix;

        foreach ($listing as $item) {
            if ($item === '' || preg_match('#.* \.(\.)?$|^total#', $item)) {
                continue;
            }

            if (preg_match('#^.*:$#', $item)) {
                $base = preg_replace('~^\./*|:$~', '', $item);
                continue;
            }

            yield $this->normalizeObject($item, $base);
        }
    }

    private function normalizeObject(string $item, string $base): StorageAttributes
    {
        $systemType = $this->systemType ?: $this->detectSystemType($item);

        if ($systemType === self::SYSTEM_TYPE_UNIX) {
            return $this->normalizeUnixObject($item, $base);
        }

        return $this->normalizeWindowsObject($item, $base);
    }

    private function detectSystemType(string $item): string
    {
        return preg_match(
            '/^[0-9]{2,4}-[0-9]{2}-[0-9]{2}/',
            $item
        ) ? self::SYSTEM_TYPE_WINDOWS : self::SYSTEM_TYPE_UNIX;
    }

    private function normalizeWindowsObject(string $item, string $base): StorageAttributes
    {
        $item = preg_replace('#\s+#', ' ', trim($item), 3);
        $parts = explode(' ', $item, 4);

        if (count($parts) !== 4) {
            throw new InvalidListResponseReceived("Metadata can't be parsed from item '$item' , not enough parts.");
        }

        [$date, $time, $size, $name] = $parts;
        $path = $base === '' ? $name : rtrim($base, '/') . '/' . $name;

        if ($size === '<DIR>') {
            return new DirectoryAttributes($path);
        }

        // Check for the correct date/time format
        $format = strlen($date) === 8 ? 'm-d-yH:iA' : 'Y-m-dH:i';
        $dt = DateTime::createFromFormat($format, $date . $time);
        $lastModified = $dt ? $dt->getTimestamp() : (int) strtotime("$date $time");
        $size = (int) $size;

        return new FileAttributes($path, (int) $size, null, $lastModified);
    }

    private function normalizeUnixObject(string $item, string $base): StorageAttributes
    {
        $item = preg_replace('#\s+#', ' ', trim($item), 7);
        $parts = explode(' ', $item, 9);

        if (count($parts) !== 9) {
            throw new InvalidListResponseReceived("Metadata can't be parsed from item '$item' , not enough parts.");
        }

        [$permissions, /* $number */, /* $owner */, /* $group */, $size, $month, $day, $timeOrYear, $name] = $parts;
        $isDirectory = $this->listingItemIsDirectory($permissions);
        $permissions = $this->normalizePermissions($permissions);
        $path = $base === '' ? $name : rtrim($base, '/') . '/' . $name;

        if ($isDirectory) {
            return new DirectoryAttributes($path, $this->visibilityConverter->inverseForDirectory($permissions));
        }

        $visibility = $this->visibilityConverter->inverseForFile($permissions);
        $size = (int) $size;
        $lastModified = null;

        if ($this->connectionOptions->timestampsOnUnixListingsEnabled()) {
            $lastModified = $this->normalizeUnixTimestamp($month, $day, $timeOrYear);
        }

        return new FileAttributes($path, (int) $size, $visibility, $lastModified);
    }

    private function listingItemIsDirectory(string $permissions): bool
    {
        return substr($permissions, 0, 1) === 'd';
    }

    private function normalizeUnixTimestamp(string $month, string $day, string $timeOrYear): int
    {
        if (is_numeric($timeOrYear)) {
            $year = $timeOrYear;
            $hour = '00';
            $minute = '00';
            $seconds = '00';
        } else {
            $year = date('Y');
            [$hour, $minute] = explode(':', $timeOrYear);
            $seconds = '00';
        }

        $dateTime = DateTime::createFromFormat('Y-M-j-G:i:s', "{$year}-{$month}-{$day}-{$hour}:{$minute}:{$seconds}");

        return $dateTime->getTimestamp();
    }

    private function normalizePermissions(string $permissions): int
    {
        // remove the type identifier
        $permissions = substr($permissions, 1);

        // map the string rights to the numeric counterparts
        $map = ['-' => '0', 'r' => '4', 'w' => '2', 'x' => '1'];
        $permissions = strtr($permissions, $map);

        // split up the permission groups
        $parts = str_split($permissions, 3);

        // convert the groups
        $mapper = function ($part) {
            return array_sum(str_split($part));
        };

        // converts to decimal number
        return octdec(implode('', array_map($mapper, $parts)));
    }

    /**
     * @inheritdoc
     *
     * @param string $directory
     */
    private function listDirectoryContentsRecursive(string $directory): Generator
    {
        $location = $this->prefixer->prefixPath($directory);
        $listing = $this->ftpRawlist('-aln', $location);
        /** @var StorageAttributes[] $listing */
        $listing = $this->normalizeListing($listing, $directory);

        foreach ($listing as $item) {
            yield $item;

            if ( ! $item->isDir()) {
                continue;
            }

            $children = $this->listDirectoryContentsRecursive($item->path());

            foreach ($children as $child) {
                yield $child;
            }
        }
    }

    private function ftpRawlist(string $options, string $path): array
    {
        $path = rtrim($path, '/') . '/';
        $connection = $this->connection();

        if ($this->isPureFtpdServer()) {
            $path = str_replace(' ', '\ ', $path);
        }

        return ftp_rawlist($connection, $options . ' ' . $path, stripos($options, 'R') !== false) ?: [];
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->ensureParentDirectoryExists($destination, $config->get(Config::OPTION_DIRECTORY_VISIBILITY));
        } catch (Throwable $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }

        $sourceLocation = $this->prefixer->prefixPath($source);
        $destinationLocation = $this->prefixer->prefixPath($destination);
        $connection = $this->connection();

        if ( ! @ftp_rename($connection, $sourceLocation, $destinationLocation)) {
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $readStream = $this->readStream($source);
            $visibility = $this->visibility($source)->visibility();
            $this->writeStream($destination, $readStream, new Config(compact('visibility')));
        } catch (Throwable $exception) {
            if (isset($readStream) && is_resource($readStream)) {
                @fclose($readStream);
            }
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }
    }

    private function ensureParentDirectoryExists(string $path, ?string $visibility): void
    {
        $dirname = dirname($path);

        if ($dirname === '' || $dirname === '.') {
            return;
        }

        $this->ensureDirectoryExists($dirname, $visibility);
    }

    /**
     * @param string $dirname
     */
    private function ensureDirectoryExists(string $dirname, ?string $visibility): void
    {
        $connection = $this->connection();

        $dirPath = '';
        $parts = explode('/', rtrim($dirname, '/'));
        $mode = $visibility ? $this->visibilityConverter->forDirectory($visibility) : false;

        foreach ($parts as $part) {
            $dirPath .= '/' . $part;
            $location = $this->prefixer->prefixPath($dirPath);

            if (@ftp_chdir($connection, $location)) {
                continue;
            }

            error_clear_last();
            $result = @ftp_mkdir($connection, $location);

            if ($result === false) {
                $errorMessage = error_get_last()['message'] ?? 'unable to create the directory';
                throw UnableToCreateDirectory::atLocation($dirPath, $errorMessage);
            }

            if ($mode !== false && @ftp_chmod($connection, $mode, $location) === false) {
                throw UnableToCreateDirectory::atLocation($dirPath, 'unable to chmod the directory');
            }
        }
    }
}
