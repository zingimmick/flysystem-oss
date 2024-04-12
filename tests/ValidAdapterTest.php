<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss\Tests;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use OSS\OssClient;
use Zing\Flysystem\Oss\OssAdapter;

class ValidAdapterTest extends TestCase
{
    /**
     * @var \Zing\Flysystem\Oss\OssAdapter
     */
    private $ossAdapter;

    private function getKey(): string
    {
        return (string) getenv('OSS_KEY') ?: '';
    }

    private function getSecret(): string
    {
        return (string) getenv('OSS_SECRET') ?: '';
    }

    protected function getBucket(): string
    {
        return (string) getenv('OSS_BUCKET') ?: '';
    }

    protected function getEndpoint(): string
    {
        return (string) getenv('OSS_ENDPOINT') ?: 'oss-cn-shanghai.aliyuncs.com';
    }

    protected function isBucketEndpoint(): bool
    {
        return false;
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
            $config['endpoint'],
            $this->isBucketEndpoint()
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
        $this->assertSame('update', $this->ossAdapter->read('fixture/file.txt')['contents']);
    }

    public function testUpdateStream(): void
    {
        $this->ossAdapter->write('fixture/file.txt', 'write', new Config());
        $this->ossAdapter->updateStream('fixture/file.txt', $this->streamFor('update')->detach(), new Config());
        $this->assertSame('update', $this->ossAdapter->read('fixture/file.txt')['contents']);
    }

    public function testCopy(): void
    {
        $this->ossAdapter->write('fixture/file.txt', 'write', new Config());
        $this->ossAdapter->copy('fixture/file.txt', 'fixture/copy.txt');
        $this->assertSame('write', $this->ossAdapter->read('fixture/copy.txt')['contents']);
    }

    public function testCreateDir(): void
    {
        $this->ossAdapter->createDir('fixture/path', new Config());
        $this->assertSame([], $this->ossAdapter->listContents('fixture/path'));
        $this->assertSame([], $this->ossAdapter->listContents('fixture/path/'));
        $this->ossAdapter->write('fixture/path1/file.txt', 'test', new Config());
        $contents = $this->ossAdapter->listContents('fixture/path1');
        $this->assertCount(1, $contents);
        $file = $contents[0];
        $this->assertSame('fixture/path1/file.txt', $file['path']);
    }

    public function testSetVisibility(): void
    {
        $this->ossAdapter->write('fixture/file.txt', 'write', new Config([
            'visibility' => AdapterInterface::VISIBILITY_PRIVATE,
        ]));
        $this->assertSame(
            AdapterInterface::VISIBILITY_PRIVATE,
            $this->ossAdapter->getVisibility('fixture/file.txt')['visibility']
        );
        $this->ossAdapter->setVisibility('fixture/file.txt', AdapterInterface::VISIBILITY_PUBLIC);
        $this->assertSame(
            AdapterInterface::VISIBILITY_PUBLIC,
            $this->ossAdapter->getVisibility('fixture/file.txt')['visibility']
        );
    }

    public function testRename(): void
    {
        $this->ossAdapter->write('fixture/from.txt', 'write', new Config());
        $this->assertTrue($this->ossAdapter->has('fixture/from.txt'));
        $this->assertFalse($this->ossAdapter->has('fixture/to.txt'));
        $this->ossAdapter->rename('fixture/from.txt', 'fixture/to.txt');
        $this->assertFalse($this->ossAdapter->has('fixture/from.txt'));
        $this->assertSame('write', $this->ossAdapter->read('fixture/to.txt')['contents']);
        $this->ossAdapter->delete('fixture/to.txt');
    }

    public function testDeleteDir(): void
    {
        $this->assertTrue($this->ossAdapter->deleteDir('fixture'));
        $this->assertFalse($this->ossAdapter->has('fixture'));
    }

