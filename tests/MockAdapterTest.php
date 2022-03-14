<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss\Tests;

use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\Visibility;
use Mockery;
use OSS\Model\ObjectInfo;
use OSS\Model\ObjectListInfo;
use OSS\Model\PrefixInfo;
use OSS\OssClient;
use Zing\Flysystem\Oss\OssAdapter;

/**
 * @internal
 */
final class MockAdapterTest extends TestCase
{
    /**
     * @var \Mockery\LegacyMockInterface
     */
    private $legacyMock;

    /**
     * @var \Zing\Flysystem\Oss\OssAdapter
     */
    private $ossAdapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->legacyMock = Mockery::mock(OssClient::class);
        $this->ossAdapter = new OssAdapter($this->legacyMock, 'test');
        $this->mockPutObject('fixture/read.txt', 'read-test');
        $this->ossAdapter->write('fixture/read.txt', 'read-test', new Config());
    }

    /**
     * @param resource|string $body
     */
    private function mockPutObject(string $path, $body, ?string $visibility = null): void
    {
        $options = [
            OssClient::OSS_CONTENT_TYPE => 'text/plain',
        ];
        if ($visibility !== null) {
            $options[OssClient::OSS_HEADERS] =
                [
                    OssClient::OSS_OBJECT_ACL => $visibility === Visibility::PUBLIC ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE,
                ];
        }

        $arg = ['test', $path, \is_resource($body) ? stream_get_contents($body) : $body, $options];
        if (\is_resource($body)) {
            rewind($body);
        }

        $this->legacyMock->shouldReceive('putObject')
            ->withArgs($arg)
            ->andReturn(null);
    }

    public function testCopy(): void
    {
        $this->mockPutObject('file.txt', 'write');
        $this->ossAdapter->write('file.txt', 'write', new Config());
        $this->legacyMock->shouldReceive('copyObject')
            ->withArgs(['test', 'file.txt', 'test', 'copy.txt', []])->andReturn(null);
        $this->mockGetVisibility('file.txt', Visibility::PUBLIC);
        $this->ossAdapter->copy('file.txt', 'copy.txt', new Config());
        $this->mockGetObject('copy.txt', 'write');
        self::assertSame('write', $this->ossAdapter->read('copy.txt'));
    }

    public function testCopyFailed(): void
    {
        $this->mockPutObject('file.txt', 'write');
        $this->ossAdapter->write('file.txt', 'write', new Config());
        $this->legacyMock->shouldReceive('copyObject')
            ->withArgs([
                'test',
                'file.txt',
                'test',
                'copy.txt',
                [],
            ])->andThrow(new \OSS\Core\OssException('mock test'));
        $this->mockGetVisibility('file.txt', Visibility::PUBLIC);
        $this->expectException(UnableToCopyFile::class);
        $this->ossAdapter->copy('file.txt', 'copy.txt', new Config());
        $this->mockGetObject('copy.txt', 'write');
        self::assertSame('write', $this->ossAdapter->read('copy.txt'));
    }

    private function mockGetObject(string $path, string $body): void
    {
        $this->legacyMock->shouldReceive('getObject')
            ->withArgs(['test', $path])->andReturn($body);
    }

    public function testCreateDir(): void
    {
        $this->legacyMock->shouldReceive('putObject')
            ->withArgs([
                'test',
                'path/',
                '',
                [
                    OssClient::OSS_HEADERS => [
                        OssClient::OSS_OBJECT_ACL => 'public-read',
                    ],
                ],
            ])->andReturn(null);
        $this->legacyMock->shouldReceive('listObjects')
            ->twice()
            ->withArgs([
                'test', [
                    'delimiter' => '/',
                    'prefix' => 'path/',
                    'max-keys' => 1,
                ],
            ])->andReturn(new ObjectListInfo('test', 'path/', '', '', '1000', '/', null, [
                new ObjectInfo('path/', '', '', '', '', ''),
            ], []), new ObjectListInfo('test', 'path/', '', '', '1000', '/', null, [], []));
        $this->legacyMock->shouldReceive('listObjects')
            ->once()
            ->withArgs([
                'test', [
                    'delimiter' => '',
                    'prefix' => 'path/',
                    'max-keys' => 1000,
                    'marker' => '',
                ],
            ])->andReturn(new ObjectListInfo('test', 'path/', '', '', '1000', '/', null, [
                new ObjectInfo('path/', '', '', '', '', ''),
            ], []));
        $this->legacyMock->shouldReceive('listObjects')
            ->once()
            ->withArgs([
                'test', [
                    'delimiter' => '/',
                    'prefix' => 'path/',
                    'max-keys' => 1000,
                    'marker' => '',
                ],
            ])
            ->andReturn(new ObjectListInfo('test', 'path/', '', '', '1000', '/', null, [
                new ObjectInfo('path/', '', '', '', '', ''),
            ], []));
        $this->legacyMock->shouldReceive('deleteObjects')
            ->once()
            ->withArgs(['test', ['path/']]);
        $this->ossAdapter->createDirectory('path', new Config());
        self::assertTrue($this->ossAdapter->directoryExists('path'));
        self::assertSame([], iterator_to_array($this->ossAdapter->listContents('path', false)));
        $this->ossAdapter->deleteDirectory('path');
        self::assertFalse($this->ossAdapter->directoryExists('path'));
    }

    public function testSetVisibility(): void
    {
        $this->mockPutObject('file.txt', 'write');
        $this->ossAdapter->write('file.txt', 'write', new Config());
        $this->legacyMock->shouldReceive('getObjectAcl')
            ->withArgs(['test', 'file.txt'])
            ->andReturns('private', 'public');
        self::assertSame(Visibility::PRIVATE, $this->ossAdapter->visibility('file.txt')->visibility());
        $this->legacyMock->shouldReceive('putObjectAcl')
            ->withArgs(['test', 'file.txt', 'public-read'])->andReturn(null);
        $this->ossAdapter->setVisibility('file.txt', Visibility::PUBLIC);

        self::assertSame(Visibility::PUBLIC, $this->ossAdapter->visibility('file.txt')['visibility']);
    }

    public function testRename(): void
    {
        $this->mockPutObject('from.txt', 'write');
        $this->ossAdapter->write('from.txt', 'write', new Config());
        $this->legacyMock->shouldReceive('doesObjectExist')
            ->once()
            ->withArgs(['test', 'from.txt'])->andReturn(true);
        self::assertTrue($this->ossAdapter->fileExists('from.txt'));
        $this->legacyMock->shouldReceive('doesObjectExist')
            ->once()
            ->withArgs(['test', 'to.txt'])->andThrow(new \OSS\Core\OssException('mock test'));
        $this->expectException(UnableToCheckFileExistence::class);
        $this->ossAdapter->fileExists('to.txt');
        $this->legacyMock->shouldReceive('copyObject')
            ->withArgs(['test', 'from.txt', 'test', 'to.txt', []])->andReturn(null);
        $this->legacyMock->shouldReceive('deleteObject')
            ->withArgs(['test', 'from.txt'])->andReturn(null);
        $this->mockGetVisibility('from.txt', Visibility::PUBLIC);
        $this->ossAdapter->move('from.txt', 'to.txt', new Config());
        $this->legacyMock->shouldReceive('doesObjectExist')
            ->withArgs(['test', 'from.txt'])->andThrow(new \OSS\Core\OssException('mock test'));
        self::assertFalse($this->ossAdapter->fileExists('from.txt'));
        $this->mockGetObject('to.txt', 'write');
        self::assertSame('write', $this->ossAdapter->read('to.txt'));
        $this->legacyMock->shouldReceive('deleteObject')
            ->withArgs(['test', 'to.txt'])->andReturn(null);
        $this->ossAdapter->delete('to.txt');
    }

    public function testDeleteDir(): void
    {
        $this->legacyMock->shouldReceive('listObjects')
            ->withArgs([
                'test', [
                    'prefix' => 'path/',
                    'max-keys' => 1000,
                    'marker' => '',
                    'delimiter' => '',
                ],
            ])->andReturn(new ObjectListInfo('test', '', '', '', '', '', null, [
                new ObjectInfo('path/', '', '', '', '', ''),
                new ObjectInfo('path/file.txt', '', '', '', '', ''),
            ], []));
        $this->legacyMock->shouldReceive('deleteObjects')
            ->once()
            ->withArgs(['test', ['path/', 'path/file.txt']])
            ->andReturn(null);
        $this->legacyMock->shouldReceive('deleteObjects')
            ->once()
            ->withArgs(['test', ['path/', 'path/file.txt']])
            ->andThrow(new \OSS\Core\OssException('mock test'));
        $this->ossAdapter->deleteDirectory('path');
        $this->expectException(UnableToDeleteDirectory::class);
        $this->ossAdapter->deleteDirectory('path');
        self::assertTrue(true);
    }

    public function testWriteStream(): void
    {
        $contents = $this->streamForResource('write');
        $this->mockPutObject('file.txt', $contents);
        $this->ossAdapter->writeStream('file.txt', $contents, new Config());
        $this->mockGetObject('file.txt', 'write');
        self::assertSame('write', $this->ossAdapter->read('file.txt'));
    }

    /**
     * @return \Iterator<string[]>
     */
    public function provideVisibilities(): \Iterator
    {
        yield [Visibility::PUBLIC];
        yield [Visibility::PRIVATE];
    }

    private function mockGetVisibility(string $path, string $visibility): void
    {
        $this->legacyMock->shouldReceive('getObjectAcl')
            ->withArgs(['test', $path])
            ->andReturn(
                $visibility === Visibility::PUBLIC ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE
            );
    }

    /**
     * @dataProvider provideVisibilities
     */
    public function testWriteStreamWithVisibility(string $visibility): void
    {
        $contents = $this->streamForResource('write');
        $this->mockPutObject('file.txt', $contents, $visibility);
        $this->ossAdapter->writeStream('file.txt', $contents, new Config([
            'visibility' => $visibility,
        ]));
        $this->mockGetVisibility('file.txt', $visibility);
        self::assertSame($visibility, $this->ossAdapter->visibility('file.txt')['visibility']);
    }

    public function testWriteStreamWithExpires(): void
    {
        $contents = $this->streamForResource('write');
        $this->legacyMock->shouldReceive('putObject')
            ->withArgs([
                'test',
                'file.txt',
                stream_get_contents($contents), [
                    'Content-Type' => 'text/plain',
                    'Expires' => 20,
                ],
            ])->andReturn(null);
        rewind($contents);
        $this->ossAdapter->writeStream('file.txt', $contents, new Config([
            'Expires' => 20,
        ]));
        $this->mockGetObject('file.txt', 'write');
        self::assertSame('write', $this->ossAdapter->read('file.txt'));
    }

    public function testWriteStreamWithMimetype(): void
    {
        $contents = $this->streamForResource('write');
        $this->legacyMock->shouldReceive('putObject')
            ->withArgs([
                'test',
                'file.txt', stream_get_contents($contents), [
                    OssClient::OSS_CONTENT_TYPE => 'image/png',
                ],
            ])->andReturn(null);
        rewind($contents);
        $this->ossAdapter->writeStream('file.txt', $contents, new Config([
            OssClient::OSS_CONTENT_TYPE => 'image/png',
        ]));
        $this->legacyMock->shouldReceive('getObjectMeta')
            ->once()
            ->withArgs(['test', 'file.txt'])->andReturn([
                'last-modified' => 'Mon, 31 May 2021 06:52:32 GMT',
                'content-type' => 'image/png',
                'content-length' => '9',
            ]);
        self::assertSame('image/png', $this->ossAdapter->mimeType('file.txt')['mime_type']);
    }

    public function testDelete(): void
    {
        $contents = $this->streamForResource('write');
        $this->mockPutObject('file.txt', $contents);
        $this->ossAdapter->writeStream('file.txt', $contents, new Config());
        $this->legacyMock->shouldReceive('doesObjectExist')
            ->once()
            ->withArgs(['test', 'file.txt'])->andReturn(true);
        self::assertTrue($this->ossAdapter->fileExists('file.txt'));
        $this->legacyMock->shouldReceive('deleteObject')
            ->withArgs(['test', 'file.txt'])->andReturn(null);
        $this->ossAdapter->delete('file.txt');
        $this->legacyMock->shouldReceive('doesObjectExist')
            ->withArgs(['test', 'file.txt'])->andThrow(new \OSS\Core\OssException('mock test'));
        $this->expectException(UnableToCheckFileExistence::class);
        $this->ossAdapter->fileExists('file.txt');
    }

    public function testWrite(): void
    {
        $this->mockPutObject('file.txt', 'write');
        $this->ossAdapter->write('file.txt', 'write', new Config());
        $this->mockGetObject('file.txt', 'write');
        self::assertSame('write', $this->ossAdapter->read('file.txt'));
    }

    public function testRead(): void
    {
        $this->mockGetObject('fixture/read.txt', 'read-test');
        self::assertSame('read-test', $this->ossAdapter->read('fixture/read.txt'));
    }

    public function testReadStream(): void
    {
        $this->mockGetObject('fixture/read.txt', 'read-test');

        self::assertSame('read-test', stream_get_contents($this->ossAdapter->readStream('fixture/read.txt')));
    }

    public function testGetVisibility(): void
    {
        $this->legacyMock->shouldReceive('getObjectAcl')
            ->withArgs(['test', 'fixture/read.txt'])
            ->andReturn(OssClient::OSS_ACL_TYPE_PRIVATE);
        self::assertSame(Visibility::PRIVATE, $this->ossAdapter->visibility('fixture/read.txt')['visibility']);
    }

    private function mockGetMetadata(string $path): void
    {
        $this->legacyMock->shouldReceive('getObjectMeta')
            ->once()
            ->withArgs(['test', $path])->andReturn([
                'last-modified' => 'Mon, 31 May 2021 06:52:32 GMT',
                'content-type' => 'text/plain',
                'content-length' => '9',
            ]);
    }

    private function mockGetEmptyMetadata(string $path): void
    {
        $this->legacyMock->shouldReceive('getObjectMeta')
            ->once()
            ->withArgs(['test', $path])->andReturn([
                'la' => 1,
            ]);
    }

    public function testListContents(): void
    {
        $this->legacyMock->shouldReceive('listObjects')
            ->withArgs([
                'test', [
                    'Delimiter' => '/',
                    'Prefix' => 'path/',
                    'MaxKeys' => 1000,
                    'Marker' => '',
                ],
            ])->andReturn(null);
        $this->legacyMock->shouldReceive('getObjectMeta')
            ->withArgs(['test', 'path/'])->andReturn([
                'ContentLength' => 0,
                'Date' => 'Mon, 31 May 2021 06:52:32 GMT',
                'RequestId' => '00000179C13207EF9217A7F5589D2DC6',
                'Id2' => '32AAAQAAEAABAAAQAAEAABAAAQAAEAABCSvXM+dHYwFYYJv2m9y5LibcMVibe3QN',
                'Reserved' => 'amazon, aws and amazon web services are trademarks or registered trademarks of Amazon Technologies, Inc',
                'Expiration' => '',
                'LastModified' => 'Mon, 31 May 2021 06:52:31 GMT',
                'ContentType' => 'binary/octet-stream',
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
            ]);
        self::assertNotEmpty($this->ossAdapter->listContents('path', false));
        $this->legacyMock->shouldReceive('listObjects')
            ->withArgs([
                'test', [
                    'prefix' => 'path1/',
                    'max-keys' => 1000,
                    'marker' => '',
                    'delimiter' => '/',
                ],
            ])->andReturn(new ObjectListInfo('test', 'path1/', '', '', '1000', '/', null, [], []));
        self::assertEmpty(iterator_to_array($this->ossAdapter->listContents('path1', false)));
        $this->mockPutObject('a/b/file.txt', 'test');
        $this->ossAdapter->write('a/b/file.txt', 'test', new Config());
        $this->legacyMock->shouldReceive('listObjects')
            ->withArgs([
                'test', [
                    'prefix' => 'a/',
                    'max-keys' => 1000,
                    'marker' => '',
                    'delimiter' => '',
                ],
            ])->andReturn(new ObjectListInfo('test', 'a/', '', '', '1000', '/', null, [
                new ObjectInfo(
                    'a/b/file.txt',
                    '2021-05-31T15:23:24.217Z',
                    'd41d8cd98f00b204e9800998ecf8427e',
                    '',
                    '9',
                    'STANDARD_IA'
                ),
            ], [new PrefixInfo('a/b/')]));
        $this->legacyMock->shouldReceive('listObjects')
            ->withArgs([
                'test', [
                    'prefix' => 'a/',
                    'max-keys' => 1000,
                    'marker' => '',
                    'delimiter' => '',
                ],
            ])->andReturn(new ObjectListInfo('test', 'a/', '', '', '1000', '/', null, [
                new ObjectInfo(
                    'a/b/file.txt',
                    '2021-05-31T15:23:24.217Z',
                    'd41d8cd98f00b204e9800998ecf8427e',
                    '',
                    '9',
                    'STANDARD_IA'
                ),
            ], []));
        $this->mockGetMetadata('a/b/file.txt');
        $contents = iterator_to_array($this->ossAdapter->listContents('a', true));
        self::assertContainsOnlyInstancesOf(StorageAttributes::class, $contents);
        self::assertCount(2, $contents);

        /** @var \League\Flysystem\FileAttributes $file */
        $file = $contents[0];
        self::assertInstanceOf(FileAttributes::class, $file);
        self::assertSame('a/b/file.txt', $file->path());
        self::assertSame(9, $file->fileSize());

        self::assertNull($file->mimeType());
        self::assertSame(1622474604, $file->lastModified());
        self::assertNull($file->visibility());
        self::assertSame([
            'x-oss-storage-class' => 'STANDARD_IA',
            'etag' => 'd41d8cd98f00b204e9800998ecf8427e',
        ], $file->extraMetadata());

        /** @var \League\Flysystem\DirectoryAttributes $directory */
        $directory = $contents[1];
        self::assertInstanceOf(DirectoryAttributes::class, $directory);
        self::assertSame('a/b', $directory->path());
    }

    public function testGetSize(): void
    {
        $this->mockGetMetadata('fixture/read.txt');
        self::assertSame(9, $this->ossAdapter->fileSize('fixture/read.txt')->fileSize());
    }

    public function testGetSizeError(): void
    {
        $this->mockGetEmptyMetadata('fixture/read.txt');
        $this->expectException(UnableToRetrieveMetadata::class);
        self::assertSame(9, $this->ossAdapter->fileSize('fixture/read.txt')->fileSize());
    }

    public function testGetTimestamp(): void
    {
        $this->mockGetMetadata('fixture/read.txt');
        self::assertSame(1622443952, $this->ossAdapter->lastModified('fixture/read.txt')->lastModified());
    }

    public function testGetTimestampError(): void
    {
        $this->mockGetEmptyMetadata('fixture/read.txt');
        $this->expectException(UnableToRetrieveMetadata::class);
        self::assertSame(1622443952, $this->ossAdapter->lastModified('fixture/read.txt')->lastModified());
    }

    public function testGetMimetype(): void
    {
        $this->mockGetMetadata('fixture/read.txt');
        self::assertSame('text/plain', $this->ossAdapter->mimeType('fixture/read.txt')->mimeType());
    }

    public function testGetMimetypeError(): void
    {
        $this->mockGetEmptyMetadata('fixture/read.txt');
        $this->expectException(UnableToRetrieveMetadata::class);
        self::assertSame('text/plain', $this->ossAdapter->mimeType('fixture/read.txt')->mimeType());
    }

    public function testGetMetadataError(): void
    {
        $this->mockGetEmptyMetadata('fixture/');
        $this->expectException(UnableToRetrieveMetadata::class);
        self::assertSame('text/plain', $this->ossAdapter->mimeType('fixture/')->mimeType());
    }

    public function testHas(): void
    {
        $this->legacyMock->shouldReceive('doesObjectExist')
            ->withArgs(['test', 'fixture/read.txt'])->andReturn(true);
        self::assertTrue($this->ossAdapter->fileExists('fixture/read.txt'));
    }

    public function testGetTemporaryUrl(): void
    {
        $this->legacyMock->shouldReceive('signUrl')
            ->withArgs(['test', 'fixture/read.txt', 10, 'GET', []])->andReturn('signed-url');
        self::assertSame('signed-url', $this->ossAdapter->getTemporaryUrl('fixture/read.txt', 10, []));
    }

    public function testDirectoryExists(): void
    {
        $this->legacyMock->shouldReceive('listObjects')
            ->withArgs([
                'test', [
                    'prefix' => 'fixture/exists-directory/',
                    'delimiter' => '/',
                    'max-keys' => 1,
                ],
            ])->andReturn(
                new ObjectListInfo('test', 'fixture/exists-directory/', '', '', '1000', '/', null, [], []),
                new ObjectListInfo('test', 'fixture/exists-directory/', '', '', '1000', '/', null, [
                    new ObjectInfo('fixture/exists-directory/', '', '', '', '', ''),
                ], [])
            );
        $this->legacyMock->shouldReceive('putObject')
            ->withArgs([
                'test',
                'fixture/exists-directory/',
                null, [
                    OssClient::OSS_HEADERS => [
                        OssClient::OSS_OBJECT_ACL => 'public-read',
                    ],
                ],
            ])->andReturn(null);
        self::assertFalse($this->ossAdapter->directoryExists('fixture/exists-directory'));
        $this->ossAdapter->createDirectory('fixture/exists-directory', new Config());
        self::assertTrue($this->ossAdapter->directoryExists('fixture/exists-directory'));
    }
}
