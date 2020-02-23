<?php

declare(strict_types=1);

namespace Flysystem\PHPSecLibV2;

use Flysystem\Config;
use Flysystem\FilesystemAdapter;
use Flysystem\FilesystemAdapterTestCase;
use Flysystem\UnableToCopyFile;
use Flysystem\UnableToCreateDirectory;
use Flysystem\UnableToMoveFile;
use Flysystem\UnableToReadFile;
use Flysystem\UnableToWriteFile;

/**
 * @group sftp
 */
class SftpAdapterTest extends FilesystemAdapterTestCase
{
    /**
     * @var ConnectionProvider
     */
    private $connectionProvider;

    /**
     * @var SftpStub
     */
    private $connection;

    protected function createFilesystemAdapter(): FilesystemAdapter
    {
        if (getenv('FLYSYSTEM_TEST_SFTP') !== 'yes') {
            $this->markTestSkipped('Opted out of testing SFTP');
        }

        return new SftpAdapter(
            $this->connectionProvider(),
            '/upload'
        );
    }

    /**
     * @before
     */
    public function setupConnectionProvider(): void
    {
        /** @var SftpStub $connection */
        $connection = $this->connectionProvider()->provideConnection();
        $this->connection = $connection;
    }

    /**
     * @test
     */
    public function failing_to_create_a_directory(): void
    {
        $adapter = $this->adapterWithInvalidRoot();

        $this->expectException(UnableToCreateDirectory::class);

        $adapter->createDirectory('not-gonna-happen', new Config());
    }

    /**
     * @test
     */
    public function failing_to_write_a_file(): void
    {
        $adapter = $this->adapterWithInvalidRoot();

        $this->expectException(UnableToWriteFile::class);

        $adapter->write('not-gonna-happen', 'na-ah', new Config());
    }

    /**
     * @test
     */
    public function failing_to_read_a_file(): void
    {
        $adapter = $this->adapterWithInvalidRoot();

        $this->expectException(UnableToReadFile::class);

        $adapter->read('not-gonna-happen');
    }

    /**
     * @test
     */
    public function failing_to_read_a_file_as_a_stream(): void
    {
        $adapter = $this->adapterWithInvalidRoot();

        $this->expectException(UnableToReadFile::class);

        $adapter->readStream('not-gonna-happen');
    }

    /**
     * @test
     */
    public function failing_to_write_a_file_using_streams(): void
    {
        $adapter = $this->adapterWithInvalidRoot();
        $writeHandle = stream_with_contents('contents');

        $this->expectException(UnableToWriteFile::class);

        try {
            $adapter->writeStream('not-gonna-happen', $writeHandle, new Config());
        } finally {
            fclose($writeHandle);
        }
    }

    /**
     * @test
     */
    public function detecting_mimetype(): void
    {
        $adapter = $this->adapter();
        $adapter->write('file.svg', (string) file_get_contents(__DIR__.'/../../test_files/flysystem.svg'), new Config());

        $mimeType = $adapter->mimeType('file.svg');

        $this->assertEquals('image/svg', $mimeType->mimeType());
    }

    /**
     * @test
     */
    public function failing_to_chmod_when_writing(): void
    {
        $this->connection->failOnChmod('/upload/path.txt');
        $adapter = $this->adapter();

        $this->expectException(UnableToWriteFile::class);

        $adapter->write('path.txt', 'contents', new Config(['visibility' => 'public']));
    }

    /**
     * @test
     */
    public function failing_to_move_a_file_cause_the_parent_directory_cant_be_created(): void
    {
        $adapter = $this->adapterWithInvalidRoot();

        $this->expectException(UnableToMoveFile::class);

        $adapter->move('path.txt', 'new-path.txt', new Config());
    }

    /**
     * @test
     */
    public function failing_to_copy_a_file(): void
    {
        $adapter = $this->adapterWithInvalidRoot();

        $this->expectException(UnableToCopyFile::class);

        $adapter->copy('path.txt', 'new-path.txt', new Config());
    }

    /**
     * @test
     */
    public function failing_to_copy_a_file_because_writing_fails(): void
    {
        $this->givenWeHaveAnExistingFile('path.txt', 'contents');
        $adapter = $this->adapter();
        $this->connection->failOnPut('/upload/new-path.txt');

        $this->expectException(UnableToCopyFile::class);

        $adapter->copy('path.txt', 'new-path.txt', new Config());
    }

    /**
     * @test
     */
    public function failing_to_chmod_when_writing_with_a_stream(): void
    {
        $writeStream = stream_with_contents('contents');
        $this->connection->failOnChmod('/upload/path.txt');
        $adapter = $this->adapter();

        $this->expectException(UnableToWriteFile::class);

        try {
            $adapter->writeStream('path.txt', $writeStream, new Config(['visibility' => 'public']));
        } finally {
            @fclose($writeStream);
        }
    }

    private function connectionProvider(): ConnectionProvider
    {
        if ( ! $this->connectionProvider instanceof ConnectionProvider) {
            $this->connectionProvider = new StubSFTPConnectionProvider('localhost', 'foo', 'pass', 2222);
        }

        return $this->connectionProvider;
    }

    /**
     * @return SftpAdapter
     */
    private function adapterWithInvalidRoot(): SftpAdapter
    {
        $provider = $this->connectionProvider();
        $adapter = new SftpAdapter($provider, '/invalid');

        return $adapter;
    }
}
