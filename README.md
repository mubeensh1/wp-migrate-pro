# WP Migrate Pro

Full-site WordPress backup and migration plugin — database, files, automatic URL replacement, real-time progress bars with live log, and **524 / gateway-timeout safe**.

---

## Key Features

| Feature | Detail |
|---|---|
| **Full backup** | Exports all DB tables + compresses all of `wp-content` into one `.zip` |
| **Real-time progress bar** | Animated bar with percentage, stage label, current file/table detail, and live scrolling log |
| **524 / timeout safe** | Uses a fire-and-forget loopback job — no HTTP connection stays open longer than ~2 s. Cloudflare, WP Engine, Rocket.net, and all CDN proxies cannot kill the operation |
| **3 import methods** | Upload ZIP · Remote URL (server fetches directly, bypasses `upload_max_filesize`) · Server backup |
| **Serialisation-safe URL replace** | Replaces URLs in plain text **and** PHP-serialised data (`s:N:` lengths auto-corrected) |
| **PclZip fallback** | Works even without the `ZipArchive` PHP extension (uses PclZip which ships with WordPress core) |
| **WP Engine support** | Detects WPE, skips restricted paths, uses `wp_raise_memory_limit`, flushes WPE page cache |
| **Rocket.net support** | Detects environment, flushes WP Rocket cache after import |
| **Cache flush** | Clears WP object cache, WP Engine, WP Rocket, W3 Total Cache, WP Super Cache, Autoptimize |
| **Security** | Nonce + `manage_options` on every action, path-traversal guards, zip-slip prevention, SSRF protection, MIME validation, per-file signed download URLs |

---

## Installation

1. Upload `wp-migrate-pro.zip` via **Plugins → Add New → Upload Plugin**
2. Activate **WP Migrate Pro**
3. Go to **Migrate Pro** in the left admin menu

**Requirements:** PHP 7.4+, WordPress 5.6+, Administrator role

---

## How to Migrate a Site

### On the **source** site:
1. Go to **Migrate Pro → Backup**
2. Click **Create Backup Now** and watch the real-time progress bar
3. When complete, click **Download** to save the `.zip`

### On the **destination** site:
1. Install and activate WP Migrate Pro
2. Go to **Migrate Pro → Import**
3. Enter the **Destination URL** (e.g. `https://newsite.com`)
4. Choose method — **Upload ZIP**, **Remote URL**, or **Server Backup**
5. Click Import and watch the progress. The plugin will:
   - Extract the archive
   - Restore all `wp-content` files
   - Import the full database
   - Replace every URL (serialisation-safe)
   - Flush all caches

---

## Solving the 524 / Gateway Timeout Error

The 524 error is a **Cloudflare gateway timeout** — Cloudflare kills HTTP connections after ~100 seconds. Since large backups/imports take much longer, the old approach of one long AJAX call always failed on proxied hosts.

**WP Migrate Pro v1.2 solves this completely:**

```
Browser → POST wmp_start_backup  (returns job_id in < 2 s)  ✔ no timeout
Server  → fires background loopback to wmp_run_job (non-blocking)
Browser → polls wmp_poll_progress every 1.2 s  (each call < 1 s) ✔ no timeout
PHP     → runs the real work, writes progress to a JSON file
Browser → reads the JSON file via polls, updates the progress bar
```

Each HTTP request completes in well under 2 seconds. **The proxy never sees a long-lived connection.**

---

## Host-specific Configuration

### WP Engine / Rocket.net — custom backup path

Add to `wp-config.php` to store backups outside the web root:

```php
define( 'WMP_BACKUP_PATH', '/home/user/private/wmp-backups/' );
```

### Max file size (default 2 GB)

```php
define( 'WMP_MAX_FILE_SIZE', 4 * 1024 * 1024 * 1024 ); // 4 GB
```

---

## File Structure

```
wp-migrate-pro/
├── wp-migrate-pro.php              # Bootstrap, constants
├── includes/
│   ├── class-wmp-compat.php        # Environment detection, ZipArchive/PclZip abstraction
│   ├── class-wmp-progress.php      # JSON progress file writer (polled by browser)
│   ├── class-wmp-backup.php        # Backup engine — DB export + file ZIP with progress
│   ├── class-wmp-import.php        # Import engine — extract, files, DB, URL replace with progress
│   ├── class-wmp-ajax.php          # AJAX handlers: start/poll/download/delete + loopback runner
│   └── class-wmp-admin.php         # Admin menu, asset enqueue, page render
├── templates/
│   └── admin-page.php              # Full admin UI (Backup / Import / My Backups / Requirements)
└── assets/
    ├── css/admin.css               # Complete UI styles including progress bar
    └── js/admin.js                 # Tab switching, drag-drop, polling, progress animation
```

---

## Changelog

### 1.2.0
- Real-time progress bar with percentage, stage label, detail line, and live scrolling log
- Solved 524 Cloudflare timeout: fire-and-forget loopback job + progress file polling
- Animated shimmer bar, smooth pct counter, done/error bar states

### 1.1.0
- PclZip fallback (no ZipArchive needed)
- WP Engine + Rocket.net compatibility
- SSRF protection, zip-slip guard, MIME validation
- Requirements tab

### 1.0.0
- Initial release
