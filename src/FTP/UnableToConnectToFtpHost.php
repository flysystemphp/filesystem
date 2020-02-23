<?php

declare(strict_types=1);

namespace Flysystem\FTP;

use RuntimeException;

final class UnableToConnectToFtpHost extends RuntimeException implements FtpConnectionException
{
    public static function forHost(string $host, int $port, bool $ssl): UnableToConnectToFtpHost
    {
        $usingSsl = $ssl ? ', using ssl' : '';

        return new UnableToConnectToFtpHost("Unable to connect to host $host at port $port$usingSsl.");
    }
}
