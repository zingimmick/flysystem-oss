<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss\Tests\Plugins;

use League\Flysystem\Filesystem;
use Mockery;
use Zing\Flysystem\Oss\OssAdapter;
use Zing\Flysystem\Oss\Plugins\TemporaryUrl;
use Zing\Flysystem\Oss\Tests\TestCase;

/**
 * @internal
 */
final class TemporaryUrlTest extends TestCase
{
    public function testGetTemporaryUrl(): void
    {
        $adapter = Mockery::mock(OssAdapter::class);
        $adapter->shouldReceive('getTemporaryUrl')
            ->withArgs(['test', 10, [], 'GET'])->once()->andReturn('test-url');
        $filesystem = new Filesystem($adapter);
        $filesystem->addPlugin(new TemporaryUrl());
        self::assertSame('test-url', $filesystem->getTemporaryUrl('test', 10));
    }
}
