<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss\Tests;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\StorageAttributes;
use League\Flysystem\Visibility;
use OSS\OssClient;
use Zing\Flysystem\Oss\OssAdapter;

/**
 * @internal
 */
final class ValidAdapterTest extends TestCase
{
    private OssAdapter $ossAdapter;

    private function getKey(): string
    {
        return (string) getenv('ALIBABA_CLOUD_KEY') ?: '';
    }

    private function getSecret(): string
    {
        return (string) getenv('ALIBABA_CLOUD_SECRET') ?: '';
    }

    private function getBucket(): string
    {
        return (string) getenv('ALIBABA_CLOUD_BUCKET') ?: '';
    }

    private function getEndpoint(): string
    {
        return (string) getenv('ALIBABA_CLOUD_ENDPOINT') ?: 'oss-cn-shanghai.aliyuncs.com';
    }

    protected function setUp(): void
    {
        if ((string) getenv('MOCK') !== 'false') {
            $this->markTestSkipped('Mock tests enabled');
        }

        parent::setUp();

        $config = [
            'key' => $this->getKey(),
            'secret' => $this->getSecret(),
            'bucket' => $this->getBucket(),
            'endpoint' => $this->getEndpoint(),
            'path_style' => '',
            'region' => '',
        ];

        $this->ossAdapter = new OssAdapter(new OssClient(
            $config['key'],
            $config['secret'],
            $config['endpoint']
        ), $this->getBucket());
        $this->ossAdapter->write('fixture/read.txt', 'read-test', new Config());
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->ossAdapter->deleteDirectory('fixture');
    }

    public function testCopy(): void
    {
        $this->ossAdapter->write('fixture/file.txt', 'write', new Config());
        $this->ossAdapter->copy('fixture/file.txt', 'fixture/copy.txt', new Config());
        $this->assertSame('write', $this->ossAdapter->read('fixture/copy.txt'));
    }

    public function testCreateDir(): void
    {
        $this->ossAdapter->createDirectory('fixture/path', new Config());
        $this->assertTrue($this->ossAdapter->directoryExists('fixture/path'));
        $this->assertSame([], iterator_to_array($this->ossAdapter->listContents('fixture/path', false)));
        $this->assertSame([], iterator_to_array($this->ossAdapter->listContents('fixture/path/', false)));
        $this->ossAdapter->write('fixture/path1/file.txt', 'test', new Config());
        $contents = iterator_to_array($this->ossAdapter->listContents('fixture/path1', false));
        $this->assertCount(1, $contents);
        $file = $contents[0];
        $this->assertSame('fixture/path1/file.txt', $file['path']);
        $this->ossAdapter->deleteDirectory('fixture/path');
        $this->assertFalse($this->ossAdapter->directoryExists('fixture/path'));
        $this->ossAdapter->deleteDirectory('fixture/path1');
        $this->assertFalse($this->ossAdapter->directoryExists('fixture/path1'));
    }

    public function testDirectoryExists(): void
    {
        $this->assertFalse($this->ossAdapter->directoryExists('fixture/exists-directory'));
        $this->ossAdapter->createDirectory('fixture/exists-directory', new Config());
        $this->assertTrue($this->ossAdapter->directoryExists('fixture/exists-directory'));
    }

    public function testSetVisibility(): void
    {
        $this->ossAdapter->write('fixture/file.txt', 'write', new Config([
            'visibility' => Visibility::PRIVATE,
        ]));
        $this->assertSame(Visibility::PRIVATE, $this->ossAdapter->visibility('fixture/file.txt')['visibility']);
        $this->ossAdapter->setVisibility('fixture/file.txt', Visibility::PUBLIC);
        $this->assertSame(Visibility::PUBLIC, $this->ossAdapter->visibility('fixture/file.txt')['visibility']);
    }

