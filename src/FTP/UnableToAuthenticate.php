<?php

declare(strict_types=1);

namespace Flysystem\FTP;

use RuntimeException;

final class UnableToAuthenticate extends RuntimeException implements FtpConnectionException
{
    public function __construct()
    {
        parent::__construct("Unable to login/authenticate with FTP");
    }
}
