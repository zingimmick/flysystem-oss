<?php

declare(strict_types=1);

namespace Zing\Flysystem\Oss;

use DateTimeInterface;
use GuzzleHttp\Psr7\Uri;
use League\Flysystem\ChecksumAlgoIsNotSupported;
use League\Flysystem\ChecksumProvider;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemOperationFailed;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToGeneratePublicUrl;
use League\Flysystem\UnableToGenerateTemporaryUrl;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToProvideChecksum;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UrlGeneration\PublicUrlGenerator;
use League\Flysystem\UrlGeneration\TemporaryUrlGenerator;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use OSS\Core\OssException;
use OSS\OssClient;
use Psr\Http\Message\UriInterface;
use Throwable;

class OssAdapter implements FilesystemAdapter, PublicUrlGenerator, ChecksumProvider, TemporaryUrlGenerator
{
    /**
     * @var string[]
     */
    private const EXTRA_METADATA_FIELDS = ['x-oss-storage-class', OssClient::OSS_ETAG];

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

    private PathPrefixer $pathPrefixer;

    private PortableVisibilityConverter|VisibilityConverter $visibilityConverter;

    private FinfoMimeTypeDetector|MimeTypeDetector $mimeTypeDetector;

    /**
     * @param array{url?: string, temporary_url?: string, endpoint?: string, bucket_endpoint?: bool} $options
     */
    public function __construct(
        protected OssClient $ossClient,
        protected string $bucket,
        string $prefix = '',
        ?VisibilityConverter $visibility = null,
        ?MimeTypeDetector $mimeTypeDetector = null,
        /**
         * @phpstan-var array{url?: string, temporary_url?: string, endpoint?: string, bucket_endpoint?: bool}
         */
        protected array $options = []
    ) {
        $this->pathPrefixer = new PathPrefixer($prefix);
        $this->visibilityConverter = $visibility ?: new PortableVisibilityConverter();
        $this->mimeTypeDetector = $mimeTypeDetector ?: new FinfoMimeTypeDetector();
    }

    public function getBucket(): string
    {
        return $this->bucket;
    }

    public function getClient(): OssClient
    {
        return $this->ossClient;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->upload($path, $contents, $config);
    }

    public function kernel(): OssClient
    {
        return $this->getClient();
    }

    public function setBucket(string $bucket): void
    {
        $this->bucket = $bucket;
    }

