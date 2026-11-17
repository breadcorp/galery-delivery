# Gallery Delivery

Self-hosted photo gallery delivery for photographers.

Gallery Delivery is a standalone PHP app for private client galleries with password protection, previews, and ZIP downloads. It is designed for simple file upload deployments.

## Project history

Gallery Delivery originally started as a small personal project for my own photo delivery workflow. It went through several private iterations before being cleaned up and published as an open-source project. Public releases therefore use the 0.x version series while the project is still evolving.

## Release

Test build: `0.3.0-v5-preview` (based on the deployed 0.2.6 application).

## Core goals

- PHP 8.2+
- no database
- no framework
- no Composer required for normal usage
- Apache and Nginx support
- upload-and-run deployment

## Features

- private password-protected galleries
- per-gallery UUID links
- optional gallery names
- per-photo downloads
- full gallery ZIP download
- lazy ZIP rebuild (rebuild only when needed)
- JPG, PNG, WebP, and common camera RAW uploads (DNG, CR2/CR3, NEF, ARW, RAF, ORF, RW2, PEF, 3FR, IIQ, X3F)
- RAW downloads with a file card when a browser preview is unavailable
- generated JPG previews (GD optional)
- encrypted admin-visible gallery passwords
- multiple administrator accounts with a permanent `admin` account
- gallery enable/disable
- gallery password rotation
- auth-version invalidation on password change
- gallery unlock session TTL
- file-based persistent rate limiting (admin + gallery unlock)
- multi-disk storage support
- cross-disk gallery lookup by UUID
- duplicate UUID conflict detection across disks
- cached storage stats for dashboard performance
- lightweight sidecar live stats
- atomic JSON writes for mutable state
- global background (file or URL)
- per-gallery custom background URL
- Apache/Nginx hardening examples

## Installation

### 1. Upload files

Upload the `downloads` directory to your web root, for example:

```text
/var/www/html/downloads/
```

### 2. Configure storage

Set storage via `config.php` and/or environment variables.

Default:

```text
/mnt/ssd/photos/downloads
```

The storage path must be writable by PHP.

### 3. Open setup

Open:

```text
https://your-domain.com/downloads/
```

Complete the setup form to initialize admin access.

## Routing

Default mount:

```text
/downloads/
```

Main routes:

```text
/downloads/admin
/downloads/<gallery-uuid>
/downloads/<gallery-uuid>/photo/<photo-id>
/downloads/<gallery-uuid>/full.zip
```

## Requirements

- PHP 8.2+
- writable storage directory
- Apache or Nginx

Required PHP extensions:

- `fileinfo`
- `openssl`

Recommended:

- `zip`
- `gd`
- `mbstring`

## Multi-disk behavior

The active storage disk determines where **new** galleries are created.

Existing galleries remain accessible by UUID even after changing the active disk.

Dashboard listing scans configured storage disks and can detect duplicate UUID conflicts.

## ZIP behavior

ZIP files are not rebuilt after every upload/delete.

Flow:

```text
photos changed -> ZIP marked outdated
Download all -> ZIP rebuilt if needed, then downloaded
```

ZIP build uses lock files and atomic rename to avoid partial archives.

## Download behavior

- Photos and ZIPs use the browser download manager for progress and resume, without buffering entire files in JavaScript.
- Server-side download authorization remains enforced.
- HTTP Range requests are supported in PHP download responses.

## Security notes

- Keep gallery storage outside public web root.
- Do not expose storage via direct web alias/symlink.
- Keep `app/` and `deploy/` blocked from direct HTTP access.
- Keep internal lock/tmp/metadata files inaccessible via web server.

### Critical PHP runtime update (21 October 2026)

