<?php

declare(strict_types=1);

namespace Flysystem;

use RuntimeException;

final class UnreadableFileEncountered extends RuntimeException implements FilesystemException
{
    /**
     * @var string
     */
    private $location;

    public function location(): string
    {
        return $this->location;
    }

    public static function atLocation(string $location): UnreadableFileEncountered
    {
        $e = new static("Unreadable file encountered at location {$location}.");
        $e->location = $location;

        return $e;
    }
}
