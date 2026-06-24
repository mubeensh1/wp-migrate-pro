# WP Migrate Pro

A WordPress plugin for full-site backup, restore, and migration — including all files and the complete database, with automatic URL replacement.

---

## Features

- **Full backup** — exports every database table and compresses your entire `wp-content` directory into a single `.zip` file
- **One-click restore** — revert your site to any stored backup point with a single click
- **Three import methods** — upload a ZIP from your computer, paste a remote URL, or select a backup already on the server
- **Automatic URL replacement** — when migrating to a new domain, all URLs in the database are updated automatically, including PHP-serialised data
- **Real-time progress bar** — live percentage, stage label, and scrolling log during backup, import, and restore
- **Backup exclusions** — cache directories, log files, and any file or folder containing `backup`, `backups`, or `bkp` are automatically excluded

---

## Requirements

- WordPress 5.6 or higher
- PHP 7.4 or higher
- Administrator role
- At least 128 MB memory limit (recommended)

---

## Installation

1. Go to **Plugins → Add New → Upload Plugin**
2. Select `wp-migrate-pro.zip` and click **Install Now**
3. Click **Activate Plugin**
4. Go to **Migrate Pro** in the left admin menu

---

## How to Back Up

1. Go to **Migrate Pro → Backup**
2. Click **Create Backup Now**
3. Watch the progress bar — the backup runs in the background
4. When complete, the page reloads and your backup appears in **My Backups**

---

## How to Migrate to a New Site

### On the source site
1. Go to **Migrate Pro → Backup** and create a backup
2. Go to **My Backups**, click **Download** to save the `.zip`

### On the destination site
1. Install and activate WP Migrate Pro
2. Go to **Migrate Pro → Import**
3. Set the **Destination URL** to the new site's domain (e.g. `https://newsite.com`)
4. Choose an import method:
   - **Upload ZIP** — drag and drop the file you downloaded
   - **Remote URL** — paste a direct link to the backup file; the server fetches it (no upload size limit)
   - **Server Backup** — select a backup already stored on this server
5. Click Import and wait for the progress bar to complete

The plugin will extract the archive, restore all files, import the database, replace every URL, and flush all caches.

---

## How to Restore

1. Go to **Migrate Pro → My Backups**
2. Select a backup from the **Restore** dropdown at the top
3. Click **Restore Selected** and confirm
4. The site's database and files will be reverted to that backup point

Restore does not change the site URL — it simply replaces the current database and files with those from the backup.

---

## Import Methods Explained

| Method | Best for |
|---|---|
| **Upload ZIP** | Backups under your server's upload limit |
| **Remote URL** | Large backups — the server downloads directly, bypassing upload limits |
| **Server Backup** | Backups already on this server (e.g. created here and not yet deleted) |

---

## What Gets Excluded from Backups

The following are automatically skipped during backup:

- Cache directories (`cache`, `wp-rocket`, `w3tc`, `breeze`, `litespeed`, etc.)
- Temporary and upgrade directories
- Log files (`.log`)
- Any file or directory whose name contains `backup`, `backups`, or `bkp`
- Known backup plugin directories (`updraftplus`, `duplicator`, `backupbuddy`, etc.)
- Files larger than 500 MB

---

## File Structure

```
wp-migrate-pro/
├── wp-migrate-pro.php              Bootstrap and constants
├── includes/
│   ├── class-wmp-compat.php        ZIP library abstraction, environment helpers
│   ├── class-wmp-progress.php      Progress file writer, polled by the browser
│   ├── class-wmp-runner.php        Background job dispatcher
│   ├── class-wmp-backup.php        Backup engine
│   ├── class-wmp-import.php        Import and restore engine
│   ├── class-wmp-ajax.php          All AJAX endpoints
│   └── class-wmp-admin.php         Admin menu and page
├── templates/
│   └── admin-page.php              Admin UI
└── assets/
    ├── css/admin.css
    └── js/admin.js
```

---

## Custom Backup Directory

To store backups outside the web root, add this to `wp-config.php`:

```php
define( 'WMP_BACKUP_PATH', '/home/user/private/wmp-backups/' );
```

---

## Changelog

### 1.4.1
- Removed host-specific messages and banners from the UI
- Simplified log output and confirm dialogs
- Cleaned up Requirements tab

### 1.4.0
- Added Restore feature — revert to any backup from My Backups tab
- Fixed 95%→0% progress reset bug (Progress factory pattern)
- Fixed `function_exists(Closure)` fatal error on some PHP/WordPress versions

### 1.3.x
- Fixed 0% stuck bug — background job now uses static property instead of transient for inline mode
- Added automatic exclusion of backup files and directories from scans
- Loopback token no longer passed through `sanitize_key()` which was corrupting it

### 1.2.0
- Real-time progress bar with percentage, stage label, and live log
- Background execution with loopback → cron → inline fallback chain
- PclZip fallback for hosts without ZipArchive

### 1.0.0
- Initial release
