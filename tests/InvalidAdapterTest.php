<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss\Tests;

use League\Flysystem\Config;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use OSS\Core\OssException;
use OSS\OssClient;
use Zing\Flysystem\Oss\OssAdapter;
use Zing\Flysystem\Oss\UnableToGetUrl;

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

    private OssAdapter $ossAdapter;

    private OssClient $ossClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ossClient = new OssClient(self::CONFIG['key'], self::CONFIG['secret'], self::CONFIG['endpoint']);
        $this->ossAdapter = new OssAdapter($this->ossClient, self::CONFIG['bucket'], '');
    }

    public function testCopy(): void
    {
        $this->expectException(UnableToCopyFile::class);
        $this->ossAdapter->copy('file.txt', 'copy.txt', new Config());
    }

    public function testCreateDir(): void
    {
        $this->expectException(UnableToCreateDirectory::class);
        $this->ossAdapter->createDirectory('path', new Config());
    }

    public function testSetVisibility(): void
    {
        $this->expectException(UnableToSetVisibility::class);
        $this->ossAdapter->setVisibility('file.txt', Visibility::PUBLIC);
    }

    public function testRename(): void
    {
        $this->expectException(UnableToMoveFile::class);
        $this->ossAdapter->move('from.txt', 'to.txt', new Config());
    }

    public function testDeleteDir(): void
    {
        $this->expectException(OssException::class);
        $this->ossAdapter->deleteDirectory('path');
    }

    public function testWriteStream(): void
    {
        $this->expectException(UnableToWriteFile::class);
        $this->ossAdapter->writeStream('file.txt', $this->streamForResource('test'), new Config());
    }

    public function testDelete(): void
    {
        $this->expectException(UnableToDeleteFile::class);
        $this->ossAdapter->delete('file.txt');
    }

    public function testWrite(): void
    {
        $this->expectException(UnableToWriteFile::class);
        $this->ossAdapter->write('file.txt', 'test', new Config());
    }

    public function testRead(): void
    {
        $this->expectException(UnableToReadFile::class);
        $this->ossAdapter->read('file.txt');
    }

    public function testReadStream(): void
    {
        $this->expectException(UnableToReadFile::class);
        $this->ossAdapter->readStream('file.txt');
    }

    public function testGetVisibility(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);
        $this->ossAdapter->visibility('file.txt')
            ->visibility();
    }

    public function testListContents(): void
    {
        $this->expectException(OssException::class);
        $this->assertEmpty(iterator_to_array($this->ossAdapter->listContents('/', false)));
    }

    public function testGetSize(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);
        $this->ossAdapter->fileSize('file.txt')
            ->fileSize();
    }

    public function testGetTimestamp(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);
        $this->ossAdapter->lastModified('file.txt')
            ->lastModified();
    }

    public function testGetMimetype(): void
    {
        $this->expectException(UnableToRetrieveMetadata::class);
        $this->ossAdapter->mimeType('file.txt')
            ->mimeType();
    }

    public function testHas(): void
    {
        $this->expectException(UnableToCheckFileExistence::class);
        $this->ossAdapter->fileExists('file.txt');
    }

    public function testBucket(): void
    {
        $ossAdapter = new OssAdapter($this->ossClient, self::CONFIG['bucket'], '', null, null, [
            'endpoint' => 'http://oss.cdn.com',
        ]);
        $this->assertSame('test', $ossAdapter->getBucket());
    }

    public function testSetBucket(): void
    {
        $ossAdapter = new OssAdapter($this->ossClient, self::CONFIG['bucket'], '', null, null, [
            'endpoint' => 'http://oss.cdn.com',
        ]);
        $ossAdapter->setBucket('new-bucket');
        $this->assertSame('new-bucket', $ossAdapter->getBucket());
    }

    public function testGetUrl(): void
    {
        $ossAdapter = new OssAdapter($this->ossClient, self::CONFIG['bucket'], '', null, null, [
            'endpoint' => 'http://oss.cdn.com',
        ]);
        $this->assertSame('http://test.oss.cdn.com/test', $ossAdapter->getUrl('test'));
    }

    public function testGetClient(): void
    {
        $ossAdapter = new OssAdapter($this->ossClient, self::CONFIG['bucket'], '', null, null, [
            'endpoint' => 'http://oss.cdn.com',
        ]);
        $this->assertSame($this->ossClient, $ossAdapter->getClient());
        $this->assertSame($this->ossClient, $ossAdapter->kernel());
    }

    public function testGetUrlWithoutSchema(): void
    {
        $ossAdapter = new OssAdapter($this->ossClient, self::CONFIG['bucket'], '', null, null, [
            'endpoint' => 'oss.cdn.com',
        ]);
        $this->assertSame('https://test.oss.cdn.com/test', $ossAdapter->getUrl('test'));
    }

    public function testGetUrlWithoutEndpoint(): void
    {
        $ossAdapter = new OssAdapter($this->ossClient, self::CONFIG['bucket'], '');
        $this->expectException(UnableToGetUrl::class);
        $this->expectExceptionMessage('Unable to get url with option endpoint missing.');
        $ossAdapter->getUrl('test');
    }

    public function testGetUrlWithUrl(): void
    {
        $ossAdapter = new OssAdapter($this->ossClient, self::CONFIG['bucket'], '', null, null, [
            'endpoint' => 'https://oss.cdn.com',
            'url' => 'https://oss.cdn.com',
        ]);
        $this->assertSame('https://oss.cdn.com/test', $ossAdapter->getUrl('test'));
    }

    public function testGetUrlWithBucketEndpoint(): void
    {
        $ossAdapter = new OssAdapter($this->ossClient, self::CONFIG['bucket'], '', null, null, [
            'endpoint' => 'https://oss.cdn.com',
            'bucket_endpoint' => true,
        ]);
        $this->assertSame('https://oss.cdn.com/test', $ossAdapter->getUrl('test'));
    }

    public function testGetTemporaryUrlWithUrl(): void
    {
        $ossAdapter = new OssAdapter($this->ossClient, self::CONFIG['bucket'], '', null, null, [
            'endpoint' => 'https://oss.cdn.com',
            'temporary_url' => 'https://oss.cdn.com',
        ]);
        $this->assertStringStartsWith('https://oss.cdn.com/test', $ossAdapter->getTemporaryUrl('test', 10));
    }

    public function testDirectoryExists(): void
    {
        if (! class_exists(UnableToCheckDirectoryExistence::class)) {
            $this->markTestSkipped('Require League Flysystem v3');
        }

        $this->expectException(UnableToCheckDirectoryExistence::class);
        $this->ossAdapter->directoryExists('path');
    }
}
