<?php

declare(strict_types=1);

namespace Flysystem\FTP;

use RuntimeException;

class UnableToMakeConnectionPassive extends RuntimeException implements FtpConnectionException
{
}
