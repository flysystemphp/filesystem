<?php

declare(strict_types=1);

namespace Flysystem\FTP;

use Flysystem\FilesystemException;
use RuntimeException;

class InvalidListResponseReceived extends RuntimeException implements FilesystemException
{
}
