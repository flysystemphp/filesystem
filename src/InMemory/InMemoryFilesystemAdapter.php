<?php

declare(strict_types=1);

namespace Flysystem\InMemory;

use Flysystem\Config;
use Flysystem\DirectoryAttributes;
use Flysystem\FileAttributes;
use Flysystem\FilesystemAdapter;
use Flysystem\UnableToCopyFile;
use Flysystem\UnableToMoveFile;
use Flysystem\UnableToReadFile;
use Flysystem\UnableToRetrieveMetadata;
use Flysystem\UnableToSetVisibility;
use Generator;

class InMemoryFilesystemAdapter implements FilesystemAdapter
{
    const DUMMY_FILE_FOR_FORCED_LISTING_IN_FLYSYSTEM_TEST = '______DUMMY_FILE_FOR_FORCED_LISTING_IN_FLYSYSTEM_TEST';

    /**
     * @var InMemoryFile[]
     */
    private $files = [];

    public function fileExists(string $path): bool
    {
        return array_key_exists($this->preparePath($path), $this->files);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $path = $this->preparePath($path);
        $file = $this->files[$path] = $this->files[$path] ?? new InMemoryFile();
        $file->updateContents($contents);

        if ($visibility = $config->get(Config::OPTION_VISIBILITY)) {
            $file->setVisibility($visibility);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->write($path, (string) stream_get_contents($contents), $config);
    }

    public function read(string $path): string
    {
        $path = $this->preparePath($path);

        if (array_key_exists($path, $this->files) === false) {
            throw UnableToReadFile::fromLocation($path, 'file does not exist');
        }

        return $this->files[$path]->read();
    }

    public function readStream(string $path)
    {
        $path = $this->preparePath($path);

        if (array_key_exists($path, $this->files) === false) {
            throw UnableToReadFile::fromLocation($path, 'file does not exist');
        }

        return $this->files[$path]->readStream();
    }

    public function delete(string $path): void
    {
        unset($this->files[$this->preparePath($path)]);
    }

    public function deleteDirectory(string $prefix): void
    {
        $prefix = $this->preparePath($prefix);
        $prefix = rtrim($prefix, '/') . '/';

        foreach (array_keys($this->files) as $path) {
            if (strpos($path, $prefix) === 0) {
                unset($this->files[$path]);
            }
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $filePath = rtrim($path, '/') . '/' . self::DUMMY_FILE_FOR_FORCED_LISTING_IN_FLYSYSTEM_TEST;
        $this->write($filePath, '', $config);
    }

    public function setVisibility(string $path, $visibility): void
    {
        $path = $this->preparePath($path);

        if (array_key_exists($path, $this->files) === false) {
            throw UnableToSetVisibility::atLocation($path, 'file does not exist');
        }

        $this->files[$path]->setVisibility($visibility);
    }

    public function visibility(string $path): FileAttributes
    {
        $path = $this->preparePath($path);

        if (array_key_exists($path, $this->files) === false) {
            throw UnableToRetrieveMetadata::visibility($path, 'file does not exist');
        }

        return new FileAttributes($path, null, $this->files[$path]->visibility());
    }

    public function mimeType(string $path): FileAttributes
    {
        $path = $this->preparePath($path);

        if (array_key_exists($path, $this->files) === false) {
            throw UnableToRetrieveMetadata::mimeType($path, 'file does not exist');
        }

        return new FileAttributes($path, null, null, null, $this->files[$path]->mimeType());
    }

    public function lastModified(string $path): FileAttributes
    {
        $path = $this->preparePath($path);

        if (array_key_exists($path, $this->files) === false) {
            throw UnableToRetrieveMetadata::lastModified($path, 'file does not exist');
        }

        return new FileAttributes($path, null, null, $this->files[$path]->lastModified());
    }

    public function fileSize(string $path): FileAttributes
    {
        $path = $this->preparePath($path);
        if (array_key_exists($path, $this->files) === false) {
            throw UnableToRetrieveMetadata::fileSize($path, 'file does not exist');
        }

        return new FileAttributes($path, $this->files[$path]->fileSize());
    }

    public function listContents(string $prefix, bool $deep): Generator
    {
        $prefix = rtrim($this->preparePath($prefix), '/') . '/';
        $prefixLength = strlen($prefix);
        $listedDirectories = [];

        foreach (array_keys($this->files) as $path) {
            if (substr($path, 0, $prefixLength) === $prefix) {
                $subPath = substr($path, $prefixLength);
                $dirname = dirname($subPath);

                if ($dirname !== '.') {
                    $parts = explode('/', $dirname);
                    $dirPath = '';

                    foreach ($parts as $index => $part) {
                        if ($deep === false && $index >= 1) {
                            break;
                        }

                        $dirPath .= $part . '/';

                        if ( ! in_array($dirPath, $listedDirectories)) {
                            $listedDirectories[] = $dirPath;
                            yield new DirectoryAttributes(trim($prefix . $dirPath, '/'));
                        }
                    }
                }

                $dummyFilename = self::DUMMY_FILE_FOR_FORCED_LISTING_IN_FLYSYSTEM_TEST;
                if (substr($path, -strlen($dummyFilename)) === $dummyFilename) {
                    continue;
                }

                if ($deep === true || strpos($subPath, '/') === false) {
                    yield new FileAttributes(ltrim($path, '/'));
                }
            }
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $source = $this->preparePath($source);
        $destination = $this->preparePath($destination);

        if ( ! $this->fileExists($source) || $this->fileExists($destination)) {
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }

        $this->files[$destination] = $this->files[$source];
        unset($this->files[$source]);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $source = $this->preparePath($source);
        $destination = $this->preparePath($destination);

        if ( ! $this->fileExists($source)) {
            throw UnableToCopyFile::fromLocationTo($source, $destination);
        }

        $this->files[$destination] = clone $this->files[$source];
    }

    private function preparePath(string $path): string
    {
        return '/' . ltrim($path, '/');
    }
}
