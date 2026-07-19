# ☁️ Google Drive Storage & Mirror Sync Setup Guide

This guide provides a comprehensive, step-by-step walkthrough for integrating Google Drive storage into your Laravel application and configuring the **Google Drive Mirror Sync** functionality.

---

## 📋 Prerequisites

Ensure your project has the necessary driver installed:

```bash
composer require nao-pon/flysystem-google-drive:~1.1
```

---

## 🚀 Phase 1: Create Google Drive API Credentials

### 1.1 Create a Google Cloud Project
1. Log in to the [Google Cloud Console](https://console.developers.google.com/).
2. Create a new project using the dropdown at the top.
   ![Create Project](https://raw.githubusercontent.com/vswb/laravel-cms-kernel/v7.x/public/google-drive-guide/060eac9e-e56e-11e6-907c-717932605569.png)

### 1.2 Enable Google Drive API
1. Select your project.
2. Go to **Library** and search for **Google Drive API**.
3. Click **Enable**.
   ![Enable API](https://raw.githubusercontent.com/vswb/laravel-cms-kernel/v7.x/public/google-drive-guide/28462245-a13b3d9c-6e1a-11e7-8cf8-0082ac8a9141.png)

### 1.3 Configure OAuth Consent Screen
1. Go to **APIs & Services** > **OAuth Consent Screen**.
2. Select **External** (or Internal for Workspace users) and click **Create**.
3. Fill in the **Product name** and click **Save**.
   ![Consent Screen](https://raw.githubusercontent.com/vswb/laravel-cms-kernel/v7.x/public/google-drive-guide/549fb3c0-e56f-11e6-9b0a-8771b0ba72b4.png)

### 1.4 Create OAuth 2.0 Client ID
1. Go to **Credentials** > **Create Credentials** > **OAuth Client ID**.
2. Select **Web Application**.
3. Under **Authorized redirect URIs**, add: `https://developers.google.com/oauthplayground`.
   ![Credentials](https://raw.githubusercontent.com/vswb/laravel-cms-kernel/v7.x/public/google-drive-guide/28473452-e675826c-6e44-11e7-8ff0-bea423b0cff7.png)
4. Click **Create** and copy your **Client ID** and **Client Secret**.

---

## 🔑 Phase 2: Obtain your Refresh Token

1. Go to the [Google OAuth2 Playground](https://developers.google.com/oauthplayground).
2. Click the **Settings icon** (top right):
   - Check **Use your own OAuth credentials**.
   - Paste your **Client ID** and **Client Secret**.
   ![OAuth Credentials](https://raw.githubusercontent.com/vswb/laravel-cms-kernel/v7.x/public/google-drive-guide/24fe7d88-e56d-11e6-82cf-2d75365d8800.png)
3. **Step 1 (Select Scopes)**:
   - Scroll to **Drive API v3**.
   - Select `https://www.googleapis.com/auth/drive` (Full access).
   ![Check Scopes](https://raw.githubusercontent.com/vswb/laravel-cms-kernel/v7.x/public/google-drive-guide/28462312-fa4397ea-6e1a-11e7-93ad-365b891052a6.png)
   - Click **Authorize APIs** and allow access.
4. **Step 2 (Exchange Tokens)**:
   - Click **Exchange authorization code for tokens**.
   ![Exchange Tokens](https://raw.githubusercontent.com/vswb/laravel-cms-kernel/v7.x/public/google-drive-guide/8472095c-e56c-11e6-85be-83adf00837c7.png)
5. **Step 3 (Get Refresh Token)**:
   - Click back on **Step 2** to see your **Refresh Token**.
   ![Refresh Token](https://raw.githubusercontent.com/vswb/laravel-cms-kernel/v7.x/public/google-drive-guide/2cef7a98-e56c-11e6-83b9-b4653850dbca.png)

---

## 📁 Phase 3: Getting Folder ID

To store files in a specific folder, open the folder in Google Drive. The **Folder ID** is the alphanumeric string at the end of the URL.
![Folder ID](https://raw.githubusercontent.com/vswb/laravel-cms-kernel/v7.x/public/google-drive-guide/d79422ba-e56b-11e6-8ba6-01c622fdef42.png)

---

## ⚙️ Phase 4: Configuration

### 4.1 Environment Variables
Add the following to your `.env` file:

```env
FILESYSTEM_CLOUD=google

GOOGLE_DRIVE_CLIENT_ID=your-client-id.apps.googleusercontent.com
GOOGLE_DRIVE_CLIENT_SECRET=your-client-secret
GOOGLE_DRIVE_REFRESH_TOKEN=your-refresh-token
GOOGLE_DRIVE_FOLDER_ID=null # Or a specific Folder ID
```

### 4.2 Filesystem Configuration
Ensure `config/filesystems.php` includes the `google` disk:

```php
'disks' => [
    // ...
    'google' => [
        'driver' => 'google',
        'clientId' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'clientSecret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        'refreshToken' => env('GOOGLE_DRIVE_REFRESH_TOKEN'),
        'folderId' => env('GOOGLE_DRIVE_FOLDER_ID'),
    ],
    // ...
],
```

---

## 🛠️ Usage

### Artisan Command (Mirror Sync)
The kernel provides a high-performance command to mirror Google Drive folders to your local machine (or external drives like OneDrive/SSD).

```bash
# Basic usage with Folder ID
php artisan gdrive:mirror:sync "Google_Folder_ID"

# Sync to a custom path (e.g., OneDrive or External SSD)
php artisan gdrive:mirror:sync "Google_Folder_ID" --path="/Users/eugene/Library/CloudStorage/OneDrive/Marketing"

# Force re-download everything (useful to fix corrupted or mismatched files)
php artisan gdrive:mirror:sync "Google_Folder_ID" --force

# Robust sync with high retries for unstable networks
php artisan gdrive:mirror:sync "Google_Folder_ID" --retry=10
```

#### 🛠️ Available Options

| Option | Description |
| :--- | :--- |
| `{folders*}` | (Required) One or more Google Drive Folder IDs or Names to sync. |
| `--path=` | Custom local storage path (e.g., External Drive/OneDrive). |
| `--force` | **SAFE:** Forces re-download and overwrites existing local files. It does **NOT** delete anything. |
| `--retry=3` | Number of retries for each file operation on network/API failure. |

---

## ⚡ Performance & Reliability

### Delta Sync (Incremental)
By default (without `--force`), the script performs a **Delta Sync**:
1. It checks the `lastModified` timestamp and `Size` of the local file.
2. If they match the Google Drive version exactly, it **Skips** the file.
3. This is extremely fast for large libraries (thousands of files) and resumes after a network drop.

### Google Native Export
Google Docs/Sheets/Slides are automatically converted to Office formats:
- **Google Sheets** $\rightarrow$ `.xlsx`
- **Google Docs** $\rightarrow$ `.docx`
- **Google Slides** $\rightarrow$ `.pptx`
- **Note:** Files exceeding Google's export limit (e.g., Sheets > 100MB) will be skipped with an error log.

### Name Safety & Collision Handling (Service Account mode)
Drive allows almost any name a filesystem doesn't — forbidden characters, names differing only
by case, `/`/`..` inside a name, names over 255 bytes. Every direct child's local name is made
safe before anything is written to disk:
- Characters NTFS refuses (`< > : " | ? * \ /`) and control characters are replaced with `_`;
  trailing dots/spaces are trimmed; Windows-reserved device names (`CON`, `PRN`, `COM1-9`,
  `LPT1-9`…) get `_` appended (e.g. `CON.txt` → `CON_.txt`).
- Each name is capped at **255 UTF-8 bytes**, cut only on a character boundary (Vietnamese
  diacritics are never split), with a short hash appended when a real cut happens so two
  different long names never collapse onto the same truncated one.
- **Name collisions are resolved at listing time**, as ONE shared namespace across files AND
  folders in the same parent — a folder and a file with the same Drive name collide just as much
  as two files do, and the destination filesystem (NTFS/APFS) is case-insensitive by default so
  `Report.pdf` and `report.pdf` collide too, even though Drive treats them as different files.
  Decision order is by **Drive file ID, ascending** — never by `files.list` response order (Drive
  does not guarantee that's stable) — so the same set of Drive items always produces the same
  local names. The loser of a collision gets the first 8 characters of its file ID appended
  before the extension (e.g. `report.pdf` → `report_1JP7CIBW.pdf`).
- Every resolved local path is re-validated against `--path` immediately before any
  directory/file is created — defense-in-depth against a Drive item name containing `/` or `..`
  that could otherwise write outside the destination directory.
- Because this tool **never deletes local files**, upgrading to this behavior leaves any
  previously-mirrored file under its old (unsafe) name in place and downloads the new,
  sanitized name alongside it — a duplicate, not data loss. Only files whose Drive name actually
  needed sanitizing are affected.

(Same rules — mirrored 1:1 in a standalone, cross-platform Go CLI at `tools/gdrive-mirror-cli/`
for machines without PHP; see that tool's `README.md` for the full write-up.)

### Programmatic Usage
```php
use Illuminate\Support\Facades\Storage;

// Using the default cloud disk
Storage::cloud()->put('test.txt', 'Hello World');

// List files
$files = Storage::cloud()->listContents('/', false);
```

---

## 💡 Pro Tips

- **OneDrive / External Drives**: This tool is ideal for bridging Google Drive to OneDrive or External HDD. Always use double quotes for paths with spaces: `--path="/Volumes/DATA/My Folder"`.
- **Large Libraries**: For syncing >10,000 files, run the command without `--force` to leverage incremental skipping.
- **Network Drops**: If your internet disconnects, simply re-run the command. It will quickly skip finished files and resume exactly where it left off.
