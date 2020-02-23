<?php

declare(strict_types=1);

namespace Flysystem\FTP;

use RuntimeException;

final class UnableToEnableUtf8Mode extends RuntimeException implements FtpConnectionException
{
}