    public function testWriteStream(): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamFor('write')->detach(), new Config());
        $this->assertSame('write', $this->ossAdapter->read('fixture/file.txt')['contents']);
    }

    /**
     * @return \Iterator<string[]>
     */
    public static function provideWriteStreamWithVisibilityCases(): \Iterator
    {
        yield [AdapterInterface::VISIBILITY_PUBLIC];

        yield [AdapterInterface::VISIBILITY_PRIVATE];
    }

    /**
     * @dataProvider provideWriteStreamWithVisibilityCases
     */
    public function testWriteStreamWithVisibility(string $visibility): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamFor('write')->detach(), new Config([
            'visibility' => $visibility,
        ]));
        $this->assertSame($visibility, $this->ossAdapter->getVisibility('fixture/file.txt')['visibility']);
    }

    public function testWriteStreamWithExpires(): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamFor('write')->detach(), new Config([
            'Expires' => 20,
        ]));
        $this->assertSame('write', $this->ossAdapter->read('fixture/file.txt')['contents']);
    }

    public function testWriteStreamWithMimetype(): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamFor('write')->detach(), new Config([
            OssClient::OSS_CONTENT_TYPE => 'image/png',
        ]));
        $this->assertSame('image/png', $this->ossAdapter->getMimetype('fixture/file.txt')['mimetype']);
    }

    public function testDelete(): void
    {
        $this->ossAdapter->writeStream('fixture/file.txt', $this->streamFor('test')->detach(), new Config());
        $this->assertTrue($this->ossAdapter->has('fixture/file.txt'));
        $this->ossAdapter->delete('fixture/file.txt');
        $this->assertFalse($this->ossAdapter->has('fixture/file.txt'));
    }

    public function testWrite(): void
    {
        $this->ossAdapter->write('fixture/file.txt', 'write', new Config());
        $this->assertSame('write', $this->ossAdapter->read('fixture/file.txt')['contents']);
    }

    public function testRead(): void
    {
        $this->assertSame('read-test', $this->ossAdapter->read('fixture/read.txt')['contents']);
    }

    public function testReadStream(): void
    {
        $this->assertSame(
            'read-test',
            stream_get_contents($this->ossAdapter->readStream('fixture/read.txt')['stream'])
        );
    }

    public function testGetVisibility(): void
    {
        $this->assertSame(
            AdapterInterface::VISIBILITY_PRIVATE,
            $this->ossAdapter->getVisibility('fixture/read.txt')['visibility']
        );
    }

    public function testGetMetadata(): void
    {
        $this->assertIsArray($this->ossAdapter->getMetadata('fixture/read.txt'));
    }

    public function testListContents(): void
    {
        $this->assertNotEmpty($this->ossAdapter->listContents('fixture'));
        $this->assertEmpty($this->ossAdapter->listContents('path1'));
        $this->ossAdapter->write('fixture/path/file.txt', 'test', new Config());
        $this->ossAdapter->listContents('a', true);
    }

    public function testGetSize(): void
    {
        $this->assertSame(9, $this->ossAdapter->getSize('fixture/read.txt')['size']);
    }

    public function testGetTimestamp(): void
    {
        $this->assertGreaterThan(time() - 10, $this->ossAdapter->getTimestamp('fixture/read.txt')['timestamp']);
    }

    public function testGetMimetype(): void
    {
        $this->assertSame('text/plain', $this->ossAdapter->getMimetype('fixture/read.txt')['mimetype']);
    }

    public function testHas(): void
    {
        $this->assertTrue($this->ossAdapter->has('fixture/read.txt'));
    }

    public function testSignUrl(): void
    {
        $this->assertSame('read-test', file_get_contents($this->ossAdapter->signUrl('fixture/read.txt', 10, [])));
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
        $this->ossAdapter->write(
            'fixture/image.png',
            file_get_contents('https://avatars.githubusercontent.com/u/26657141'),
            new Config()
        );
        $info = getimagesize($this->ossAdapter->signUrl('fixture/image.png', 10, [
            'x-oss-process' => 'image/crop,w_200,h_100',
        ]));
        $this->assertSame(200, $info[0]);
        $this->assertSame(100, $info[1]);
    }

    public function testForceMimetype(): void
    {
        $this->ossAdapter->write('fixture/file.txt', 'test', new Config([
            'mimetype' => 'image/png',
        ]));
        $this->assertSame('image/png', $this->ossAdapter->getMimetype('fixture/file.txt')['mimetype']);
        $this->ossAdapter->write('fixture/file2.txt', 'test', new Config([
            'Content-Type' => 'image/png',
        ]));
        $this->assertSame('image/png', $this->ossAdapter->getMimetype('fixture/file2.txt')['mimetype']);
    }
}
