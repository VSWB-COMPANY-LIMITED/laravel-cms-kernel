# ⚡ Laravel CMS Kernel

A premium kernel extension for Laravel CMS providing core system functionality, package initialization, and powerful system customizations.

<p align="center">
    <a href="https://packagist.org/packages/dev-extensions/kernel"><img src="https://img.shields.io/packagist/v/dev-extensions/kernel.svg?style=flat-square" alt="Latest Version"></a>
    <img src="https://img.shields.io/badge/license-proprietary-red.svg?style=flat-square" alt="Software License">
    <a href="https://packagist.org/packages/dev-extensions/kernel"><img src="https://img.shields.io/packagist/dt/dev-extensions/kernel.svg?style=flat-square" alt="Total Downloads"></a>
</p>

---

## 🏗️ System Architecture

| Branch | CMS Version | System Namespace | Description |
| :--- | :--- | :--- | :--- |
| `lte.6x-is_dev` | <= 6.x | `Dev\` | Development branch for legacy CMS. |
| `lte.6x-is_platform` | <= 6.x | `Platform\` | Platform branch for legacy CMS. |
| `v7x` | >= 7.x | `Dev\` | Standard branch for modern CMS. |

---

## 🚀 Key Features

*   **Core Systems**: Automatic bootstrap and package initialization.
*   **Google Integration**: Full support for Google Drive & Spreadsheet API.
*   **Data Macros**: Advanced query macros and benchmarking tools.
*   **Utilities**: Province detection, phone extraction, and form field helpers.
*   **Automation**: System utility tasks and cloud synchronization.

---

## ⚙️ Installation & Setup

### 1. Install via Composer
```bash
composer require dev-extension/kernel
```

### 2. Configure
Publish the configuration to `config/kernel/`:
```bash
php artisan vendor:publish --tag=cms-config
```

---

## 📚 Documentation

For detailed guides, please refer to the following:

- ☁️ **[Google Drive Mirror Sync](GOOGLE_DRIVE.md)**: Full setup for cloud storage and synchronization.

---

## 🛠️ Common Commands

### Google Drive Sync
```bash
php artisan gdrive:mirror:sync [PATH_OR_ID] --retry=5
```

---

---

## 🤝 Support & Security

- 🐛 **Security**: If you discover any security issues, please email [toan@visualweber.com](mailto:toan@visualweber.com).
- 💎 **Credits**: Developed with ❤️ by [Visual Weber Vietnam](https://visualweber.com).
- 📜 **License**: Proprietary. © 2026 Visual Weber Company Limited. All rights reserved. Proprietary software developed and distributed by Visual Weber. Use is permitted only under a valid license agreement.
  © 2026 Công ty TNHH Visual Weber. Bảo lưu mọi quyền. Phần mềm độc quyền của Visual Weber, chỉ được sử dụng theo Hợp đồng cấp phép.
