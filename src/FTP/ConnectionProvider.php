<?php

declare(strict_types=1);

namespace Flysystem\FTP;

interface ConnectionProvider
{
    /**
     * @return resource
     */
    public function createConnection(FtpConnectionOptions $options);
}
