<?php

declare(strict_types=1);

namespace Flysystem;

use ArrayAccess;
use JsonSerializable;

interface StorageAttributes extends JsonSerializable, ArrayAccess
{
    public const TYPE_FILE = 'file';
    public const TYPE_DIRECTORY = 'dir';

    public function path(): string;

    public function type(): string;

    public function visibility(): ?string;

    public static function fromArray(array $attributes): StorageAttributes;

    public function isFile(): bool;

    public function isDir(): bool;
}
