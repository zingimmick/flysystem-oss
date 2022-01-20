<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss\Plugins;

use League\Flysystem\Plugin\AbstractPlugin;

class SignUrl extends AbstractPlugin
{
    /**
     * sign url.
     */
    public function getMethod(): string
    {
        return 'signUrl';
    }

    /**
     * handle.
     *
     * @param $path
     * @param \DateTimeInterface|int $expiration
     * @param mixed $method
     *
     * @return mixed
     */
    public function handle($path, $expiration, array $options = [], $method = 'GET')
    {
        return $this->filesystem->getAdapter()
            ->signUrl($path, $expiration, $options, $method);
    }
}