    public function testRename(): void
    {
        $this->ossAdapter->write('fixture/from.txt', 'write', new Config());
        $this->assertTrue($this->ossAdapter->fileExists('fixture/from.txt'));
        $this->assertFalse($this->ossAdapter->fileExists('fixture/to.txt'));
        $this->ossAdapter->move('fixture/from.txt', 'fixture/to.txt', new Config());
        $this->assertFalse($this->ossAdapter->fileExists('fixture/from.txt'));
        $this->assertSame('write', $this->ossAdapter->read('fixture/to.txt'));
        $this->ossAdapter->delete('fixture/to.txt');
    }

    public function testDeleteDir(): void
    {
        $this->ossAdapter->deleteDirectory('fixture');
        $this->assertFalse($this->ossAdapter->directoryExists('fixture'));
    }

    public function testWriteStream(): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamForResource('write'), new Config());
        $this->assertSame('write', $this->ossAdapter->read('fixture/file.txt'));
    }

    /**
     * @return \Iterator<string[]>
     */
    public static function provideVisibilities(): \Iterator
    {
        yield [Visibility::PUBLIC];

        yield [Visibility::PRIVATE];
    }

    /**
     * @dataProvider provideVisibilities
     */
    public function testWriteStreamWithVisibility(string $visibility): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamForResource('write'), new Config([
            'visibility' => $visibility,
        ]));
        $this->assertSame($visibility, $this->ossAdapter->visibility('fixture/file.txt')['visibility']);
    }

    public function testWriteStreamWithExpires(): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamForResource('write'), new Config([
            'Expires' => 20,
        ]));
        $this->assertSame('write', $this->ossAdapter->read('fixture/file.txt'));
    }

    public function testWriteStreamWithMimetype(): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamForResource('write'), new Config([
            OssClient::OSS_CONTENT_TYPE => 'image/png',
        ]));
        $this->assertSame('image/png', $this->ossAdapter->mimeType('fixture/file.txt')['mime_type']);
    }

    public function testDelete(): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamForResource('test'), new Config());
        $this->assertTrue($this->ossAdapter->fileExists('fixture/file.txt'));
        $this->ossAdapter->delete('fixture/file.txt');
        $this->assertFalse($this->ossAdapter->fileExists('fixture/file.txt'));
    }

    public function testWrite(): void
    {
        $this->ossAdapter->write('fixture/file.txt', 'write', new Config());
        $this->assertSame('write', $this->ossAdapter->read('fixture/file.txt'));
    }

    public function testRead(): void
    {
        $this->assertSame('read-test', $this->ossAdapter->read('fixture/read.txt'));
    }

    public function testReadStream(): void
    {
        $this->assertSame('read-test', stream_get_contents($this->ossAdapter->readStream('fixture/read.txt')));
    }

    public function testGetVisibility(): void
    {
        $this->assertSame(Visibility::PUBLIC, $this->ossAdapter->visibility('fixture/read.txt')->visibility());
    }

    public function testListContents(): void
    {
        $this->assertNotEmpty(iterator_to_array($this->ossAdapter->listContents('fixture', false)));
        $this->assertEmpty(iterator_to_array($this->ossAdapter->listContents('path1', false)));
        $this->ossAdapter->createDirectory('fixture/path/dir', new Config());
        $this->ossAdapter->write('fixture/path/dir/file.txt', 'test', new Config());

        /** @var \League\Flysystem\StorageAttributes[] $contents */
        $contents = iterator_to_array($this->ossAdapter->listContents('fixture/path', true));
        $this->assertContainsOnlyInstancesOf(StorageAttributes::class, $contents);
        $this->assertCount(2, $contents);
        /** @var \League\Flysystem\FileAttributes $file */
        /** @var \League\Flysystem\DirectoryAttributes $directory */
        [$file, $directory] = $contents[0]->isFile() ? [$contents[0], $contents[1]] : [$contents[1], $contents[0]];
        $this->assertInstanceOf(FileAttributes::class, $file);
        $this->assertSame('fixture/path/dir/file.txt', $file->path());
        $this->assertSame(4, $file->fileSize());

        $this->assertNull($file->mimeType());
        $this->assertNotNull($file->lastModified());
        $this->assertNull($file->visibility());
        $this->assertIsArray($file->extraMetadata());
        $this->assertInstanceOf(DirectoryAttributes::class, $directory);
        $this->assertSame('fixture/path/dir', $directory->path());
    }

    public function testGetSize(): void
    {
        $this->assertSame(9, $this->ossAdapter->fileSize('fixture/read.txt')->fileSize());
    }

    public function testGetTimestamp(): void
    {
        $this->assertGreaterThan(time() - 10, $this->ossAdapter->lastModified('fixture/read.txt')->lastModified());
    }

    public function testGetMimetype(): void
    {
        $this->assertSame('text/plain', $this->ossAdapter->mimeType('fixture/read.txt')->mimeType());
    }

    public function testHas(): void
    {
        $this->assertTrue($this->ossAdapter->fileExists('fixture/read.txt'));
    }

    public function testGetTemporaryUrl(): void
    {
        $this->assertSame(
            'read-test',
            file_get_contents($this->ossAdapter->getTemporaryUrl('fixture/read.txt', 10, []))
        );
    }

    public function testImage(): void
    {
        $contents = file_get_contents('https://avatars.githubusercontent.com/u/26657141');
        if ($contents === false) {
            $this->markTestSkipped('Require image contents');
        }

        $this->ossAdapter->write('fixture/image.png', $contents, new Config());

        /** @var array{int, int} $info */
        $info = getimagesize($this->ossAdapter->getTemporaryUrl('fixture/image.png', 10, [
            'x-oss-process' => 'image/crop,w_200,h_100',
        ]));

        $this->assertSame(200, $info[0]);
        $this->assertSame(100, $info[1]);
    }

    /**
     * @dataProvider provideVisibilities
     */
    public function testCopyWithVisibility(string $visibility): void
    {
        $this->ossAdapter->write('fixture/private.txt', 'private', new Config([
            Config::OPTION_VISIBILITY => $visibility,
        ]));
        $this->ossAdapter->copy('fixture/private.txt', 'fixture/copied-private.txt', new Config());
        $this->assertSame($visibility, $this->ossAdapter->visibility('fixture/copied-private.txt')->visibility());
    }

    public function testMovingAFileWithVisibility(): void
    {
        $adapter = $this->ossAdapter;
        $adapter->write(
            'source.txt',
            'contents to be copied',
            new Config([
                Config::OPTION_VISIBILITY => Visibility::PUBLIC,
            ])
        );
        $adapter->move('source.txt', 'destination.txt', new Config([
            Config::OPTION_VISIBILITY => Visibility::PRIVATE,
        ]));
        $this->assertFalse(
            $adapter->fileExists('source.txt'),
            'After moving a file should no longer exist in the original location.'
        );
        $this->assertTrue(
            $adapter->fileExists('destination.txt'),
            'After moving, a file should be present at the new location.'
        );
        $this->assertSame(Visibility::PRIVATE, $adapter->visibility('destination.txt')->visibility());
        $this->assertSame('contents to be copied', $adapter->read('destination.txt'));
    }

    public function testCopyingAFileWithVisibility(): void
    {
        $adapter = $this->ossAdapter;
        $adapter->write(
            'source.txt',
            'contents to be copied',
            new Config([
                Config::OPTION_VISIBILITY => Visibility::PUBLIC,
            ])
        );

        $adapter->copy('source.txt', 'destination.txt', new Config([
            Config::OPTION_VISIBILITY => Visibility::PRIVATE,
        ]));

        $this->assertTrue($adapter->fileExists('source.txt'));
        $this->assertTrue($adapter->fileExists('destination.txt'));
        $this->assertSame(Visibility::PRIVATE, $adapter->visibility('destination.txt')->visibility());
        $this->assertSame('contents to be copied', $adapter->read('destination.txt'));
    }
}