The PHP project disclosed unauthenticated remote-code-execution vulnerabilities CVE-2026-109618 and CVE-2026-109619. Update PHP itself immediately to at least 8.2.35, 8.3.36, 8.4.27, or 8.5.12, as applicable, and restart every PHP worker. Updating this application alone cannot fix vulnerabilities in the PHP runtime. The `session.upload_progress.enabled = Off` setting in the example PHP configuration is only a partial mitigation for one issue; it is not a substitute for upgrading. See the [official PHP advisory](https://www.php.net/index.php#2026-10-21-5).

See:

- `.htaccess`
- `deploy/nginx.conf.example`

## Backward compatibility

`gallery.json` from existing installations is loaded and normalized with defaults where possible.

New fields are added naturally during normal write operations. Existing UUID links remain unchanged.

`settings.json` lives only in the private control directory. See the v5 upgrade instructions below.

## Automated checks

- Lint: `find . -name '*.php' -print0 | xargs -0 -n1 php -l`
- Tests (isolated copy only): `php tests/run.php` and `php tests/v5.php`
- HTTP integration: `node tests/http.mjs <test-login.json> <test-photo.jpg>` against three local PHP workers on ports 8785–8787, sharing disposable storage.

GitHub Actions runs both checks on push and pull request.

## Backup

Back up both:

- application files
- storage directory (photos + metadata)

## License

GPL-3.0

## v5 upgrade / private settings

Back up the application and all storage before switching versions. Set a stable absolute
`DOWNLOADS_APP_DATA_DIR` outside the public document root, normally
`/mnt/ssd/photos/downloads/_app`. If omitted, it defaults to the configured storage root
plus `/_app` and is pinned before the active photo disk is selected. Changing the active
disk must not move settings, lock files, or rate limits. Never point a test installation
at production settings or production gallery disks.

On first load, an old `app/settings.json` is copied atomically to the private path if
there is no private settings file, then removed from the webroot. A pre-existing private
settings file is authoritative. Preserve the original app_secret: replacing it makes
stored gallery passwords unreadable. If the application directory is read-only, remove
the legacy copy after confirming the private copy; startup refuses to continue with an
undeletable legacy secret. Corrupt settings cause an error rather than reopening setup.
Check old backup/temp copies of settings during deployment as well.

Legacy gallery sessions without an auth_version are rejected. Current sessions have
an absolute 24-hour TTL and are invalidated by password rotation.

## Web server configuration

Apache 2.4 requires mod_rewrite and the distributed .htaccess; see
`deploy/apache.conf.example` for AllowOverride. Only index.php and explicitly allowed
static asset types are served. Missing mod_rewrite fails closed. The rules work under
other mount paths without editing RewriteBase. Configure the PHP handler in your host.

For Nginx, install `deploy/nginx.conf.example` inside the site's server block and adapt
the physical path, mount path and FPM socket. Nginx does not read .htaccess. Validate
with `nginx -t` / `apachectl configtest` and smoke-test routing and private paths on the
actual host before switching traffic. These examples have not been exercised against
the production host's web-server configuration.

## Download acceleration

The default `download_backend=php` supports single byte ranges, suffix ranges, HEAD,
If-Range, Content-Range, 416 and zero-length files. Multi-range requests are served as a
full response. PHP releases the session lock before streaming.

For Nginx, configure an internal location for each private photo disk and set in
config.local.php:

```php
return [
    'download_backend' => 'x-accel',
    'x_accel_mappings' => ['/mnt/ssd/photos/downloads' => '/_gallery_files/'],
];
```

For Apache, enable mod_xsendfile, allow each storage disk using XSendFilePath, then set
`download_backend=x-sendfile`. Authorization always happens in PHP. Enable either
backend only after its server module/location is configured. Temporary selection ZIPs
continue through PHP so their cleanup remains reliable. The current preview uses PHP. X-Accel was also tested with local Nginx 1.30.6 + PHP-CGI, including actual file responses and Range/If-Range. The target production host still needs its own configuration check; Apache/X-Sendfile has not been run in this workspace.

## Upload queue and concurrency

Drag/drop or select photos, then click Upload photos. Three requests transfer in
parallel, each with progress and an individual retry. IndexedDB stores queued files
for refresh recovery; after refreshing, click Upload photos to continue. Uploaded
files retain only small completion records. Clear finished removes those records;
Remove from queue affects only the local queue, never an uploaded gallery photo.

Browser storage quotas/private browsing can prevent persistence; the UI reports this.
Keep the page open in that case. Recovery restarts an interrupted file from its start,
not from the interrupted byte. A stable per-file upload identifier prevents duplicates
when retrying after a lost response. The plain HTML upload still works without JS.

Upload commits, gallery edits/deletes and ZIP snapshots share a gallery lock. No ZIP is
built on upload. The first Download all builds it; waiting callers recheck and reuse
the result. Regenerate ZIP remains available to admins. ZIP building is lazy, not a
background job: allow an appropriate request timeout for very large galleries.

## Audit semantics

views counts gallery page GETs, including the password page and refreshes; it is not a
unique-person metric. unlocks counts successful password entries. photo_downloads and
zip_downloads count accepted transfer starts, not proof that the client received all
bytes. HEAD, invalid ranges, and resumed ranges starting after byte zero are excluded.
last_access_at is the time of the latest counted event. Sidecar stats use locked atomic
writes; cached storage sizes avoid rescanning image trees on each dashboard request.
A first load of old uncached metadata still performs a one-time stats initialization.
Old undifferentiated download_count values are retained as legacy_downloads instead
of being guessed as photo downloads. Already classified historical counters are kept.

The rate limiter uses REMOTE_ADDR (configure trusted proxy handling in the server,
not arbitrary forwarded headers), per-IP admin and per-IP/gallery scopes, eight failures
per cooldown and 5/10/20/40/60-minute escalation. Stable lock files prevent races with
state reset/cleanup. Empty lock files can remain; remove those only during maintenance
with all PHP workers stopped.
