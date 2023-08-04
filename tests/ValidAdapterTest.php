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
        self::assertSame([], iterator_to_array($this->ossAdapter->listContents('fixture/path', false)));
        self::assertSame([], iterator_to_array($this->ossAdapter->listContents('fixture/path/', false)));
        $this->ossAdapter->write('fixture/path1/file.txt', 'test', new Config());
        $contents = iterator_to_array($this->ossAdapter->listContents('fixture/path1', false));
        self::assertCount(1, $contents);
        $file = $contents[0];
        self::assertSame('fixture/path1/file.txt', $file['path']);
        $this->ossAdapter->deleteDirectory('fixture/path');
        self::assertFalse($this->ossAdapter->directoryExists('fixture/path'));
        $this->ossAdapter->deleteDirectory('fixture/path1');
        self::assertFalse($this->ossAdapter->directoryExists('fixture/path1'));
    }

    public function testDirectoryExists(): void
    {
        self::assertFalse($this->ossAdapter->directoryExists('fixture/exists-directory'));
        $this->ossAdapter->createDirectory('fixture/exists-directory', new Config());
        self::assertTrue($this->ossAdapter->directoryExists('fixture/exists-directory'));
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
        self::assertFalse($this->ossAdapter->directoryExists('fixture'));
    }

    public function testWriteStream(): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamForResource('write'), new Config());
        self::assertSame('write', $this->ossAdapter->read('fixture/file.txt'));
    }

    /**
     * @return \Iterator<string[]>
     */
    public static function provideVisibilities(): iterable
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
        [$file, $directory] = $contents[0]->isFile() ? [$contents[0], $contents[1]] : [$contents[1], $contents[0]];
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
        $contents = file_get_contents('https://avatars.githubusercontent.com/u/26657141');
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

    /**
     * @dataProvider provideVisibilities
     */
    public function testCopyWithVisibility(string $visibility): void
    {
        $this->ossAdapter->write('fixture/private.txt', 'private', new Config([
            Config::OPTION_VISIBILITY => $visibility,
        ]));
        $this->ossAdapter->copy('fixture/private.txt', 'fixture/copied-private.txt', new Config());
        self::assertSame($visibility, $this->ossAdapter->visibility('fixture/copied-private.txt')->visibility());
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
        self::assertFalse(
            $adapter->fileExists('source.txt'),
            'After moving a file should no longer exist in the original location.'
        );
        self::assertTrue(
            $adapter->fileExists('destination.txt'),
            'After moving, a file should be present at the new location.'
        );
        self::assertSame(Visibility::PRIVATE, $adapter->visibility('destination.txt')->visibility());
        self::assertSame('contents to be copied', $adapter->read('destination.txt'));
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

        self::assertTrue($adapter->fileExists('source.txt'));
        self::assertTrue($adapter->fileExists('destination.txt'));
        self::assertSame(Visibility::PRIVATE, $adapter->visibility('destination.txt')->visibility());
        self::assertSame('contents to be copied', $adapter->read('destination.txt'));
    }
}
