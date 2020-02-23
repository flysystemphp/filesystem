<?php

declare(strict_types=1);

namespace Flysystem\FTP;

interface ConnectivityChecker
{
    /**
     * @param resource $connection
     */
    public function isConnected($connection): bool;
}
