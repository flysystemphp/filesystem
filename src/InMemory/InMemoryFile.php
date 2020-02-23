<?php

declare(strict_types=1);

namespace Flysystem\InMemory;

use finfo;

use const FILEINFO_MIME_TYPE;

class InMemoryFile
{
    /**
     * @var string
     */
    private $contents;

    /**
     * @var int
     */
    private $lastModified;

    /**
     * @var string
     */
    private $visibility;

    public function updateContents(string $contents): void
    {
        $this->contents = $contents;
        $this->lastModified = time();
    }

    public function lastModified(): int
    {
        return $this->lastModified;
    }

    public function read(): string
    {
        return $this->contents;
    }

    /**
     * @return resource
     */
    public function readStream()
    {
        /** @var resource $stream */
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->contents);
        rewind($stream);

        return $stream;
    }

    public function fileSize(): int
    {
        return function_exists('mb_strlen')
            ? mb_strlen($this->contents)
            : strlen($this->contents);
    }

    public function mimeType(): string
    {
        return (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($this->contents);
    }

    public function setVisibility(string $visibility): void
    {
        $this->visibility = $visibility;
    }

    public function visibility(): string
    {
        return $this->visibility;
    }
}
