# Flysystem OSS

<p align="center">
<a href="https://github.com/zingimmick/flysystem-oss/actions/workflows/tests.yml"><img src="https://github.com/zingimmick/flysystem-oss/actions/workflows/tests.yml/badge.svg?branch=3.x" alt="tests"></a>
<a href="https://codecov.io/gh/zingimmick/flysystem-oss"><img src="https://codecov.io/gh/zingimmick/flysystem-oss/branch/3.x/graph/badge.svg" alt="Code Coverage" /></a>
<a href="https://packagist.org/packages/zing/flysystem-oss"><img src="https://poser.pugx.org/zing/flysystem-oss/v/stable.svg" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/zing/flysystem-oss"><img src="https://poser.pugx.org/zing/flysystem-oss/downloads" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/zing/flysystem-oss"><img src="https://poser.pugx.org/zing/flysystem-oss/v/unstable.svg" alt="Latest Unstable Version"></a>
<a href="https://packagist.org/packages/zing/flysystem-oss"><img src="https://poser.pugx.org/zing/flysystem-oss/license" alt="License"></a>
</p>

> **Requires**
> - **[PHP 8.0+](https://php.net/releases/)**
> - **[Flysystem 3.10+](https://github.com/thephpleague/flysystem/releases)**

## Version Information

| Version | Flysystem | PHP Version | Status                  |
|:--------|:----------|:------------|:------------------------|
| 3.x     | 3.10+     | >= 8.0      | Active support :rocket: |
| 2.x     | 2.x - 3.x | >= 7.2      | Active support          |
| 1.x     | 1.x       | >= 7.2      | Active support          |

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

## Integration

- Laravel: [zing/laravel-flysystem-oss](https://github.com/zingimmick/laravel-flysystem-oss)

## Reference

[league/flysystem-aws-s3-v3](https://github.com/thephpleague/flysystem-aws-s3-v3)

## License

Flysystem OSS is an open-sourced software licensed under the [MIT license](LICENSE).
