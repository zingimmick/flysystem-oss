<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss\Tests\Plugins;

use League\Flysystem\Filesystem;
use Mockery;
use Zing\Flysystem\Oss\OssAdapter;
use Zing\Flysystem\Oss\Plugins\SignUrl;
use Zing\Flysystem\Oss\Tests\TestCase;

/**
 * @internal
 */
final class SignUrlTest extends TestCase
{
    public function testSignUrl(): void
    {
        $adapter = Mockery::mock(OssAdapter::class);
        $adapter->shouldReceive('signUrl')
            ->withArgs(['test', 10, [], 'GET'])->once()->andReturn('test-url');
        $filesystem = new Filesystem($adapter);
        $filesystem->addPlugin(new SignUrl());
        self::assertSame('test-url', $filesystem->signUrl('test', 10));
    }
}
