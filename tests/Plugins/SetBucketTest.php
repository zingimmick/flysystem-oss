<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss\Tests\Plugins;

use League\Flysystem\Filesystem;
use Mockery;
use Zing\Flysystem\Oss\OssAdapter;
use Zing\Flysystem\Oss\Plugins\SetBucket;
use Zing\Flysystem\Oss\Tests\TestCase;

/**
 * @internal
 */
final class SetBucketTest extends TestCase
{
    public function testSetBucket(): void
    {
        $adapter = Mockery::mock(OssAdapter::class);
        $adapter->shouldReceive('setBucket')
            ->withArgs(['test'])->once()->passthru();
        $adapter->shouldReceive('getBucket')
            ->withNoArgs()
            ->once()
            ->passthru();
        $filesystem = new Filesystem($adapter);
        $filesystem->addPlugin(new SetBucket());
        $filesystem->bucket('test');
        self::assertSame('test', $adapter->getBucket());
    }
}