    /**
     * @param resource $contents
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->upload($path, $contents, $config);
    }

    /**
     * @param string|resource $contents
     */
    private function upload(string $path, $contents, Config $config): void
    {
        $options = $this->createOptionsFromConfig($config);
        if (! isset($options[OssClient::OSS_HEADERS][OssClient::OSS_OBJECT_ACL])) {
            /** @var string|null $visibility */
            $visibility = $config->get(Config::OPTION_VISIBILITY);
            if ($visibility !== null) {
                $options[OssClient::OSS_HEADERS][OssClient::OSS_OBJECT_ACL] = $options[OssClient::OSS_OBJECT_ACL] ?? $this->visibilityConverter->visibilityToAcl(
                    $visibility
                );
            }
        }

        $shouldDetermineMimetype = $contents !== '' && ! \array_key_exists(OssClient::OSS_CONTENT_TYPE, $options);

        if ($shouldDetermineMimetype) {
            $mimeType = $this->mimeTypeDetector->detectMimeType($path, $contents);
            if ($mimeType) {
                $options[OssClient::OSS_CONTENT_TYPE] = $mimeType;
            }
        }

        try {
            if (\is_string($contents)) {
                $this->ossClient->putObject($this->bucket, $this->pathPrefixer->prefixPath($path), $contents, $options);
            } else {
                $this->ossClient->uploadStream(
                    $this->bucket,
                    $this->pathPrefixer->prefixPath($path),
                    $contents,
                    $options
                );
            }
        } catch (OssException $ossException) {
            throw UnableToWriteFile::atLocation($path, $ossException->getMessage(), $ossException);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        try {
            $this->copy($source, $destination, $config);
            $this->delete($source);
        } catch (FilesystemOperationFailed $filesystemOperationFailed) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $filesystemOperationFailed);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->ossClient->copyObject(
                $this->bucket,
                $this->pathPrefixer->prefixPath($source),
                $this->bucket,
                $this->pathPrefixer->prefixPath($destination),
                $this->createOptionsFromConfig($config)
            );
        } catch (OssException $ossException) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $ossException);
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->ossClient->deleteObject($this->bucket, $this->pathPrefixer->prefixPath($path));
        } catch (OssException $ossException) {
            throw UnableToDeleteFile::atLocation($path, $ossException->getMessage(), $ossException);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $result = $this->listDirObjects($path, true);
        $keys = array_column($result['objects'], 'key');
        if ($keys === []) {
            return;
        }

        try {
            foreach (array_chunk($keys, 1000) as $items) {
                $this->ossClient->deleteObjects($this->bucket, $items);
            }
        } catch (OssException $ossException) {
            throw UnableToDeleteDirectory::atLocation($path, $ossException->getMessage(), $ossException);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        $defaultVisibility = $config->get('directory_visibility', $this->visibilityConverter->defaultForDirectories());
        $config = $config->withDefaults([
            'visibility' => $defaultVisibility,
        ]);

        try {
            $this->write(trim($path, '/') . '/', '', $config);
        } catch (FilesystemOperationFailed $filesystemOperationFailed) {
            throw UnableToCreateDirectory::dueToFailure($path, $filesystemOperationFailed);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        try {
            $this->ossClient->putObjectAcl(
                $this->bucket,
                $this->pathPrefixer->prefixPath($path),
                $this->visibilityConverter->visibilityToAcl($visibility)
            );
        } catch (OssException $ossException) {
            throw UnableToSetVisibility::atLocation($path, $ossException->getMessage(), $ossException);
        }
    }

    public function visibility(string $path): FileAttributes
    {
        try {
            $result = $this->ossClient->getObjectAcl($this->bucket, $this->pathPrefixer->prefixPath($path));
        } catch (OssException $ossException) {
            throw UnableToRetrieveMetadata::visibility($path, $ossException->getMessage(), $ossException);
        }

        $visibility = $this->visibilityConverter->aclToVisibility($result);

        return new FileAttributes($path, null, $visibility);
    }

    public function fileExists(string $path): bool
    {
        try {
            return $this->ossClient->doesObjectExist($this->bucket, $this->pathPrefixer->prefixPath($path));
        } catch (OssException $ossException) {
            throw UnableToCheckFileExistence::forLocation($path, $ossException);
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            $prefix = $this->pathPrefixer->prefixDirectoryPath($path);
            $options = [
                'prefix' => $prefix,
                'delimiter' => '/',
                'max-keys' => 1,
            ];
            $model = $this->ossClient->listObjects($this->bucket, $options);

            return $model->getObjectList() !== [];
        } catch (OssException $ossException) {
            throw UnableToCheckDirectoryExistence::forLocation($path, $ossException);
        }
    }

    public function read(string $path): string
    {
        return $this->getObject($path);
    }

    /**
     * @return resource
     */
    public function readStream(string $path)
    {
        /** @var resource $stream */
        $stream = fopen('php://temp', 'w+b');

        try {
            $this->ossClient->getObject($this->bucket, $this->pathPrefixer->prefixPath($path), [
                OssClient::OSS_FILE_DOWNLOAD => $stream,
            ]);
        } catch (OssException $ossException) {
            throw UnableToReadFile::fromLocation($path, $ossException->getMessage(), $ossException);
        }

        rewind($stream);

        return $stream;
    }

    /**
     * @return \Traversable<\League\Flysystem\StorageAttributes>
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $directory = rtrim($path, '/');
        $result = $this->listDirObjects($directory, $deep);

        foreach ($result['objects'] as $files) {
            $path = $this->pathPrefixer->stripDirectoryPrefix((string) ($files['key'] ?? $files['prefix']));
            if ($path === $directory) {
                continue;
            }

            yield $this->mapObjectMetadata($files);
        }

        foreach ($result['prefix'] as $dir) {
            yield new DirectoryAttributes($this->pathPrefixer->stripDirectoryPrefix($dir));
        }
    }

    /**
     * Get the metadata of a file.
     */
    private function getMetadata(string $path, string $type): FileAttributes
    {
        try {
            /** @var array{key?: string, prefix: ?string, content-length?: string, size?: int, last-modified?: string, content-type?: string} $metadata */
            $metadata = $this->ossClient->getObjectMeta($this->bucket, $this->pathPrefixer->prefixPath($path));
        } catch (OssException $ossException) {
            throw UnableToRetrieveMetadata::create($path, $type, $ossException->getMessage(), $ossException);
        }

        $attributes = $this->mapObjectMetadata($metadata, $path);

        if (! $attributes instanceof FileAttributes) {
            throw UnableToRetrieveMetadata::create($path, $type);
        }

        return $attributes;
    }

    /**
     * @param array{key?: string, prefix: ?string, content-length?: string, size?: int, last-modified?: string, content-type?: string} $metadata
     */
    private function mapObjectMetadata(array $metadata, ?string $path = null): StorageAttributes
    {
        if ($path === null) {
            $path = $this->pathPrefixer->stripPrefix((string) ($metadata['key'] ?? $metadata['prefix']));
        }

        if (str_ends_with($path, '/')) {
            return new DirectoryAttributes(rtrim($path, '/'));
        }

        $dateTime = isset($metadata['last-modified']) ? strtotime($metadata['last-modified']) : null;
        $lastModified = $dateTime ?: null;

        return new FileAttributes(
            $path,
            isset($metadata['content-length']) ? (int) $metadata['content-length'] : ($metadata['size'] ?? null),
            null,
            $lastModified,
            $metadata['content-type'] ?? null,
            $this->extractExtraMetadata($metadata)
        );
    }

    /**
     * @param array<string,mixed> $metadata
     *
     * @return array<string,mixed>
     */
    private function extractExtraMetadata(array $metadata): array
    {
        $extracted = [];

        foreach (self::EXTRA_METADATA_FIELDS as $field) {
            if (isset($metadata[$field]) && $metadata[$field] !== '') {
                $extracted[$field] = $metadata[$field];
            }
        }

        return $extracted;
    }

    public function fileSize(string $path): FileAttributes
    {
        $attributes = $this->getMetadata($path, FileAttributes::ATTRIBUTE_FILE_SIZE);
        if ($attributes->fileSize() === null) {
            throw UnableToRetrieveMetadata::fileSize($path);
        }

        return $attributes;
    }

    public function mimeType(string $path): FileAttributes
    {
        $attributes = $this->getMetadata($path, FileAttributes::ATTRIBUTE_MIME_TYPE);
        if ($attributes->mimeType() === null) {
            throw UnableToRetrieveMetadata::mimeType($path);
        }

        return $attributes;
    }

    public function lastModified(string $path): FileAttributes
    {
        $attributes = $this->getMetadata($path, FileAttributes::ATTRIBUTE_LAST_MODIFIED);
        if ($attributes->lastModified() === null) {
            throw UnableToRetrieveMetadata::lastModified($path);
        }

        return $attributes;
    }

    /**
     * Read an object from the OssClient.
     */
    protected function getObject(string $path): string
    {
        try {
            return $this->ossClient->getObject($this->bucket, $this->pathPrefixer->prefixPath($path));
        } catch (OssException $ossException) {
            throw UnableToReadFile::fromLocation($path, $ossException->getMessage(), $ossException);
        }
    }

    /**
     * File list core method.
     *
     * @return array{prefix: array<string>, objects: array<array{key?: string, prefix: ?string, content-length?: string, size?: int, last-modified?: string, content-type?: string}>}
     */
    public function listDirObjects(string $dirname = '', bool $recursive = false): array
    {
        $prefix = trim($this->pathPrefixer->prefixPath($dirname), '/');
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
            $model = $this->ossClient->listObjects($this->bucket, $options);
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

        /** @var string|null $visibility */
        $visibility = $config->get(Config::OPTION_VISIBILITY);
        if ($visibility) {
            $options[OssClient::OSS_HEADERS][OssClient::OSS_OBJECT_ACL] = $this->visibilityConverter->visibilityToAcl(
                $visibility
            );
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
            return $this->concatPathToUrl($this->options['url'], $this->pathPrefixer->prefixPath($path));
        }

        return $this->concatPathToUrl($this->normalizeHost(), $this->pathPrefixer->prefixPath($path));
    }

    protected function normalizeHost(): string
    {
        if (! isset($this->options['endpoint'])) {
            throw UnableToGetUrl::missingOption('endpoint');
        }

        $endpoint = $this->options['endpoint'];
        if (! str_starts_with($endpoint, 'http')) {
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
     * @param array<string, mixed> $options
     */
    public function signUrl(
        string $path,
        DateTimeInterface|int $expiration,
        array $options = [],
        string $method = 'GET'
    ): string {
        $expires = $expiration instanceof DateTimeInterface ? $expiration->getTimestamp() - time() : $expiration;

        return $this->ossClient->signUrl(
            $this->bucket,
            $this->pathPrefixer->prefixPath($path),
            $expires,
            $method,
            $options
        );
    }

    /**
     * Get a temporary URL for the file at the given path.
     *
     * @param array<string, mixed> $options
     */
    public function getTemporaryUrl(
        string $path,
        DateTimeInterface|int $expiration,
        array $options = [],
        string $method = 'GET'
    ): string {
        $uri = new Uri($this->signUrl($path, $expiration, $options, $method));

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

    public function publicUrl(string $path, Config $config): string
    {
        $location = $this->pathPrefixer->prefixPath($path);

        try {
            return $this->concatPathToUrl($this->normalizeHost(), $location);
        } catch (Throwable $throwable) {
            throw UnableToGeneratePublicUrl::dueToError($path, $throwable);
        }
    }

    public function checksum(string $path, Config $config): string
    {
        $algo = $config->get('checksum_algo', 'etag');

        if ($algo !== 'etag') {
            throw new ChecksumAlgoIsNotSupported();
        }

        try {
            $metadata = $this->getMetadata($path, 'checksum')
                ->extraMetadata();
        } catch (UnableToRetrieveMetadata $unableToRetrieveMetadata) {
            throw new UnableToProvideChecksum($unableToRetrieveMetadata->reason(), $path, $unableToRetrieveMetadata);
        }

        if (! isset($metadata['etag'])) {
            throw new UnableToProvideChecksum('ETag header not available.', $path);
        }

        return strtolower(trim($metadata['etag'], '"'));
    }

    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, Config $config): string
    {
        try {
            return $this->ossClient->generatePresignedUrl(
                $this->bucket,
                $this->pathPrefixer->prefixPath($path),
                $expiresAt->getTimestamp(),
                'GET',
                $config->get('gcp_signing_options', [])
            );
        } catch (Throwable $throwable) {
            throw UnableToGenerateTemporaryUrl::dueToError($path, $throwable);
        }
    }
}
