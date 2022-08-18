<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss;

use DateTimeInterface;
use GuzzleHttp\Psr7\Uri;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\Util;
use OSS\Core\OssException;
use OSS\OssClient;
use Psr\Http\Message\UriInterface;

class OssAdapter extends AbstractAdapter
{
    /**
     * @var string
     */
    private const DELIMITER = '/';

    /**
     * @var int
     */
    private const MAX_KEYS = 1000;

    /**
     * @var string[]
     */
    private const AVAILABLE_OPTIONS = [
        OssClient::OSS_REQUEST_PAYER,
        OssClient::OSS_OBJECT_ACL,
        OssClient::OSS_EXPIRES,
        OssClient::OSS_CACHE_CONTROL,
        OssClient::OSS_CONTENT_DISPOSTION,
        OssClient::OSS_TRAFFIC_LIMIT,
        OssClient::OSS_CONTENT_TYPE,
        OssClient::OSS_CONTENT_MD5,
        OssClient::OSS_CONTENT_LENGTH,
        'x-oss-storage-class',
        'x-oss-tagging',
        'Content-Encoding',
        'Content-Language',
        'x-oss-server-side-encryption',
        'x-oss-meta-self-define-title',
        'x-oss-forbid-overwrite',
        'x-oss-server-side-data-encryption',
        'x-oss-server-side-encryption-key-id',
    ];

    /**
     * @var string[]
     */
    private const MUP_AVAILABLE_OPTIONS = [
        OssClient::OSS_CALLBACK,
        OssClient::OSS_CALLBACK_VAR,
        OssClient::OSS_CONTENT_TYPE,
        OssClient::OSS_LENGTH,
        OssClient::OSS_CHECK_MD5,
        OssClient::OSS_HEADERS,
    ];

    /**
     * @var string
     */
    protected $bucket;

    /**
     * @var mixed[]|array<string, bool>|array<string, string>
     * @phpstan-var array{url?: string, temporary_url?: string, endpoint?: string, bucket_endpoint?: bool}
     */
    protected $options = [];

    /**
     * @var \OSS\OssClient
     */
    protected $client;

