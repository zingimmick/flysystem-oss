# Flysystem OSS
<p align="center">
<a href="https://github.com/zingimmick/flysystem-oss/actions"><img src="https://github.com/zingimmick/flysystem-oss/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://codecov.io/gh/zingimmick/flysystem-oss"><img src="https://codecov.io/gh/zingimmick/flysystem-oss/branch/master/graph/badge.svg" alt="Code Coverage" /></a>
<a href="https://packagist.org/packages/zing/flysystem-oss"><img src="https://poser.pugx.org/zing/flysystem-oss/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/zing/flysystem-oss"><img src="https://poser.pugx.org/zing/flysystem-oss/downloads" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/zing/flysystem-oss"><img src="https://poser.pugx.org/zing/flysystem-oss/v/unstable.svg" alt="Latest Unstable Version"></a>
<a href="https://packagist.org/packages/zing/flysystem-oss"><img src="https://poser.pugx.org/zing/flysystem-oss/license" alt="License"></a>
</p>

> **Requires [PHP 7.2.0+](https://php.net/releases/)**

Require Flysystem OSS using [Composer](https://getcomposer.org):

```bash
composer require zing/flysystem-oss
```

## Usage

```php
use League\Flysystem\Filesystem;
use OSS\OssClient;
use Zing\Flysystem\Oss\OssAdapter;

$prefix = '';
$config = [
    'key' => 'aW52YWxpZC1rZXk=',
    'secret' => 'aW52YWxpZC1zZWNyZXQ=',
    'bucket' => 'test',
    'endpoint' => 'oss-cn-shanghai.aliyuncs.com',
];

$config['options'] = [
    'url' => '',
    'endpoint' => $config['endpoint'], 
    'bucket_endpoint' => '',
    'temporary_url' => '',
];

$client = new OssClient($config['key'], $config['secret'], $config['endpoint']);
$adapter = new OssAdapter($client, $config['bucket'], $prefix, null, null, $config['options']);
$flysystem = new Filesystem($adapter);
```

## Reference

[league/flysystem-aws-s3-v3](https://github.com/thephpleague/flysystem-aws-s3-v3)

[zing/flysystem-obs](https://github.com/zingimmick/flysystem-obs)

## License

Flysystem OSS is an open-sourced software licensed under the [MIT license](LICENSE).
