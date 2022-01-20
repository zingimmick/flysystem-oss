<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss\Tests;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use OSS\Core\OssException;
use OSS\OssClient;
use Zing\Flysystem\Oss\OssAdapter;
use Zing\Flysystem\Oss\Plugins\FileUrl;
use Zing\Flysystem\Oss\Plugins\TemporaryUrl;

/**
 * @internal
 */
final class InvalidAdapterTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private const CONFIG = [
        'key' => 'aW52YWxpZC1rZXk=',
        'secret' => 'aW52YWxpZC1zZWNyZXQ=',
        'bucket' => 'test',
        'endpoint' => 'oss-cn-shanghai.aliyuncs.com',
        'path_style' => '',
        'region' => '',
    ];

    /**
     * @var \Zing\Flysystem\Oss\OssAdapter
     */
    private $ossAdapter;

    /**
     * @var \OSS\OssClient
     */
    private $ossClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ossClient = new OssClient(self::CONFIG['key'], self::CONFIG['secret'], self::CONFIG['endpoint']);
        $this->ossAdapter = new OssAdapter($this->ossClient, self::CONFIG['bucket'], '', [
            'endpoint' => self::CONFIG['endpoint'],
        ]);
    }

    public function testUpdate(): void
    {
        self::assertFalse($this->ossAdapter->update('file.txt', 'test', new Config()));
    }

    public function testUpdateStream(): void
    {
        self::assertFalse(
            $this->ossAdapter->updateStream('file.txt', $this->streamFor('test')->detach(), new Config())
        );
    }

    public function testCopy(): void
    {
        self::assertFalse($this->ossAdapter->copy('file.txt', 'copy.txt'));
    }

    public function testCreateDir(): void
    {
        self::assertFalse($this->ossAdapter->createDir('path', new Config()));
    }

    public function testSetVisibility(): void
    {
        self::assertFalse($this->ossAdapter->setVisibility('file.txt', AdapterInterface::VISIBILITY_PUBLIC));
    }

    public function testRename(): void
    {
        self::assertFalse($this->ossAdapter->rename('from.txt', 'to.txt'));
    }

    public function testDeleteDir(): void
    {
        $this->expectException(OssException::class);
        self::assertFalse($this->ossAdapter->deleteDir('path'));
    }

    public function testWriteStream(): void
    {
        self::assertFalse($this->ossAdapter->writeStream('file.txt', $this->streamFor('test')->detach(), new Config()));
    }

    public function testDelete(): void
    {
        self::assertFalse($this->ossAdapter->delete('file.txt'));
    }

    public function testWrite(): void
    {
        self::assertFalse($this->ossAdapter->write('file.txt', 'test', new Config()));
    }

    public function testRead(): void
    {
        self::assertFalse($this->ossAdapter->read('file.txt'));
    }

    public function testReadStream(): void
    {
        self::assertFalse($this->ossAdapter->readStream('file.txt'));
    }

    public function testGetVisibility(): void
    {
        self::assertFalse($this->ossAdapter->getVisibility('file.txt'));
    }

    public function testGetMetadata(): void
    {
        self::assertFalse($this->ossAdapter->getMetadata('file.txt'));
    }

    public function testListContents(): void
    {
        $this->expectException(OssException::class);
        self::assertEmpty($this->ossAdapter->listContents());
    }

    public function testGetSize(): void
    {
        self::assertFalse($this->ossAdapter->getSize('file.txt'));
    }

    public function testGetTimestamp(): void
    {
        self::assertFalse($this->ossAdapter->getTimestamp('file.txt'));
    }

    public function testGetMimetype(): void
    {
        self::assertFalse($this->ossAdapter->getMimetype('file.txt'));
    }

    public function testHas(): void
    {
        self::assertFalse($this->ossAdapter->has('file.txt'));
    }

    public function testGetUrl(): void
    {
        self::assertSame('https://test.oss-cn-shanghai.aliyuncs.com/file.txt', $this->ossAdapter->getUrl('file.txt'));
    }

    public function testSignUrl(): void
    {
        $this->ossAdapter->setBucket('');
        self::assertFalse($this->ossAdapter->signUrl('file.txt', 10, []));
    }

    public function testGetTemporaryUrl(): void
    {
        $this->ossAdapter->setBucket('');
        self::assertFalse($this->ossAdapter->getTemporaryUrl('file.txt', 10, []));
    }

    public function testSetBucket(): void
    {
        self::assertSame('test', $this->ossAdapter->getBucket());
        $this->ossAdapter->setBucket('bucket');
        self::assertSame('bucket', $this->ossAdapter->getBucket());
    }

    public function testGetClient(): void
    {
        self::assertInstanceOf(OssClient::class, $this->ossAdapter->getClient());
    }

    public function testGetUrlWithUrl(): void
    {
        $client = \Mockery::mock(OssClient::class);
        $ossAdapter = new OssAdapter($client, '', '', [
            'endpoint' => '',
            'url' => 'https://oss.cdn.com',
        ]);
        $filesystem = new Filesystem($ossAdapter);
        $filesystem->addPlugin(new FileUrl());
        self::assertSame('https://oss.cdn.com/test', $filesystem->getUrl('test'));
    }

    public function testGetUrlWithBucketEndpoint(): void
    {
        $client = \Mockery::mock(OssClient::class);
        $ossAdapter = new OssAdapter($client, '', '', [
            'endpoint' => 'https://oss.cdn.com',
            'bucket_endpoint' => true,
        ]);
        $filesystem = new Filesystem($ossAdapter);
        $filesystem->addPlugin(new FileUrl());
        self::assertSame('https://oss.cdn.com/test', $filesystem->getUrl('test'));
    }

    public function testGetTemporaryUrlWithUrl(): void
    {
        $ossAdapter = new OssAdapter($this->ossClient, 'test', '', [
            'endpoint' => 'https://oss.cdn.com',
            'temporary_url' => 'https://oss.cdn.com',
        ]);
        $filesystem = new Filesystem($ossAdapter);
        $filesystem->addPlugin(new TemporaryUrl());
        self::assertStringStartsWith('https://oss.cdn.com/test', (string) $filesystem->getTemporaryUrl('test', 10));
    }
}
