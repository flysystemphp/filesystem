<?php

declare(strict_types=1);

namespace Flysystem\PHPSecLibV2;

use phpseclib\Net\SFTP;

interface ConnectionProvider
{
    public function provideConnection(): SFTP;
}
