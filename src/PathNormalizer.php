<?php

declare(strict_types=1);

namespace Flysystem;

interface PathNormalizer
{
    public function normalizePath(string $path): string;
}
