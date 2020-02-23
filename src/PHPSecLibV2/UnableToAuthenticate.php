<?php

declare(strict_types=1);

namespace Flysystem\PHPSecLibV2;

use Flysystem\FilesystemException;
use RuntimeException;

class UnableToAuthenticate extends RuntimeException implements FilesystemException
{
}
