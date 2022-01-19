<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss\Tests;

use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
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
            'key' => (string) getenv('ALIBABA_CLOUD_KEY') ?: '',
            'secret' => (string) getenv('ALIBABA_CLOUD_SECRET') ?: '',
            'bucket' => (string) getenv('ALIBABA_CLOUD_BUCKET') ?: '',
            'endpoint' => (string) getenv('ALIBABA_CLOUD_ENDPOINT') ?: 'oss-cn-shanghai.aliyuncs.com',
            'path_style' => '',
            'region' => '',
        ];

        return new OssAdapter(new OssClient($config['key'], $config['secret'], $config['endpoint']), (string) getenv(
            'ALIBABA_CLOUD_BUCKET'
        ) ?: '', 'github-test');
    }

    /**
     * @var \League\Flysystem\FilesystemAdapter
     */
    private $filesystemAdapter;

    protected function setUp(): void
    {
        if ((string) getenv('MOCK') !== 'false') {
            self::markTestSkipped('Mock tests enabled');
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
        }
    }

    /**
     * @test
     */
    public function fetching_unknown_mime_type_of_a_file(): void
    {
        $this->adapter()
            ->write('unknown-mime-type.md5', '', new Config());

        $this->runScenario(function (): void {
            self::assertSame(
                'application/octet-stream',
                $this->adapter()
                    ->mimeType('unknown-mime-type.md5')
                    ->mimeType()
            );
        });
    }
}