    /**
     * @param array{url?: string, temporary_url?: string, endpoint?: string, bucket_endpoint?: bool} $options
     */
    public function __construct(OssClient $client, string $bucket, string $prefix = '', array $options = [])
    {
        $this->client = $client;
        $this->bucket = $bucket;
        $this->setPathPrefix($prefix);
        $this->options = $options;
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    public function setBucket(string $bucket): void
    {
        $this->bucket = $bucket;
    }

    public function getClient(): OssClient
    {
        return $this->client;
    }

    public function kernel(): OssClient
    {
        return $this->getClient();
    }

    public function write($path, $contents, Config $config): bool
    {
        return $this->upload($path, $contents, $config);
    }

    /**
     * @param resource $resource
     * @param mixed $path
     */
    public function writeStream($path, $resource, Config $config): bool
    {
        return $this->upload($path, $resource, $config);
    }

    private function upload(string $path, $contents, Config $config): bool
    {
        $options = $this->createOptionsFromConfig($config);
        if (! isset($options[OssClient::OSS_HEADERS][OssClient::OSS_OBJECT_ACL])) {
            /** @var string|null $visibility */
            $visibility = $config->get('visibility');
            if ($visibility !== null) {
                $options[OssClient::OSS_HEADERS][OssClient::OSS_OBJECT_ACL] = $options[OssClient::OSS_OBJECT_ACL] ?? ($visibility === self::VISIBILITY_PUBLIC ? OssClient::OSS_ACL_TYPE_PUBLIC_READ : OssClient::OSS_ACL_TYPE_PRIVATE);
            }
        }

        $shouldDetermineMimetype = $contents !== '' && ! \array_key_exists(OssClient::OSS_CONTENT_TYPE, $options);

        if ($shouldDetermineMimetype) {
            $mimeType = Util::guessMimeType($path, $contents);
            if ($mimeType) {
                $options[OssClient::OSS_CONTENT_TYPE] = $mimeType;
            }
        }

        try {
            if (\is_string($contents)) {
                $this->client->putObject($this->bucket, $this->applyPathPrefix($path), $contents, $options);
            } else {
                $this->client->uploadStream($this->bucket, $this->applyPathPrefix($path), $contents, $options);
            }
        } catch (OssException $ossException) {
            return false;
        }

        return true;
    }

    public function rename($path, $newpath): bool
    {
        if (! $this->copy($path, $newpath)) {
            return false;
        }

        return $this->delete($path);
    }

    public function delete($path): bool
    {
        try {
            $this->client->deleteObject($this->bucket, $this->applyPathPrefix($path));
        } catch (OssException $ossException) {
            return false;
        }

        return ! $this->has($path);
    }

    public function copy($path, $newpath): bool
    {
        try {
            $this->client->copyObject(
                $this->bucket,
                $this->applyPathPrefix($path),
                $this->bucket,
                $this->applyPathPrefix($newpath),
                $this->options
            );
        } catch (OssException $ossException) {
            return false;
        }

        return true;
    }

    /**
     * @param $path
     * @param $visibility
     *
     * @return array|false
     */
    public function setVisibility($path, $visibility)
    {
        try {
            $this->client->putObjectAcl(
                $this->bucket,
                $this->applyPathPrefix($path),
                $this->visibilityToAcl($visibility)
            );
        } catch (OssException $ossException) {
            return false;
        }

        return [
            'path' => $path,
            'visibility' => $visibility,
        ];
    }

    /**
     * @param $path
     *
     * @return array|false
     */
    public function read($path)
    {
        $response = $this->getObject($path);
        if ($response === false) {
            return false;
        }

        return [
            'path' => $path,
            'contents' => $response,
        ];
    }

    /**
     * @param mixed $path
     *
     * @return array|false
     */
    public function readStream($path)
    {
        /** @var resource $stream */
        $stream = fopen('php://temp', 'w+b');

        try {
            $this->client->getObject($this->bucket, $this->applyPathPrefix($path), [
                OssClient::OSS_FILE_DOWNLOAD => $stream,
            ]);
            rewind($stream);

            return [
                'path' => $path,
                'stream' => $stream,
            ];
        } catch (OssException $ossException) {
            return false;
        }
    }

    /**
     * @param mixed $directory
     * @param mixed $recursive
     *
     * @return array<int, mixed[]>
     */
    public function listContents($directory = '', $recursive = false): array
    {
        $directory = rtrim($directory, '/');
        $result = $this->listDirObjects($directory, $recursive);
        $list = [];
        foreach ($result['objects'] as $files) {
            $path = $this->removePathPrefix(rtrim((string) ($files['key'] ?? $files['prefix']), '/'));
            if ($path === $directory) {
                continue;
            }

            $list[] = $this->mapObjectMetadata($files);
        }

        foreach ($result['prefix'] as $dir) {
            $list[] = [
                'type' => 'dir',
                'path' => $this->removePathPrefix(rtrim($dir, '/')),
            ];
        }

        return $list;
    }

    /**
     * @param $path
     *
     * @return array|false
     */
    public function getMetadata($path)
    {
        try {
            /** @var array{key?: string, prefix: ?string, content-length?: string, size?: int, last-modified?: string, content-type?: string} $metadata */
            $metadata = $this->client->getObjectMeta($this->bucket, $this->applyPathPrefix($path));
        } catch (OssException $ossException) {
            return false;
        }

        return $this->mapObjectMetadata($metadata, $path);
    }

    /**
     * @param array{key?: string, prefix: ?string, content-length?: string, size?: int, last-modified?: string, content-type?: string} $metadata
     */
    private function mapObjectMetadata(array $metadata, ?string $path = null): array
    {
        if ($path === null) {
            $path = $this->removePathPrefix((string) ($metadata['key'] ?? $metadata['prefix']));
        }

        if (substr($path, -1) === '/') {
            return [
                'type' => 'dir',
                'path' => rtrim($path, '/'),
            ];
        }

        $dateTime = isset($metadata['last-modified']) ? strtotime($metadata['last-modified']) : null;
        $lastModified = $dateTime ?: null;

        return [
            'type' => 'file',
            'mimetype' => $metadata['content-type'] ?? null,
            'path' => $path,
            'timestamp' => $lastModified,
            'size' => isset($metadata['content-length']) ? (int) $metadata['content-length'] : ($metadata['size'] ?? null),
        ];
    }

    /**
     * Read an object from the OssClient.
     */
    protected function getObject(string $path)
    {
        try {
            return $this->client->getObject($this->bucket, $this->applyPathPrefix($path));
        } catch (OssException $ossException) {
            return false;
        }
    }

    /**
     * File list core method.
     *
     * @return array{prefix: array<string>, objects: array<array{key?: string, prefix: ?string, content-length?: string, size?: int, last-modified?: string, content-type?: string}>}
     */
    public function listDirObjects(string $dirname = '', bool $recursive = false): array
    {
        $prefix = trim($this->applyPathPrefix($dirname), '/');
        $prefix = empty($prefix) ? '' : $prefix . '/';

        $nextMarker = '';

        $result = [];

        $options = [
            'prefix' => $prefix,
            'max-keys' => self::MAX_KEYS,
            'delimiter' => $recursive ? '' : self::DELIMITER,
        ];
        while (true) {
            $options['marker'] = $nextMarker;
            $model = $this->client->listObjects($this->bucket, $options);
            $nextMarker = $model->getNextMarker();
            $objects = $model->getObjectList();
            $prefixes = $model->getPrefixList();
            $result = $this->processObjects($result, $objects, $dirname);

            $result = $this->processPrefixes($result, $prefixes);
            if ($nextMarker === '') {
                break;
            }
        }

        return $result;
    }

    /**
     * @param array{prefix?: array<string>, objects?: array<array{key?: string, prefix: string|null, content-length?: string, size?: int, last-modified?: string, content-type?: string}>} $result
     * @param array<\OSS\Model\ObjectInfo>|null $objects
     *
     * @return array{prefix?: array<string>, objects: array<array{key?: string, prefix: string|null, content-length?: string, size?: int, last-modified?: string, content-type?: string}>}
     */
    private function processObjects(array $result, ?array $objects, string $dirname): array
    {
        $result['objects'] = [];
        if (! empty($objects)) {
            foreach ($objects as $object) {
                $result['objects'][] = [
                    'prefix' => $dirname,
                    'key' => $object->getKey(),
                    'last-modified' => $object->getLastModified(),
                    'size' => $object->getSize(),
                    OssClient::OSS_ETAG => $object->getETag(),
                    'x-oss-storage-class' => $object->getStorageClass(),
                ];
            }
        } else {
            $result['objects'] = [];
        }

        return $result;
    }

    /**
     * @param array{prefix?: array<string>, objects: array<array{key?: string, prefix: string|null, content-length?: string, size?: int, last-modified?: string, content-type?: string}>} $result
     * @param array<\OSS\Model\PrefixInfo>|null $prefixes
     *
     * @return array{prefix: array<string>, objects: array<array{key?: string, prefix: string|null, content-length?: string, size?: int, last-modified?: string, content-type?: string}>}
     */
    private function processPrefixes(array $result, ?array $prefixes): array
    {
        if (! empty($prefixes)) {
            foreach ($prefixes as $prefix) {
                $result['prefix'][] = $prefix->getPrefix();
            }
        } else {
            $result['prefix'] = [];
        }

        return $result;
    }

    /**
     * Get options from the config.
     *
     * @return array<string, mixed>
     */
    protected function createOptionsFromConfig(Config $config): array
    {
        $options = $this->options;
        $mimeType = $config->get('mimetype');
        if ($mimeType) {
            $options[OssClient::OSS_CONTENT_TYPE] = $mimeType;
        }

        foreach (self::AVAILABLE_OPTIONS as $option) {
            $value = $config->get($option, '__NOT_SET__');

            if ($value !== '__NOT_SET__') {
                $options[OssClient::OSS_HEADERS][$option] = $value;
            }
        }

        foreach (self::MUP_AVAILABLE_OPTIONS as $option) {
            $value = $config->get($option, '__NOT_SET__');

            if ($value !== '__NOT_SET__') {
                $options[$option] = $value;
            }
        }

        return $options;
    }

    /**
     * Get the URL for the file at the given path.
     */
    public function getUrl(string $path): string
    {
        if (isset($this->options['url'])) {
            return $this->concatPathToUrl($this->options['url'], $this->applyPathPrefix($path));
        }

        return $this->concatPathToUrl($this->normalizeHost(), $this->applyPathPrefix($path));
    }

    protected function normalizeHost(): string
    {
        if (! isset($this->options['endpoint'])) {
            throw UnableToGetUrl::missingOption('endpoint');
        }

        $endpoint = $this->options['endpoint'];
        if (strpos($endpoint, 'http') !== 0) {
            $endpoint = 'https://' . $endpoint;
        }

        /** @var array{scheme: string, host: string} $url */
        $url = parse_url($endpoint);
        $domain = $url['host'];
        if (! ($this->options['bucket_endpoint'] ?? false)) {
            $domain = $this->bucket . '.' . $domain;
        }

        $domain = sprintf('%s://%s', $url['scheme'], $domain);

        return rtrim($domain, '/') . '/';
    }

    /**
     * Get a signed URL for the file at the given path.
     *
     * @param \DateTimeInterface|int $expiration
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function signUrl(string $path, $expiration, array $options = [], string $method = 'GET')
    {
        $expires = $expiration instanceof DateTimeInterface ? $expiration->getTimestamp() - time() : $expiration;

        try {
            return $this->client->signUrl(
                $this->bucket,
                $this->applyPathPrefix($path),
                $expires,
                $method,
                $options
            );
        } catch (OssException $ossException) {
            return false;
        }
    }

    /**
     * Get a temporary URL for the file at the given path.
     *
     * @param \DateTimeInterface|int $expiration
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function getTemporaryUrl(string $path, $expiration, array $options = [], string $method = 'GET')
    {
        $signedUrl = $this->signUrl($path, $expiration, $options, $method);
        if ($signedUrl === false) {
            return false;
        }

        $uri = new Uri($signedUrl);

        if (isset($this->options['temporary_url'])) {
            $uri = $this->replaceBaseUrl($uri, $this->options['temporary_url']);
        }

        return (string) $uri;
    }

    /**
     * Concatenate a path to a URL.
     */
    protected function concatPathToUrl(string $url, string $path): string
    {
        return rtrim($url, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Replace the scheme, host and port of the given UriInterface with values from the given URL.
     */
    protected function replaceBaseUrl(UriInterface $uri, string $url): UriInterface
    {
        /** @var array{scheme: string, host: string, port?: int} $parsed */
        $parsed = parse_url($url);

        return $uri
            ->withScheme($parsed['scheme'])
            ->withHost($parsed['host'])
            ->withPort($parsed['port'] ?? null);
    }

    public function update($path, $contents, Config $config): bool
    {
        return $this->upload($path, $contents, $config);
    }

    public function updateStream($path, $resource, Config $config): bool
    {
        return $this->upload($path, $resource, $config);
    }

    public function deleteDir($dirname): bool
    {
        $result = $this->listDirObjects($dirname, true);
        $keys = array_column($result['objects'], 'key');
        if ($keys === []) {
            return true;
        }

        try {
            foreach (array_chunk($keys, 1000) as $items) {
                $this->client->deleteObjects($this->bucket, $items);
            }
        } catch (OssException $ossException) {
            return false;
        }

        return true;
    }

    public function createDir($dirname, Config $config): bool
    {
        return $this->upload(rtrim($dirname, '/') . '/', '', $config);
    }

    public function has($path): bool
    {
        $location = $this->applyPathPrefix($path);

        try {
            if ($this->client->doesObjectExist($this->bucket, $location)) {
                return true;
            }

            return $this->client->doesObjectExist($this->bucket, rtrim($location, '/') . '/');
        } catch (OssException $ossException) {
            return false;
        }
    }

    /**
     * @param $path
     *
     * @return array|false
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * @param $path
     *
     * @return array|false
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * @param $path
     *
     * @return array|false
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * @param $path
     *
     * @return false|string[]
     */
    public function getVisibility($path)
    {
        try {
            $result = $this->client->getObjectAcl($this->bucket, $this->applyPathPrefix($path));
        } catch (OssException $ossException) {
            return false;
        }

        return [
            'visibility' => $this->aclToVisibility($result),
        ];
    }

    public function visibilityToAcl(string $visibility): string
    {
        if ($visibility === AdapterInterface::VISIBILITY_PUBLIC) {
            return OssClient::OSS_ACL_TYPE_PUBLIC_READ;
        }

        return OssClient::OSS_ACL_TYPE_PRIVATE;
    }

    public function aclToVisibility(string $acl): string
    {
        switch ($acl) {
            case OssClient::OSS_ACL_TYPE_PRIVATE:
                return AdapterInterface::VISIBILITY_PRIVATE;

            case OssClient::OSS_ACL_TYPE_PUBLIC_READ:
            case OssClient::OSS_ACL_TYPE_PUBLIC_READ_WRITE:
                return AdapterInterface::VISIBILITY_PUBLIC;

            default:
                return $this->options['default_visibility'] ?? AdapterInterface::VISIBILITY_PUBLIC;
        }
    }
}
