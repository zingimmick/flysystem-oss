<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss\Tests;

use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use OSS\Model\ObjectInfo;
use OSS\Model\ObjectListInfo;
use OSS\Model\PrefixInfo;
use OSS\OssClient;
use Rector\Core\ValueObject\Visibility;
use Zing\Flysystem\Oss\OssAdapter;

/**
 * @internal
 */
final class MockAdapterTest extends TestCase
{
    /**
     * @var \Mockery\MockInterface&\OSS\OssClient
     */
    private $client;

    /**
     * @var \Zing\Flysystem\Oss\OssAdapter
     */
    private $ossAdapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = \Mockery::mock(OssClient::class);
        $this->ossAdapter = new OssAdapter($this->client, 'test', '', [
            'endpoint' => 'oss-cn-shanghai.aliyuncs.com',
        ]);
        $this->client->shouldReceive('putObject')
            ->withArgs([
                'test', 'fixture/read.txt', 'read-test', [
                    'endpoint' => 'oss-cn-shanghai.aliyuncs.com',
                    'Content-Type' => 'text/plain',
                ],
            ])->andReturn(([]));
        $this->ossAdapter->write('fixture/read.txt', 'read-test', new Config());
    }

    public function testUpdate(): void
    {
        $this->client->shouldReceive('putObject')
            ->withArgs([
                'test', 'file.txt', 'update', [
                    'endpoint' => 'oss-cn-shanghai.aliyuncs.com',
                    'Content-Type' => 'text/plain',
                ],
            ])->andReturn(null);
        $this->ossAdapter->update('file.txt', 'update', new Config());
        $this->client->shouldReceive('getObject')
            ->withArgs(['test', 'file.txt'])->andReturn('update');
        self::assertSame('update', $this->ossAdapter->read('file.txt')['contents']);
    }

    public function testUpdateStream(): void
    {
        $this->client->shouldReceive('putObject')
            ->withArgs([
                'test', 'file.txt', 'write', [
                    'endpoint' => 'oss-cn-shanghai.aliyuncs.com',
                    'Content-Type' => 'text/plain',
                ],
            ])->andReturn(null);
        $this->ossAdapter->write('file.txt', 'write', new Config());
        $this->client->shouldReceive('putObject')
            ->withArgs([
                'test', 'file.txt', 'update', [
                    'endpoint' => 'oss-cn-shanghai.aliyuncs.com',
                    'Content-Type' => 'text/plain',
                ],
            ])->andReturn(null);
        $this->ossAdapter->updateStream('file.txt', $this->streamFor('update')->detach(), new Config());
        $this->client->shouldReceive('getObject')
            ->withArgs(['test', 'file.txt'])->andReturn('update');
        self::assertSame('update', $this->ossAdapter->read('file.txt')['contents']);
    }

    private function mockPutObject(string $path, string $body, ?string $visibility = null): void
    {
        $arg = ['test', $path, $body];
        if ($visibility !== null) {
            $arg[] = [
                'endpoint' => 'oss-cn-shanghai.aliyuncs.com',
                'Content-Type' => 'text/plain',
                'headers' => [
                    OssClient::OSS_OBJECT_ACL => $visibility === AdapterInterface::VISIBILITY_PUBLIC ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : 'private',
                ],
            ];
        } else {
            $arg[] = [
                'endpoint' => 'oss-cn-shanghai.aliyuncs.com',
                'Content-Type' => 'text/plain',
            ];
        }

        $this->client->shouldReceive('putObject')
            ->withArgs($arg)
            ->andReturn(null);
    }

    public function testCopy(): void
    {
        $this->mockPutObject('file.txt', 'write');
        $this->ossAdapter->write('file.txt', 'write', new Config());
        $this->client->shouldReceive('copyObject')
            ->withArgs([
                'test', 'file.txt', 'test', 'copy.txt', [
                    'endpoint' => 'oss-cn-shanghai.aliyuncs.com',
                ],
            ])->andReturn(null);
        $this->mockGetVisibility('file.txt', Visibility::PUBLIC);
        $this->ossAdapter->copy('file.txt', 'copy.txt');
        $this->mockGetObject('copy.txt', 'write');
        self::assertSame('write', $this->ossAdapter->read('copy.txt')['contents']);
    }

    private function mockGetObject(string $path, string $body): void
    {
        $this->client->shouldReceive('getObject')
            ->withArgs(['test', $path])->andReturn($body);
    }

    public function testCreateDir(): void
    {
        $this->client->shouldReceive('putObject')
            ->withArgs([
                'test', 'path/', '', [
                    'endpoint' => 'oss-cn-shanghai.aliyuncs.com',
                ],
            ])->andReturn(null);
        $this->ossAdapter->createDir('path', new Config());
        $this->client->shouldReceive('listObjects')
            ->withArgs(['test', [
                'prefix' => 'path/',
                'max-keys' => 1000,
                'delimiter' => '/',
                'marker' => '',
            ],
            ])->andReturn(new ObjectListInfo('test', 'path/', '', '', '1000', '/', null, [
                new ObjectInfo(
                    'path/',
                    '2021-05-31T06:52:31.942Z',
                    'd41d8cd98f00b204e9800998ecf8427e',
                    'Normal',
                    0,
                    'STANDARD_IA'
                ),
            ], []));
        $this->client->shouldReceive('getObjectMetadata')
            ->withArgs([['test', 'path/']])->andReturn(([
                'ContentLength' => 0,
                'Date' => 'Mon, 31 May 2021 06:52:32 GMT',
                'RequestId' => '00000179C13207EF9217A7F5589D2DC6',
                'Id2' => '32AAAQAAEAABAAAQAAEAABAAAQAAEAABCSvXM+dHYwFYYJv2m9y5LibcMVibe3QN',
                'Reserved' => 'amazon, aws and amazon web services are trademarks or registered trademarks of Amazon Technologies, Inc',
                'Expiration' => '',
                'last-modified' => 'Mon, 31 May 2021 06:52:31 GMT',
                'content-type' => 'binary/octet-stream',
                'ETag' => 'd41d8cd98f00b204e9800998ecf8427e',
                'VersionId' => '',
                'WebsiteRedirectLocation' => '',
                'StorageClass' => 'STANDARD_IA',
                'AllowOrigin' => '',
                'MaxAgeSeconds' => '',
                'ExposeHeader' => '',
                'AllowMethod' => '',
                'AllowHeader' => '',
                'Restore' => '',
                'SseKms' => '',
                'SseKmsKey' => '',
                'SseC' => '',
                'SseCKeyMd5' => '',
                'Metadata' => [],
                'HttpStatusCode' => 200,
                'Reason' => 'OK',
            ]));
        self::assertSame([], $this->ossAdapter->listContents('path'));
    }

    public function testSetVisibility(): void
    {
        $this->client->shouldReceive('putObject')
            ->withArgs([
                'test', 'file.txt', 'write', [
                    'endpoint' => 'oss-cn-shanghai.aliyuncs.com',
                    'Content-Type' => 'text/plain',
                ],
            ])->andReturn(null);
        $this->ossAdapter->write('file.txt', 'write', new Config());
        $this->client->shouldReceive('getObjectAcl')
            ->withArgs(['test', 'file.txt'])
            ->andReturns(OssClient::OSS_ACL_TYPE_PRIVATE, OssClient::OSS_ACL_TYPE_PUBLIC_READ);
        self::assertSame(
            AdapterInterface::VISIBILITY_PRIVATE,
            $this->ossAdapter->getVisibility('file.txt')['visibility']
        );
        $this->client->shouldReceive('putObjectAcl')
            ->withArgs(['test', 'file.txt', 'public-read'])->andReturn(([
                'ContentLength' => '0',
                'Date' => 'Mon, 31 May 2021 06:52:31 GMT',
                'RequestId' => '00000179C132053492179E666378BF10',
                'Id2' => '32AAAUgAIAABAAAQAAEAABAAAQAAEAABCSFbUsDzX172DxJwfaphYILIunSuoAAR',
                'Reserved' => 'amazon, aws and amazon web services are trademarks or registered trademarks of Amazon Technologies, Inc',
                'HttpStatusCode' => 200,
                'Reason' => 'OK',
            ]));
        $this->ossAdapter->setVisibility('file.txt', AdapterInterface::VISIBILITY_PUBLIC);

        self::assertSame(
            AdapterInterface::VISIBILITY_PUBLIC,
            $this->ossAdapter->getVisibility('file.txt')['visibility']
        );
    }

    public function testRename(): void
    {
        $this->mockPutObject('from.txt', 'write');
        $this->ossAdapter->write('from.txt', 'write', new Config());
        $this->mockGetMetadata('from.txt');
        $this->client->shouldReceive('doesObjectExist')
            ->once()
            ->withArgs(['test', 'from.txt'])->andReturn(true);
        self::assertTrue($this->ossAdapter->has('from.txt'));
        $this->client->shouldReceive('doesObjectExist')
            ->once()
            ->withArgs(['test', 'to.txt'])->andThrow(new \OSS\Core\OssException(''));
        self::assertFalse($this->ossAdapter->has('to.txt'));
        $this->client->shouldReceive('copyObject')
            ->withArgs([
                'test', 'from.txt', 'test', 'to.txt', [
                    'endpoint' => 'oss-cn-shanghai.aliyuncs.com',
                ],
            ])->andReturn(null);
        $this->client->shouldReceive('deleteObject')
            ->withArgs(['test', 'from.txt'])->andReturn(null);
        $this->mockGetVisibility('from.txt', Visibility::PUBLIC);
        $this->ossAdapter->rename('from.txt', 'to.txt');
        $this->client->shouldReceive('doesObjectExist')
            ->once()
            ->withArgs(['test', 'from.txt'])->andThrow(new \OSS\Core\OssException(''));
        self::assertFalse($this->ossAdapter->has('from.txt'));
        $this->mockGetObject('to.txt', 'write');
        self::assertSame('write', $this->ossAdapter->read('to.txt')['contents']);
        $this->client->shouldReceive('deleteObject')
            ->withArgs(['test', 'to.txt'])->andReturn(null);
        $this->ossAdapter->delete('to.txt');
    }

    public function testDeleteDir(): void
    {
        $this->client->shouldReceive('listObjects')
            ->withArgs(['test', [
                'prefix' => 'path/',
                'max-keys' => 1000,
                'delimiter' => '',
                'marker' => '',
            ],
            ])->andReturn(new ObjectListInfo('test', 'path/', '', '', '1000', '', null, [
                new ObjectInfo(
                    'path/',
                    '2021-05-31T06:52:31.942Z',
                    '"d41d8cd98f00b204e9800998ecf8427e"',
                    'Normal',
                    0,
                    'STANDARD_IA'
                ), new ObjectInfo(
                    'path/file.txt',
                    '2021-05-31T06:52:32.001Z',
                    '"098f6bcd4621d373cade4e832627b4f6"',
                    'Normal',
                    4,
                    'STANDARD_IA'
                ),
            ], []));
        $this->mockGetMetadata('path/');
        $this->mockGetMetadata('path/file.txt');
        $this->client->shouldReceive('doesObjectExist')
            ->withArgs(['test', 'path'])->andThrow(new \OSS\Core\OssException(''));
        $this->client->shouldReceive('doesObjectExist')
            ->withArgs(['test', 'path/'])->andThrow(new \OSS\Core\OssException(''));
        $this->client->shouldReceive('deleteObjects')
            ->withArgs(['test', ['path/', 'path/file.txt']])->andReturn(null);
        $this->client->shouldReceive('doesObjectExist')
            ->once()
            ->withArgs(['test', 'path/file.txt'])->andReturn(false);
        $this->client->shouldReceive('doesObjectExist')
            ->once()
            ->withArgs(['test', 'path/file.txt/'])->andReturn(false);
        self::assertTrue($this->ossAdapter->deleteDir('path'));
    }

    public function testWriteStream(): void
    {
        $this->mockPutObject('file.txt', 'write');
        $this->ossAdapter->writeStream('file.txt', $this->streamFor('write')->detach(), new Config());
        $this->mockGetObject('file.txt', 'write');
        self::assertSame('write', $this->ossAdapter->read('file.txt')['contents']);
    }

    /**
     * @return \Iterator<string[]>
     */
    public function provideVisibilities(): \Iterator
    {
        yield [AdapterInterface::VISIBILITY_PUBLIC];

        yield [AdapterInterface::VISIBILITY_PRIVATE];
    }

    private function mockGetVisibility(string $path, $visibility): void
    {
        $this->client->shouldReceive('getObjectAcl')
            ->withArgs(['test', $path])
            ->andReturn(
                $visibility === AdapterInterface::VISIBILITY_PRIVATE ? OssClient::OSS_ACL_TYPE_PRIVATE : OssClient::OSS_ACL_TYPE_PUBLIC_READ
            );
    }

    /**
     * @dataProvider provideVisibilities
     *
     * @param $visibility
     */
    public function testWriteStreamWithVisibility(string $visibility): void
    {
        $this->mockPutObject('file.txt', 'write', $visibility);
        $this->ossAdapter->writeStream('file.txt', $this->streamFor('write')->detach(), new Config([
            'visibility' => $visibility,
        ]));
        $this->mockGetVisibility('file.txt', $visibility);
        self::assertSame($visibility, $this->ossAdapter->getVisibility('file.txt')['visibility']);
    }

    public function testWriteStreamWithExpires(): void
    {
        $this->client->shouldReceive('putObject')
            ->withArgs([
                'test', 'file.txt', 'write', [
                    'endpoint' => 'oss-cn-shanghai.aliyuncs.com',
                    'headers' => [
                        'Expires' => 20,
                    ],
                    'Content-Type' => 'text/plain',
                ],
            ])->andReturn(null);
        $this->ossAdapter->writeStream('file.txt', $this->streamFor('write')->detach(), new Config([
            'Expires' => 20,
        ]));
        $this->mockGetObject('file.txt', 'write');
        self::assertSame('write', $this->ossAdapter->read('file.txt')['contents']);
    }

    public function testWriteStreamWithMimetype(): void
    {
        $this->client->shouldReceive('putObject')
            ->withArgs([
                'test', 'file.txt', 'write', [
                    'endpoint' => 'oss-cn-shanghai.aliyuncs.com',
                    'Content-Type' => 'image/png',
                ],
            ])->andReturn(null);
        $this->ossAdapter->writeStream('file.txt', $this->streamFor('write')->detach(), new Config([
            'mimetype' => 'image/png',
        ]));
        $this->client->shouldReceive('getObjectMeta')
            ->once()
            ->withArgs(['test', 'file.txt'])->andReturn(([
                'content-length' => 9,

                'last-modified' => 'Mon, 31 May 2021 06:52:32 GMT',
                'content-type' => 'image/png',
            ]));
        self::assertSame('image/png', $this->ossAdapter->getMimetype('file.txt')['mimetype']);
    }

    public function testDelete(): void
    {
        $this->mockPutObject('file.txt', 'write');
        $this->ossAdapter->writeStream('file.txt', $this->streamFor('write')->detach(), new Config());
        $this->client->shouldReceive('doesObjectExist')
            ->once()
            ->withArgs(['test', 'file.txt'])->andReturn(true);
        self::assertTrue($this->ossAdapter->has('file.txt'));
        $this->client->shouldReceive('deleteObject')
            ->withArgs(['test', 'file.txt'])->andReturn(null);
        $this->ossAdapter->delete('file.txt');
        $this->client->shouldReceive('doesObjectExist')
            ->once()
            ->withArgs(['test', 'file.txt'])->andThrow(new \OSS\Core\OssException(''));
        self::assertFalse($this->ossAdapter->has('file.txt'));
    }

    public function testWrite(): void
    {
        $this->mockPutObject('file.txt', 'write');
        $this->ossAdapter->write('file.txt', 'write', new Config());
        $this->mockGetObject('file.txt', 'write');
        self::assertSame('write', $this->ossAdapter->read('file.txt')['contents']);
    }

    public function testRead(): void
    {
        $this->client->shouldReceive('getObject')
            ->withArgs(['test', 'fixture/read.txt'])->andReturn('read-test');
        self::assertSame('read-test', $this->ossAdapter->read('fixture/read.txt')['contents']);
    }

    public function testReadStream(): void
    {
        $this->client->shouldReceive('getObject')
            ->withArgs(['test', 'fixture/read.txt'])->andReturn('read-test');
        self::assertSame('read-test', stream_get_contents($this->ossAdapter->readStream('fixture/read.txt')['stream']));
    }

    public function testGetVisibility(): void
    {
        $this->client->shouldReceive('getObjectAcl')
            ->withArgs(['test', 'fixture/read.txt'])
            ->andReturn(OssClient::OSS_ACL_TYPE_PRIVATE);
        self::assertSame(
            AdapterInterface::VISIBILITY_PRIVATE,
            $this->ossAdapter->getVisibility('fixture/read.txt')['visibility']
        );
    }

    public function testGetMetadata(): void
    {
        $this->mockGetMetadata('fixture/read.txt');
        self::assertIsArray($this->ossAdapter->getMetadata('fixture/read.txt'));
    }

    private function mockGetMetadata(string $path): void
    {
        $this->client->shouldReceive('getObjectMeta')
            ->once()
            ->withArgs(['test', $path])->andReturn(([
                'content-length' => 9,

                'last-modified' => 'Mon, 31 May 2021 06:52:32 GMT',
                'content-type' => 'text/plain',
            ]));
    }

    public function testListContents(): void
    {
        $this->client->shouldReceive('listObjects')
            ->withArgs(['test', [
                'prefix' => 'path/',
                'max-keys' => 1000,
                'delimiter' => '/',
                'marker' => '',
            ],
            ])->andReturn(
                new ObjectListInfo('test', 'path/', '', '', '1000', '/', null, [
                    new ObjectInfo(
                        'path/file.txt',
                        'Mon, 31 May 2021 06:52:32 GMT',
                        'd41d8cd98f00b204e9800998ecf8427e',
                        'Normal',
                        9,
                        'STANDARD_IA'
                    ),
                ], [])
            );
        $this->client->shouldReceive('getObjectMetadata')
            ->withArgs([['test', 'path/']])->andReturn(([
                'ContentLength' => 0,
                'Date' => 'Mon, 31 May 2021 06:52:32 GMT',
                'RequestId' => '00000179C13207EF9217A7F5589D2DC6',
                'Id2' => '32AAAQAAEAABAAAQAAEAABAAAQAAEAABCSvXM+dHYwFYYJv2m9y5LibcMVibe3QN',
                'Reserved' => 'amazon, aws and amazon web services are trademarks or registered trademarks of Amazon Technologies, Inc',
                'Expiration' => '',
                'last-modified' => 'Mon, 31 May 2021 06:52:31 GMT',
                'content-type' => 'binary/octet-stream',
                'ETag' => 'd41d8cd98f00b204e9800998ecf8427e',
                'VersionId' => '',
                'WebsiteRedirectLocation' => '',
                'StorageClass' => 'STANDARD_IA',
                'AllowOrigin' => '',
                'MaxAgeSeconds' => '',
                'ExposeHeader' => '',
                'AllowMethod' => '',
                'AllowHeader' => '',
                'Restore' => '',
                'SseKms' => '',
                'SseKmsKey' => '',
                'SseC' => '',
                'SseCKeyMd5' => '',
                'Metadata' => [],
                'HttpStatusCode' => 200,
                'Reason' => 'OK',
            ]));
        self::assertNotEmpty($this->ossAdapter->listContents('path'));
        $this->client->shouldReceive('listObjects')
            ->withArgs(['test', [
                'prefix' => 'path1/',
                'max-keys' => 1000,
                'delimiter' => '/',
                'marker' => '',
            ],
            ])->andReturn(new ObjectListInfo('test', 'path1/', '', '', '1000', '/', null, [], []));
        self::assertEmpty($this->ossAdapter->listContents('path1'));
        $this->mockPutObject('a/b/file.txt', 'test');
        $this->ossAdapter->write('a/b/file.txt', 'test', new Config());
        $this->client->shouldReceive('listObjects')
            ->withArgs([
                'test', [
                    'prefix' => 'a/',
                    'max-keys' => 1000,
                    'delimiter' => '',
                    'marker' => '',
                ],
            ])->andReturn(new ObjectListInfo('test', 'a/', '', '', '1000', '', null, [
                new ObjectInfo(
                    'a/b/file.txt',
                    'Mon, 31 May 2021 06:52:32 GMT',
                    'd41d8cd98f00b204e9800998ecf8427e',
                    'Normal',
                    9,
                    'STANDARD_IA'
                ),
            ], [new PrefixInfo('a/b/')]));

        $this->mockGetMetadata('a/b/file.txt');
        self::assertSame([
            [
                'type' => 'file',
                'mimetype' => null,
                'path' => 'a/b/file.txt',
                'timestamp' => 1622443952,
                'size' => 9,
            ], [
                'type' => 'dir',
                'path' => 'a/b',
            ],
        ], $this->ossAdapter->listContents('a', true));
    }

    public function testGetSize(): void
    {
        $this->mockGetMetadata('fixture/read.txt');
        self::assertSame(9, $this->ossAdapter->getSize('fixture/read.txt')['size']);
    }

    public function testGetTimestamp(): void
    {
        $this->mockGetMetadata('fixture/read.txt');
        self::assertSame(1622443952, $this->ossAdapter->getTimestamp('fixture/read.txt')['timestamp']);
    }

    public function testGetMimetype(): void
    {
        $this->mockGetMetadata('fixture/read.txt');
        self::assertSame('text/plain', $this->ossAdapter->getMimetype('fixture/read.txt')['mimetype']);
    }

    public function testHas(): void
    {
        $this->client->shouldReceive('doesObjectExist')
            ->withArgs(['test', 'fixture/read.txt'])->andReturn(true);
        self::assertTrue($this->ossAdapter->has('fixture/read.txt'));
    }

    public function testSignUrl(): void
    {
        $this->client->shouldReceive('signUrl')
            ->withArgs(['test', 'fixture/read.txt', 10, 'GET', []])->andReturn('signed-url');
        self::assertSame('signed-url', $this->ossAdapter->signUrl('fixture/read.txt', 10, []));
    }

    public function testGetTemporaryUrl(): void
    {
        $this->client->shouldReceive('signUrl')
            ->withArgs(['test', 'fixture/read.txt', 10, 'GET', []])->andReturn('signed-url');
        self::assertSame('signed-url', $this->ossAdapter->getTemporaryUrl('fixture/read.txt', 10, []));
    }
}
