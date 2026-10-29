# Laravel CMS Kernel 

## Branches

- 📦 lte.6x-is_dev: sử dụng cho các phiên bản CMS <=6.x và namespace hệ thống dưới dạng `Dev\\`
- 📦 lte.6x-is_platform: sử dụng cho các phiên bản CMS <=6.x và namespace hệ thống dưới dạng `Platform\\`
- 📦 v7x: sử dụng cho các phiên bản CMS >=7.x và namespace hệ thống lúc này luôn luôn dưới dạng `Dev\\`

A comprehensive kernel extension for Laravel CMS that provides core system functionality, package initialization, and kernel customizations.

<p align="center">
    <a href="https://packagist.org/packages/dev-extensions/kernel"><img src="https://img.shields.io/packagist/v/dev-extensions/kernel.svg?style=flat-square" alt="Latest Version"></a>
    <img src="https://img.shields.io/badge/license-proprietary-red.svg?style=flat-square" alt="Software License">
    <a href="https://packagist.org/packages/dev-extensions/kernel"><img src="https://img.shields.io/packagist/dt/dev-extensions/kernel.svg?style=flat-square" alt="Total Downloads"></a>
</p>

## Features

- 🚀 Bootstrap core system components
- 📦 Initialize core packages automatically
- 🔧 Kernel and middleware customizations
- 🌍 Location management (Districts, Wards with import functionality)
- 📊 Google Spreadsheet integration
- 🎂 Member birthday notification system
- 🔍 Advanced query macros
- 📈 Benchmarking utilities
- 🎨 Form field helpers

## Requirements

- PHP 7.4 or higher
- Laravel Framework (8.x or higher recommended)

## Installation

You can install the package via composer:

```shell
composer require dev-extension/kernel
```

### Binary Scripts Installation

The package automatically installs all binary scripts from `vendor/dev-extensions/kernel/bin/` to your project's `ROOT/bin/` directory during:
- `composer install`
- `composer update`

All scripts are symlinked (not copied), so updates to the package will automatically reflect in your project.

**Installed scripts include:**
- 🐳 Docker setup & infrastructure scripts
- 🚀 Deployment & CI/CD tools (GitLab integration)
- 🖼️ Image & PDF optimization utilities
- 📱 Barcode & QR code decoders
- 🔍 Security scanning & maintenance tools
- 🌐 WordPress auto-installer
- 🔧 Various development utilities

You can run them directly from your project root:
```shell
./bin/docker-setup-laravel.sh
./bin/optimize-image.sh image.jpg
./bin/scan-malware.sh
```

📚 **Documentation:**
- **[BINARIES.md](BINARIES.md)** - Complete list of all available scripts
- **[BINARIES_SETUP.md](BINARIES_SETUP.md)** - Installation & troubleshooting guide
- **[FORCE_MODE_EXPLAINED.md](FORCE_MODE_EXPLAINED.md)** - How automatic cleanup works

⚠️ **Important:** The installer uses **FORCE MODE** - existing files/symlinks will be automatically removed and replaced. [Learn more](FORCE_MODE_EXPLAINED.md)

## Configuration

After installation, publish the configuration files:

```shell
php artisan vendor:publish --tag=cms-config
```

This will publish configuration files to `config/kernel/` directory.

## Usage

### Commands

#### Import Location Data
Import districts and wards data from Excel file:

```shell
php artisan location:location-import --truncate=1
```

#### Member Birthday Notifications
Send birthday reminders to members:

```shell
php artisan cms:member:birthday-notification
```

#### Git Commit Hook Setup
Install Git commit message hook:

```shell
php artisan git:install-commit-hook
```

### Seeders

#### Override Default Settings
Seed default settings for the application:

```shell
php artisan db:seed --class=\\Platform\\Kernel\\Seeders\\SettingSeeder
```

### Helper Functions

The package provides various helper functions:

#### Google Spreadsheet Integration
```php
apps_google_sheet($data, $spreadsheet, $credentialsType, $credentialsFile);
```

#### JSON Database Operations
```php
apps_json_to_database($original, $value, $key, $override);
```

#### Province Detection
```php
apps_province_detection('Thanh Hoá'); // Returns "Thanh Hoá"
```

#### Phone Extraction
```php
apps_phone_extraction($text); // Extracts phone numbers from text
```

### Traits

#### LoadAndPublishDataTrait
Provides methods for loading and publishing package resources:

```php
$this->setNamespace('kernel')
    ->loadMigrations()
    ->loadAndPublishConfigurations(['general', 'email'])
    ->loadAndPublishTranslations()
    ->loadHelpers()
    ->loadRoutes(['web', 'api']);
```

#### Benchmarkable
Add benchmarking capabilities to your classes:

```php
use Platform\Kernel\Traits\Benchmarkable;

class YourClass
{
    use Benchmarkable;
    
    public function someMethod()
    {
        $result = $this->benchmark('operation-name', function() {
            // Your code here
            return $someResult;
        });
    }
}
```

## Models

### District
Manage district data with relationships to cities:

```php
use Platform\Kernel\Models\District;

$district = District::where('city_id', 1)->get();
```

### Ward
Manage ward data with relationships to districts:

```php
use Platform\Kernel\Models\Ward;

$ward = Ward::where('district_id', 1)->get();
```

## Events & Listeners

### Member Birthday Event
The package includes a birthday reminder system:

- **Event**: `Platform\Kernel\Events\MemberBirthdayEvent`
- **Listener**: `Platform\Kernel\Listeners\MemberBirthdayListener`
- **Notification**: `Platform\Kernel\Notifications\MemberBirthdayNotification`

## API Routes

The package registers the following API routes:

- `GET|POST /api/v1/products/check-update` - System update check
- `GET /api/v1/license/verify` - License verification
- `GET /api/v1/license/check` - License check
- `DELETE /api/v1/delete-account` - Delete user account (requires authentication)

Test routes available at `/api/v1/test/*`

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Security

If you discover any security related issues, please email toan@visualweber.com instead of using the issue tracker.

## Credits

- [Visual Weber Vietnam](https://visualweber.com)
- All Contributors

## License

Proprietary. © 2026 Visual Weber Company Limited. All rights reserved. Proprietary software developed and distributed by Visual Weber. Use is permitted only under a valid license agreement.

© 2026 Công ty TNHH Visual Weber. Bảo lưu mọi quyền. Phần mềm độc quyền của Visual Weber, chỉ được sử dụng theo Hợp đồng cấp phép.

