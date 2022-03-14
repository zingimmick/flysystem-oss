<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss\Tests;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
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
        ), $this->getBucket(), '', [
            'default_visibility' => AdapterInterface::VISIBILITY_PUBLIC,
        ]);
        $this->ossAdapter->write('fixture/read.txt', 'read-test', new Config(
            [
                'visibility' => AdapterInterface::VISIBILITY_PRIVATE,
            ]
        ));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->ossAdapter->deleteDir('fixture');
    }

    public function testUpdate(): void
    {
        $this->ossAdapter->update('fixture/file.txt', 'update', new Config());
        self::assertSame('update', $this->ossAdapter->read('fixture/file.txt')['contents']);
    }

    public function testUpdateStream(): void
    {
        $this->ossAdapter->write('fixture/file.txt', 'write', new Config());
        $this->ossAdapter->updateStream('fixture/file.txt', $this->streamFor('update')->detach(), new Config());
        self::assertSame('update', $this->ossAdapter->read('fixture/file.txt')['contents']);
    }

    public function testCopy(): void
    {
        $this->ossAdapter->write('fixture/file.txt', 'write', new Config());
        $this->ossAdapter->copy('fixture/file.txt', 'fixture/copy.txt');
        self::assertSame('write', $this->ossAdapter->read('fixture/copy.txt')['contents']);
    }

    public function testCreateDir(): void
    {
        $this->ossAdapter->createDir('fixture/path', new Config());
        self::assertSame([], $this->ossAdapter->listContents('fixture/path'));
        self::assertSame([], $this->ossAdapter->listContents('fixture/path/'));
        $this->ossAdapter->write('fixture/path1/file.txt', 'test', new Config());
        $contents = $this->ossAdapter->listContents('fixture/path1');
        self::assertCount(1, $contents);
        $file = $contents[0];
        self::assertSame('fixture/path1/file.txt', $file['path']);
    }

    public function testSetVisibility(): void
    {
        $this->ossAdapter->write('fixture/file.txt', 'write', new Config([
            'visibility' => AdapterInterface::VISIBILITY_PRIVATE,
        ]));
        self::assertSame(
            AdapterInterface::VISIBILITY_PRIVATE,
            $this->ossAdapter->getVisibility('fixture/file.txt')['visibility']
        );
        $this->ossAdapter->setVisibility('fixture/file.txt', AdapterInterface::VISIBILITY_PUBLIC);
        self::assertSame(
            AdapterInterface::VISIBILITY_PUBLIC,
            $this->ossAdapter->getVisibility('fixture/file.txt')['visibility']
        );
    }

    public function testRename(): void
    {
        $this->ossAdapter->write('fixture/from.txt', 'write', new Config());
        self::assertTrue($this->ossAdapter->has('fixture/from.txt'));
        self::assertFalse($this->ossAdapter->has('fixture/to.txt'));
        $this->ossAdapter->rename('fixture/from.txt', 'fixture/to.txt');
        self::assertFalse($this->ossAdapter->has('fixture/from.txt'));
        self::assertSame('write', $this->ossAdapter->read('fixture/to.txt')['contents']);
        $this->ossAdapter->delete('fixture/to.txt');
    }

    public function testDeleteDir(): void
    {
        self::assertTrue($this->ossAdapter->deleteDir('fixture'));
        self::assertFalse($this->ossAdapter->has('fixture'));
    }

    public function testWriteStream(): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamFor('write')->detach(), new Config());
        self::assertSame('write', $this->ossAdapter->read('fixture/file.txt')['contents']);
    }

    /**
     * @return \Iterator<string[]>
     */
    public function provideVisibilities(): \Iterator
    {
        yield [AdapterInterface::VISIBILITY_PUBLIC];
        yield [AdapterInterface::VISIBILITY_PRIVATE];
    }

    /**
     * @dataProvider provideVisibilities
     *
     * @param $visibility
     */
    public function testWriteStreamWithVisibility(string $visibility): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamFor('write')->detach(), new Config([
            'visibility' => $visibility,
        ]));
        self::assertSame($visibility, $this->ossAdapter->getVisibility('fixture/file.txt')['visibility']);
    }

    public function testWriteStreamWithExpires(): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamFor('write')->detach(), new Config([
            'Expires' => 20,
        ]));
        self::assertSame('write', $this->ossAdapter->read('fixture/file.txt')['contents']);
    }

    public function testWriteStreamWithMimetype(): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamFor('write')->detach(), new Config([
            OssClient::OSS_CONTENT_TYPE => 'image/png',
        ]));
        self::assertSame('image/png', $this->ossAdapter->getMimetype('fixture/file.txt')['mimetype']);
    }

    public function testDelete(): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamFor('test')->detach(), new Config());
        self::assertTrue($this->ossAdapter->has('fixture/file.txt'));
        $this->ossAdapter->delete('fixture/file.txt');
        self::assertFalse($this->ossAdapter->has('fixture/file.txt'));
    }

    public function testWrite(): void
    {
        $this->ossAdapter->write('fixture/file.txt', 'write', new Config());
        self::assertSame('write', $this->ossAdapter->read('fixture/file.txt')['contents']);
    }

    public function testRead(): void
    {
        self::assertSame('read-test', $this->ossAdapter->read('fixture/read.txt')['contents']);
    }

    public function testReadStream(): void
    {
        self::assertSame('read-test', stream_get_contents($this->ossAdapter->readStream('fixture/read.txt')['stream']));
    }

    public function testGetVisibility(): void
    {
        self::assertSame(
            AdapterInterface::VISIBILITY_PRIVATE,
            $this->ossAdapter->getVisibility('fixture/read.txt')['visibility']
        );
    }

    public function testGetMetadata(): void
    {
        self::assertIsArray($this->ossAdapter->getMetadata('fixture/read.txt'));
    }

    public function testListContents(): void
    {
        self::assertNotEmpty($this->ossAdapter->listContents('fixture'));
        self::assertEmpty($this->ossAdapter->listContents('path1'));
        $this->ossAdapter->write('fixture/path/file.txt', 'test', new Config());
        $this->ossAdapter->listContents('a', true);
    }

    public function testGetSize(): void
    {
        self::assertSame(9, $this->ossAdapter->getSize('fixture/read.txt')['size']);
    }

    public function testGetTimestamp(): void
    {
        self::assertGreaterThan(time() - 10, $this->ossAdapter->getTimestamp('fixture/read.txt')['timestamp']);
    }

    public function testGetMimetype(): void
    {
        self::assertSame('text/plain', $this->ossAdapter->getMimetype('fixture/read.txt')['mimetype']);
    }

    public function testHas(): void
    {
        self::assertTrue($this->ossAdapter->has('fixture/read.txt'));
    }

    public function testSignUrl(): void
    {
        self::assertSame('read-test', file_get_contents($this->ossAdapter->signUrl('fixture/read.txt', 10, [])));
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
        $this->ossAdapter->write(
            'fixture/image.png',
            file_get_contents('https://via.placeholder.com/640x480.png'),
            new Config()
        );
        $info = getimagesize($this->ossAdapter->signUrl('fixture/image.png', 10, [
            'x-oss-process' => 'image/crop,w_200,h_100',
        ]));
        self::assertSame(200, $info[0]);
        self::assertSame(100, $info[1]);
    }    public function testForceMimetype(): void
{
    $this->ossAdapter->write('fixture/file.txt', 'test', new Config([
        'mimetype' => 'image/png',
    ]));
    self::assertSame('image/png', $this->ossAdapter->getMimetype('fixture/file.txt')['mimetype']);
    $this->ossAdapter->write('fixture/file2.txt', 'test', new Config([
        'Content-Type' => 'image/png',
    ]));
    self::assertSame('image/png', $this->ossAdapter->getMimetype('fixture/file2.txt')['mimetype']);
}
}
