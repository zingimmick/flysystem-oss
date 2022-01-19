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
    /**
     * @var \Zing\Flysystem\Oss\OssAdapter
     */
    private $ossAdapter;

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
            self::markTestSkipped('Mock tests enabled');
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
        self::assertSame('write', $this->ossAdapter->read('fixture/copy.txt'));
    }

    public function testCreateDir(): void
    {
        $this->ossAdapter->createDirectory('fixture/path', new Config());
        self::assertTrue($this->ossAdapter->directoryExists('fixture/path'));
        self::assertEquals([], iterator_to_array($this->ossAdapter->listContents('fixture/path', false)));
        $this->ossAdapter->deleteDirectory('fixture/path');
        self::assertFalse($this->ossAdapter->directoryExists('fixture/path'));
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
        self::assertSame(Visibility::PRIVATE, $this->ossAdapter->visibility('fixture/file.txt')['visibility']);
        $this->ossAdapter->setVisibility('fixture/file.txt', Visibility::PUBLIC);
        self::assertSame(Visibility::PUBLIC, $this->ossAdapter->visibility('fixture/file.txt')['visibility']);
    }

    public function testRename(): void
    {
        $this->ossAdapter->write('fixture/from.txt', 'write', new Config());
        self::assertTrue($this->ossAdapter->fileExists('fixture/from.txt'));
        self::assertFalse($this->ossAdapter->fileExists('fixture/to.txt'));
        $this->ossAdapter->move('fixture/from.txt', 'fixture/to.txt', new Config());
        self::assertFalse($this->ossAdapter->fileExists('fixture/from.txt'));
        self::assertSame('write', $this->ossAdapter->read('fixture/to.txt'));
        $this->ossAdapter->delete('fixture/to.txt');
    }

    public function testDeleteDir(): void
    {
        $this->ossAdapter->deleteDirectory('fixture');
        self::assertEmpty(iterator_to_array($this->ossAdapter->listContents('fixture', false)));
    }

    public function testWriteStream(): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamForResource('write'), new Config());
        self::assertSame('write', $this->ossAdapter->read('fixture/file.txt'));
    }

    /**
     * @return \Iterator<string[]>
     */
    public function provideVisibilities(): \Iterator
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
        self::assertSame($visibility, $this->ossAdapter->visibility('fixture/file.txt')['visibility']);
    }

    public function testWriteStreamWithExpires(): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamForResource('write'), new Config([
            'Expires' => 20,
        ]));
        self::assertSame('write', $this->ossAdapter->read('fixture/file.txt'));
    }

    public function testWriteStreamWithMimetype(): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamForResource('write'), new Config([
            OssClient::OSS_CONTENT_TYPE => 'image/png',
        ]));
        self::assertSame('image/png', $this->ossAdapter->mimeType('fixture/file.txt')['mime_type']);
    }

    public function testDelete(): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamForResource('test'), new Config());
        self::assertTrue($this->ossAdapter->fileExists('fixture/file.txt'));
        $this->ossAdapter->delete('fixture/file.txt');
        self::assertFalse($this->ossAdapter->fileExists('fixture/file.txt'));
    }

    public function testWrite(): void
    {
        $this->ossAdapter->write('fixture/file.txt', 'write', new Config());
        self::assertSame('write', $this->ossAdapter->read('fixture/file.txt'));
    }

    public function testRead(): void
    {
        self::assertSame('read-test', $this->ossAdapter->read('fixture/read.txt'));
    }

    public function testReadStream(): void
    {
        self::assertSame('read-test', stream_get_contents($this->ossAdapter->readStream('fixture/read.txt')));
    }

    public function testGetVisibility(): void
    {
        self::assertSame(Visibility::PUBLIC, $this->ossAdapter->visibility('fixture/read.txt')->visibility());
    }

    public function testListContents(): void
    {
        self::assertNotEmpty(iterator_to_array($this->ossAdapter->listContents('fixture', false)));
        self::assertEmpty(iterator_to_array($this->ossAdapter->listContents('path1', false)));
        $this->ossAdapter->createDirectory('fixture/path/dir', new Config());
        $this->ossAdapter->write('fixture/path/dir/file.txt', 'test', new Config());
        /** @var \League\Flysystem\StorageAttributes[] $contents */
        $contents = iterator_to_array($this->ossAdapter->listContents('fixture/path', true));
        self::assertContainsOnlyInstancesOf(StorageAttributes::class, $contents);
        self::assertCount(2, $contents);
        /** @var \League\Flysystem\FileAttributes $file */
        /** @var \League\Flysystem\DirectoryAttributes $directory */
        [$file,$directory] = $contents[0]->isFile() ? [$contents[0], $contents[1]] : [$contents[1], $contents[0]];
        self::assertInstanceOf(FileAttributes::class, $file);
        self::assertSame('fixture/path/dir/file.txt', $file->path());
        self::assertSame(4, $file->fileSize());

        self::assertNull($file->mimeType());
        self::assertNotNull($file->lastModified());
        self::assertNull($file->visibility());
        self::assertIsArray($file->extraMetadata());
        self::assertInstanceOf(DirectoryAttributes::class, $directory);
        self::assertSame('fixture/path/dir', $directory->path());
    }

    public function testGetSize(): void
    {
        self::assertSame(9, $this->ossAdapter->fileSize('fixture/read.txt')->fileSize());
    }

    public function testGetTimestamp(): void
    {
        self::assertGreaterThan(time() - 10, $this->ossAdapter->lastModified('fixture/read.txt')->lastModified());
    }

    public function testGetMimetype(): void
    {
        self::assertSame('text/plain', $this->ossAdapter->mimeType('fixture/read.txt')->mimeType());
    }

    public function testHas(): void
    {
        self::assertTrue($this->ossAdapter->fileExists('fixture/read.txt'));
    }

    public function testGetTemporaryUrl(): void
    {
        self::assertSame(
            'read-test',
            file_get_contents($this->ossAdapter->getTemporaryUrl('fixture/read.txt', 10, []))
        );
    }

    public function testImage(): void
    {
        $contents = file_get_contents('https://via.placeholder.com/640x480.png');
        if ($contents === false) {
            self::markTestSkipped('Require image contents');
        }

        $this->ossAdapter->write('fixture/image.png', $contents, new Config());
        /** @var array{int, int} $info */
        $info = getimagesize($this->ossAdapter->getTemporaryUrl('fixture/image.png', 10, [
            'x-oss-process' => 'image/crop,w_200,h_100',
        ]));

        self::assertSame(200, $info[0]);
        self::assertSame(100, $info[1]);
    }
}
