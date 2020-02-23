<?php

declare(strict_types=1);

namespace Flysystem\PHPSecLibV2;

use Flysystem\FilesystemException;
use RuntimeException;

class UnableToEstablishAuthenticityOfHost extends RuntimeException implements FilesystemException
{
    public static function becauseTheAuthenticityCantBeEstablished(string $host): UnableToEstablishAuthenticityOfHost
    {
        return new UnableToEstablishAuthenticityOfHost("The authenticity of host $host can't be established.");
    }
}
