<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss\Plugins;

use League\Flysystem\Plugin\AbstractPlugin;

class FileUrl extends AbstractPlugin
{
    /**
     * get file url.
     */
    public function getMethod(): string
    {
        return 'getUrl';
    }

    /**
     * handle.
     *
     * @param $path
     *
     * @return mixed
     */
    public function handle($path)
    {
        return $this->filesystem->getAdapter()
            ->getUrl($path);
    }
}
