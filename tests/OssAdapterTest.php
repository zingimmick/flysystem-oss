<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss\Tests;

use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use OSS\Credentials\StaticCredentialsProvider;
use OSS\OssClient;
use Zing\Flysystem\Oss\OssAdapter;

/**
 * @internal
 */
final class OssAdapterTest extends FilesystemAdapterTestCase
{
    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        $config = [
            'provider' => new StaticCredentialsProvider((string) getenv('OSS_KEY') ?: '', (string) getenv(
                'OSS_SECRET'
            ) ?: ''),
            'bucket' => (string) getenv('OSS_BUCKET') ?: '',
            'endpoint' => (string) getenv('OSS_ENDPOINT') ?: 'oss-cn-shanghai.aliyuncs.com',
            'path_style' => '',
            'region' => '',
        ];

        return new OssAdapter(new OssClient($config), $config['bucket'] ?: '', 'github-test', null, null, [
            'endpoint' => $config['endpoint'],
        ]);
    }

    private FilesystemAdapter $filesystemAdapter;

    protected function setUp(): void
    {
        if ((string) getenv('MOCK') !== 'false') {
            $this->markTestSkipped('Mock tests enabled');
        }

        $this->filesystemAdapter = self::createFilesystemAdapter();

        parent::setUp();
    }

    public function adapter(): FilesystemAdapter
    {
        return $this->filesystemAdapter;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
if ((string) getenv('MOCK') === 'false') {
        $adapter = $this->adapter();
        $adapter->deleteDirectory('/');

        /** @var \League\Flysystem\StorageAttributes[] $listing */
        $listing = $adapter->listContents('', false);

        foreach ($listing as $singleListing) {
            if ($singleListing->isFile()) {
                $adapter->delete($singleListing->path());
            } else {
                $adapter->deleteDirectory($singleListing->path());
            }
        }}
    }

    /**
     * @test
     */
    public function fetching_unknown_mime_type_of_a_file(): void
    {
        $this->adapter()
            ->write('unknown-mime-type.md5', '', new Config());

        $this->runScenario(function (): void {
            $this->assertSame('application/octet-stream', $this->adapter()
                ->mimeType('unknown-mime-type.md5')
                ->mimeType());
        });
    }
}
