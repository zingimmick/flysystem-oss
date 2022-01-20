<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss\Tests\Plugins;

use League\Flysystem\Filesystem;
use Mockery;
use OSS\OssClient;
use Zing\Flysystem\Oss\OssAdapter;
use Zing\Flysystem\Oss\Plugins\Kernel;
use Zing\Flysystem\Oss\Tests\TestCase;

/**
 * @internal
 */
final class KernelTest extends TestCase
{
    public function testKernel(): void
    {
        $adapter = Mockery::mock(OssAdapter::class);
        $adapter->shouldReceive('getClient')
            ->withNoArgs()
            ->once()
            ->andReturn(Mockery::mock(OssClient::class));
        $filesystem = new Filesystem($adapter);
        $filesystem->addPlugin(new Kernel());
        self::assertInstanceOf(OssClient::class, $filesystem->kernel());
    }
}
