<?php

declare(strict_types=1);

namespace Flysystem\FTP;

class NoopCommandConnectivityChecker implements ConnectivityChecker
{
    public function isConnected($connection): bool
    {
        $response = @ftp_raw($connection, 'NOOP');
        $responseCode = $response ? (int) preg_replace('/\D/', '', implode('', $response)) : false;

        return $responseCode === 200;
    }
}
