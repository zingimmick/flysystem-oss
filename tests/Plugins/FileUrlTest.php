<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss\Tests\Plugins;

use League\Flysystem\Filesystem;
use Mockery;
use Zing\Flysystem\Oss\OssAdapter;
use Zing\Flysystem\Oss\Plugins\FileUrl;
use Zing\Flysystem\Oss\Tests\TestCase;

/**
 * @internal
 */
final class FileUrlTest extends TestCase
{
    public function testGetUrl(): void
    {
        $adapter = Mockery::mock(OssAdapter::class);
        $adapter->shouldReceive('getUrl')
            ->withArgs(['test'])->once()->andReturn('test-url');
        $filesystem = new Filesystem($adapter);
        $filesystem->addPlugin(new FileUrl());
        self::assertSame('test-url', $filesystem->getUrl('test'));
    }
}
