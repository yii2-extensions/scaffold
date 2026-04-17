<!-- markdownlint-disable MD041 -->
<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="https://www.yiiframework.com/image/design/logo/yii3_full_for_dark.svg">
        <source media="(prefers-color-scheme: light)" srcset="https://www.yiiframework.com/image/design/logo/yii3_full_for_light.svg">
        <img src="https://www.yiiframework.com/image/design/logo/yii3_full_for_light.svg" alt="Yii Framework" width="80%">
    </picture>
    <h1 align="center">Scaffold</h1>
    <br>
</p>
<!-- markdownlint-enable MD041 -->

<p align="center">
    <a href="https://github.com/yii2-extensions/scaffold/actions/workflows/build.yml" target="_blank">
        <img src="https://img.shields.io/github/actions/workflow/status/yii2-extensions/scaffold/build.yml?style=for-the-badge&logo=github&label=Build" alt="Build">
    </a>
    <a href="https://github.com/yii2-extensions/scaffold/actions/workflows/mutation.yml" target="_blank">
        <img src="https://img.shields.io/github/actions/workflow/status/yii2-extensions/scaffold/mutation.yml?style=for-the-badge&logo=github&label=Mutation" alt="Mutation">
    </a>
    <a href="https://github.com/yii2-extensions/scaffold/actions/workflows/static.yml" target="_blank">
        <img src="https://img.shields.io/github/actions/workflow/status/yii2-extensions/scaffold/static.yml?style=for-the-badge&logo=github&label=PHPStan" alt="PHPStan">
    </a>
</p>

<p align="center">
    <strong>Declarative multi-layer file scaffolding for Yii2 projects</strong>
</p>

## Features

<picture>
    <source media="(max-width: 767px)" srcset="./docs/svgs/features-mobile.svg">
    <img src="./docs/svgs/features.svg" alt="Feature Overview" style="width: 100%;">
</picture>

## Installation

```bash
composer require yii2-extensions/scaffold
composer config allow-plugins.yii2-extensions/scaffold true
```

Declare the providers that are permitted to write files into your project:

```json
{
    "extra": {
        "scaffold": {
            "allowed-packages": [
                "yii2-extensions/app-base-scaffold"
            ]
        }
    }
}
```

Run `composer install` to trigger the scaffold process. Commit `scaffold-lock.json` to version control.

## Configuration

Minimal `composer.json` for a project using one scaffold provider:

```json
{
    "require": {
        "yii2-extensions/scaffold": "^0.1",
        "yii2-extensions/app-base-scaffold": "^0.1"
    },
    "config": {
        "allow-plugins": {
            "yii2-extensions/scaffold": true
        }
    },
    "extra": {
        "scaffold": {
            "allowed-packages": [
                "yii2-extensions/app-base-scaffold"
            ]
        }
    }
}
```

Register the console module in `config/console.php` to enable `yii scaffold/*` commands:

```php
'modules' => [
    'scaffold' => \yii\scaffold\Module::class,
],
```

## Documentation

- 📥 [Installation Guide](docs/installation.md)
- ⚙️ [Configuration Reference](docs/configuration.md)
- 📦 [Creating Providers](docs/providers.md)
- 🔀 [File Modes](docs/modes.md)
- 🖥️ [Console Commands](docs/console.md)

## Package information

[![PHP](https://img.shields.io/badge/%3E%3D8.3-777BB4.svg?style=for-the-badge&logo=php&logoColor=white)](https://www.php.net/releases/8.3/en.php)
[![Yii 22.0.x](https://img.shields.io/badge/22.0.x-0073AA.svg?style=for-the-badge&logo=yii&logoColor=white)](https://github.com/yiisoft/yii2/tree/22.0)
[![Latest Stable Version](https://img.shields.io/packagist/v/yii2-extensions/scaffold.svg?style=for-the-badge&logo=packagist&logoColor=white&label=Stable)](https://packagist.org/packages/yii2-extensions/scaffold)
[![Total Downloads](https://img.shields.io/packagist/dt/yii2-extensions/scaffold.svg?style=for-the-badge&logo=composer&logoColor=white&label=Downloads)](https://packagist.org/packages/yii2-extensions/scaffold)

## Quality code

[![PHPStan Level Max](https://img.shields.io/badge/PHPStan-Level%20Max-4F5D95.svg?style=for-the-badge&logo=github&logoColor=white)](https://github.com/yii2-extensions/scaffold/actions/workflows/static.yml)
[![Super-Linter](https://img.shields.io/github/actions/workflow/status/yii2-extensions/scaffold/linter.yml?style=for-the-badge&label=Super-Linter&logo=github)](https://github.com/yii2-extensions/scaffold/actions/workflows/linter.yml)
[![StyleCI](https://img.shields.io/badge/StyleCI-Passed-44CC11.svg?style=for-the-badge&logo=github&logoColor=white)](https://github.styleci.io/repos/scaffold?branch=main)

## Our social networks

[![Follow on X](https://img.shields.io/badge/-Follow%20on%20X-1DA1F2.svg?style=for-the-badge&logo=x&logoColor=white&labelColor=000000)](https://x.com/Terabytesoftw)

## License

[![License](https://img.shields.io/badge/License-BSD--3--Clause-brightgreen.svg?style=for-the-badge&logo=opensourceinitiative&logoColor=white&labelColor=555555)](LICENSE)
