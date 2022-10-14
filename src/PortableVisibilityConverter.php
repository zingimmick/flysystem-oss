<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss;

use League\Flysystem\Visibility;
use OSS\OssClient;

class PortableVisibilityConverter implements VisibilityConverter
{
    /**
     * @var string
     */
    private const PUBLIC_ACL = OssClient::OSS_ACL_TYPE_PUBLIC_READ;

    /**
     * @var string
     */
    private const PRIVATE_ACL = OssClient::OSS_ACL_TYPE_PRIVATE;

    public function __construct(
        private string $default = Visibility::PUBLIC,
        private string $defaultForDirectories = Visibility::PUBLIC
    ) {
    }

    public function visibilityToAcl(string $visibility): string
    {
        if ($visibility === Visibility::PUBLIC) {
            return self::PUBLIC_ACL;
        }

        return self::PRIVATE_ACL;
    }

    public function aclToVisibility(string $acl): string
    {
        return match ($acl) {
            OssClient::OSS_ACL_TYPE_PRIVATE => Visibility::PRIVATE,
            OssClient::OSS_ACL_TYPE_PUBLIC_READ, OssClient::OSS_ACL_TYPE_PUBLIC_READ_WRITE => Visibility::PUBLIC,
            default => $this->default,
        };
    }

    public function defaultForDirectories(): string
    {
        return $this->defaultForDirectories;
    }

    public function getDefault(): string
    {
        return $this->default;
    }
}
