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
use OSS\Core\OssException;
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
     * @var \Mockery\MockInterface&\OSS\OssClient
     */
    private $legacyMock;

    private OssAdapter $ossAdapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->legacyMock = \Mockery::mock(OssClient::class);
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

        $arg = ['test', $path, $body, $options];
        if (\is_resource($body)) {
            rewind($body);
        }

        $this->legacyMock->shouldReceive(\is_resource($body) ? 'uploadStream' : 'putObject')
            ->withArgs($arg)
            ->andReturn(null);
    }

    public function testCopy(): void
    {
        $this->mockPutObject('file.txt', 'write');
        $this->ossAdapter->write('file.txt', 'write', new Config());
        $this->legacyMock->shouldReceive('copyObject')
            ->withArgs(['test', 'file.txt', 'test', 'copy.txt', [
                'headers' => [
                    OssClient::OSS_OBJECT_ACL => OssClient::OSS_ACL_TYPE_PUBLIC_READ,
                ],
            ],
            ])->andReturn(null);
        $this->mockGetVisibility('file.txt', Visibility::PUBLIC);
        $this->ossAdapter->copy('file.txt', 'copy.txt', new Config());
        $this->mockGetObject('copy.txt', 'write');
        $this->assertSame('write', $this->ossAdapter->read('copy.txt'));
    }

    public function testCopyWithoutRetainVisibility(): void
    {
        $this->mockPutObject('file.txt', 'write');
        $this->ossAdapter->write('file.txt', 'write', new Config());
        $this->legacyMock->shouldReceive('copyObject')
            ->withArgs(['test', 'file.txt', 'test', 'copy.txt', [
                'headers' => [
                    OssClient::OSS_OBJECT_ACL => OssClient::OSS_ACL_TYPE_PRIVATE,
                ],
            ],
            ])->andReturn(null);
        $this->mockGetVisibility('file.txt', Visibility::PUBLIC);
        $this->ossAdapter->copy('file.txt', 'copy.txt', new Config([
            'retain_visibility' => false,
        ]));
        $this->mockGetVisibility('copy.txt', Visibility::PRIVATE);
        $this->assertSame(Visibility::PRIVATE, $this->ossAdapter->visibility('copy.txt')->visibility());
    }

    public function testCopyFailed(): void
    {
        $this->mockPutObject('file.txt', 'write');
        $this->ossAdapter->write('file.txt', 'write', new Config());
        $this->legacyMock->shouldReceive('copyObject')
            ->withArgs(['test', 'file.txt', 'test', 'copy.txt', [
                'headers' => [
                    OssClient::OSS_OBJECT_ACL => OssClient::OSS_ACL_TYPE_PUBLIC_READ,
                ],
            ],
            ])->andThrow(new OssException('mock test'));
        $this->mockGetVisibility('file.txt', Visibility::PUBLIC);
        $this->expectException(UnableToCopyFile::class);
        $this->ossAdapter->copy('file.txt', 'copy.txt', new Config());
        $this->mockGetObject('copy.txt', 'write');
        $this->assertSame('write', $this->ossAdapter->read('copy.txt'));
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
        $this->assertTrue($this->ossAdapter->directoryExists('path'));
        $this->assertSame([], iterator_to_array($this->ossAdapter->listContents('path', false)));
        $this->ossAdapter->deleteDirectory('path');
        $this->assertFalse($this->ossAdapter->directoryExists('path'));
    }

    public function testSetVisibility(): void
    {
        $this->mockPutObject('file.txt', 'write');
        $this->ossAdapter->write('file.txt', 'write', new Config());
        $this->legacyMock->shouldReceive('getObjectAcl')
            ->withArgs(['test', 'file.txt'])
            ->andReturns('private', 'public');
        $this->assertSame(Visibility::PRIVATE, $this->ossAdapter->visibility('file.txt')->visibility());
        $this->legacyMock->shouldReceive('putObjectAcl')
            ->withArgs(['test', 'file.txt', 'public-read'])->andReturn(null);
        $this->ossAdapter->setVisibility('file.txt', Visibility::PUBLIC);

        $this->assertSame(Visibility::PUBLIC, $this->ossAdapter->visibility('file.txt')['visibility']);
    }

    public function testRename(): void
    {
        $this->mockPutObject('from.txt', 'write');
        $this->ossAdapter->write('from.txt', 'write', new Config());
        $this->legacyMock->shouldReceive('doesObjectExist')
            ->once()
            ->withArgs(['test', 'from.txt'])->andReturn(true);
        $this->assertTrue($this->ossAdapter->fileExists('from.txt'));
        $this->legacyMock->shouldReceive('doesObjectExist')
            ->once()
            ->withArgs(['test', 'to.txt'])->andThrow(new OssException('mock test'));
        $this->expectException(UnableToCheckFileExistence::class);
        $this->ossAdapter->fileExists('to.txt');
        $this->legacyMock->shouldReceive('copyObject')
            ->withArgs(['test', 'from.txt', 'test', 'to.txt', []])->andReturn(null);
        $this->legacyMock->shouldReceive('deleteObject')
            ->withArgs(['test', 'from.txt'])->andReturn(null);
        $this->mockGetVisibility('from.txt', Visibility::PUBLIC);
        $this->ossAdapter->move('from.txt', 'to.txt', new Config());
        $this->legacyMock->shouldReceive('doesObjectExist')
            ->withArgs(['test', 'from.txt'])->andThrow(new OssException('mock test'));
        $this->assertFalse($this->ossAdapter->fileExists('from.txt'));
        $this->mockGetObject('to.txt', 'write');
        $this->assertSame('write', $this->ossAdapter->read('to.txt'));
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
            ->andThrow(new OssException('mock test'));
        $this->ossAdapter->deleteDirectory('path');
        $this->expectException(UnableToDeleteDirectory::class);
        $this->ossAdapter->deleteDirectory('path');
        $this->assertTrue(true);
    }

    public function testWriteStream(): void
    {
        $contents = $this->streamForResource('write');
        $this->mockPutObject('file.txt', $contents);
        $this->ossAdapter->writeStream('file.txt', $contents, new Config());
        $this->mockGetObject('file.txt', 'write');
        $this->assertSame('write', $this->ossAdapter->read('file.txt'));
    }

    /**
     * @return \Iterator<string[]>
     */
    public static function provideWriteStreamWithVisibilityCases(): \Iterator
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
     * @dataProvider provideWriteStreamWithVisibilityCases
     */
    public function testWriteStreamWithVisibility(string $visibility): void
    {
        $contents = $this->streamForResource('write');
        $this->mockPutObject('file.txt', $contents, $visibility);
        $this->ossAdapter->writeStream('file.txt', $contents, new Config([
            'visibility' => $visibility,
        ]));
        $this->mockGetVisibility('file.txt', $visibility);
        $this->assertSame($visibility, $this->ossAdapter->visibility('file.txt')['visibility']);
    }

    public function testWriteStreamWithExpires(): void
    {
        $contents = $this->streamForResource('write');
        $this->legacyMock->shouldReceive('uploadStream')
            ->withArgs([
                'test',
                'file.txt',
                $contents, [
                    'Content-Type' => 'text/plain',
                    OssClient::OSS_HEADERS => [
                        'Expires' => 20,
                    ],
                ],
            ])->andReturn(null);
        rewind($contents);
        $this->ossAdapter->writeStream('file.txt', $contents, new Config([
            'Expires' => 20,
        ]));
        $this->mockGetObject('file.txt', 'write');
        $this->assertSame('write', $this->ossAdapter->read('file.txt'));
    }

    public function testWriteStreamWithMimetype(): void
    {
        $contents = $this->streamForResource('write');
        $this->legacyMock->shouldReceive('uploadStream')
            ->withArgs([
                'test',
                'file.txt', $contents, [
                    OssClient::OSS_HEADERS => [
                        OssClient::OSS_CONTENT_TYPE => 'image/png',
                    ],
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
        $this->assertSame('image/png', $this->ossAdapter->mimeType('file.txt')['mime_type']);
    }

    public function testDelete(): void
    {
        $contents = $this->streamForResource('write');
        $this->mockPutObject('file.txt', $contents);
        $this->ossAdapter->writeStream('file.txt', $contents, new Config());
        $this->legacyMock->shouldReceive('doesObjectExist')
            ->once()
            ->withArgs(['test', 'file.txt'])->andReturn(true);
        $this->assertTrue($this->ossAdapter->fileExists('file.txt'));
        $this->legacyMock->shouldReceive('deleteObject')
            ->withArgs(['test', 'file.txt'])->andReturn(null);
        $this->ossAdapter->delete('file.txt');
        $this->legacyMock->shouldReceive('doesObjectExist')
            ->withArgs(['test', 'file.txt'])->andThrow(new OssException('mock test'));
        $this->expectException(UnableToCheckFileExistence::class);
        $this->ossAdapter->fileExists('file.txt');
    }

    public function testWrite(): void
    {
        $this->mockPutObject('file.txt', 'write');
        $this->ossAdapter->write('file.txt', 'write', new Config());
        $this->mockGetObject('file.txt', 'write');
        $this->assertSame('write', $this->ossAdapter->read('file.txt'));
    }

    public function testRead(): void
    {
        $this->mockGetObject('fixture/read.txt', 'read-test');
        $this->assertSame('read-test', $this->ossAdapter->read('fixture/read.txt'));
    }

    public function testReadStream(): void
    {
        $this->legacyMock->shouldReceive('getObject')
            ->withArgs(static function ($bucket, $object, array $options): bool {
                fwrite($options[OssClient::OSS_FILE_DOWNLOAD], 'read-test');

                return $bucket === 'test' && $object === 'fixture/read.txt';
            })
            ->andReturn('');

        $this->assertSame('read-test', stream_get_contents($this->ossAdapter->readStream('fixture/read.txt')));
    }

    public function testGetVisibility(): void
    {
        $this->legacyMock->shouldReceive('getObjectAcl')
            ->withArgs(['test', 'fixture/read.txt'])
            ->andReturn(OssClient::OSS_ACL_TYPE_PRIVATE);
        $this->assertSame(Visibility::PRIVATE, $this->ossAdapter->visibility('fixture/read.txt')['visibility']);
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
                    'prefix' => 'path/',
                    'max-keys' => 1000,
                    'delimiter' => '/',
                    'marker' => '',
                ],
            ])->andReturn(
                new ObjectListInfo('test', 'path/', '', '', '1000', '/', null, [], [new PrefixInfo('path/')])
            );
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
        $this->assertNotEmpty(iterator_to_array($this->ossAdapter->listContents('path', false), false));
        $this->legacyMock->shouldReceive('listObjects')
            ->withArgs([
                'test', [
                    'prefix' => 'path1/',
                    'max-keys' => 1000,
                    'marker' => '',
                    'delimiter' => '/',
                ],
            ])->andReturn(new ObjectListInfo('test', 'path1/', '', '', '1000', '/', null, [], []));
        $this->assertEmpty(iterator_to_array($this->ossAdapter->listContents('path1', false)));
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
        $this->assertContainsOnlyInstancesOf(StorageAttributes::class, $contents);
        $this->assertCount(2, $contents);

        /** @var \League\Flysystem\FileAttributes $file */
        $file = $contents[0];
        $this->assertInstanceOf(FileAttributes::class, $file);
        $this->assertSame('a/b/file.txt', $file->path());
        $this->assertSame(9, $file->fileSize());

        $this->assertNull($file->mimeType());
        $this->assertSame(1_622_474_604, $file->lastModified());
        $this->assertNull($file->visibility());
        $this->assertSame([
            'x-oss-storage-class' => 'STANDARD_IA',
            'etag' => 'd41d8cd98f00b204e9800998ecf8427e',
        ], $file->extraMetadata());

        /** @var \League\Flysystem\DirectoryAttributes $directory */
        $directory = $contents[1];
        $this->assertInstanceOf(DirectoryAttributes::class, $directory);
        $this->assertSame('a/b', $directory->path());
    }

    public function testGetSize(): void
    {
        $this->mockGetMetadata('fixture/read.txt');
        $this->assertSame(9, $this->ossAdapter->fileSize('fixture/read.txt')->fileSize());
    }

    public function testGetSizeError(): void
    {
        $this->mockGetEmptyMetadata('fixture/read.txt');
        $this->expectException(UnableToRetrieveMetadata::class);
        $this->assertSame(9, $this->ossAdapter->fileSize('fixture/read.txt')->fileSize());
    }

    public function testGetTimestamp(): void
    {
        $this->mockGetMetadata('fixture/read.txt');
        $this->assertSame(1_622_443_952, $this->ossAdapter->lastModified('fixture/read.txt')->lastModified());
    }

    public function testGetTimestampError(): void
    {
        $this->mockGetEmptyMetadata('fixture/read.txt');
        $this->expectException(UnableToRetrieveMetadata::class);
        $this->assertSame(1_622_443_952, $this->ossAdapter->lastModified('fixture/read.txt')->lastModified());
    }

    public function testGetMimetype(): void
    {
        $this->mockGetMetadata('fixture/read.txt');
        $this->assertSame('text/plain', $this->ossAdapter->mimeType('fixture/read.txt')->mimeType());
    }

    public function testGetMimetypeError(): void
    {
        $this->mockGetEmptyMetadata('fixture/read.txt');
        $this->expectException(UnableToRetrieveMetadata::class);
        $this->assertSame('text/plain', $this->ossAdapter->mimeType('fixture/read.txt')->mimeType());
    }

    public function testGetMetadataError(): void
    {
        $this->mockGetEmptyMetadata('fixture/');
        $this->expectException(UnableToRetrieveMetadata::class);
        $this->assertSame('text/plain', $this->ossAdapter->mimeType('fixture/')->mimeType());
    }

    public function testHas(): void
    {
        $this->legacyMock->shouldReceive('doesObjectExist')
            ->withArgs(['test', 'fixture/read.txt'])->andReturn(true);
        $this->assertTrue($this->ossAdapter->fileExists('fixture/read.txt'));
    }

    public function testGetTemporaryUrl(): void
    {
        $this->legacyMock->shouldReceive('signUrl')
            ->withArgs(['test', 'fixture/read.txt', 10, 'GET', []])->andReturn('signed-url');
        $this->assertSame('signed-url', $this->ossAdapter->getTemporaryUrl('fixture/read.txt', 10, []));
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
        $this->assertFalse($this->ossAdapter->directoryExists('fixture/exists-directory'));
        $this->ossAdapter->createDirectory('fixture/exists-directory', new Config());
        $this->assertTrue($this->ossAdapter->directoryExists('fixture/exists-directory'));
    }

    public function testMovingAFileWithVisibility(): void
    {
        $this->mockPutObject('source.txt', 'contents to be copied', Visibility::PUBLIC);
        $this->legacyMock->shouldReceive('copyObject')
            ->withArgs([
                'test', 'source.txt', 'test', 'destination.txt', [
                    OssClient::OSS_HEADERS => [
                        OssClient::OSS_OBJECT_ACL => OssClient::OSS_ACL_TYPE_PRIVATE,
                    ],
                ],
            ]);
        $this->legacyMock->shouldReceive('deleteObject')
            ->withArgs(['test', 'source.txt']);
        $this->legacyMock->shouldReceive('doesObjectExist')
            ->withArgs(['test', 'source.txt'])->andReturn(false);
        $this->legacyMock->shouldReceive('doesObjectExist')
            ->withArgs(['test', 'destination.txt'])->andReturn(true);
        $this->mockGetVisibility('destination.txt', Visibility::PRIVATE);
        $this->mockGetObject('destination.txt', 'contents to be copied');
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
        $this->mockPutObject('source.txt', 'contents to be copied', Visibility::PUBLIC);
        $this->legacyMock->shouldReceive('copyObject')
            ->withArgs([
                'test', 'source.txt', 'test', 'destination.txt', [
                    OssClient::OSS_HEADERS => [
                        OssClient::OSS_OBJECT_ACL => OssClient::OSS_ACL_TYPE_PRIVATE,
                    ],
                ],
            ]);
        $this->legacyMock->shouldReceive('deleteObject')
            ->withArgs(['test', 'source.txt']);
        $this->legacyMock->shouldReceive('doesObjectExist')
            ->withArgs(['test', 'source.txt'])->andReturn(true);
        $this->legacyMock->shouldReceive('doesObjectExist')
            ->withArgs(['test', 'destination.txt'])->andReturn(true);
        $this->mockGetVisibility('destination.txt', Visibility::PRIVATE);
        $this->mockGetObject('destination.txt', 'contents to be copied');
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
