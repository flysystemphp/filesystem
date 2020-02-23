<?php

declare(strict_types=1);

namespace Flysystem;

use function rtrim;
use function strlen;
use function substr;

final class PathPrefixer
{
    /**
     * @var string
     */
    private $prefix = '';

    /**
     * @var string
     */
    private $separator = '/';

    public function __construct(string $prefix, string $separator = '/')
    {
        $this->prefix = rtrim($prefix, '\\/');

        if ($prefix !== '') {
            $this->prefix .= $separator;
        }

        $this->separator = $separator;
    }

    public function prefixPath(string $path): string
    {
        return $this->prefix . ltrim($path, '\\/');
    }

    public function stripPrefix(string $path): string
    {
        return substr($path, strlen($this->prefix));
    }
}
