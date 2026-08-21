# Gallery Delivery

Self-hosted photo gallery delivery for photographers — secure client galleries, password protection, previews, bulk ZIP downloads, and simple file-based deployment.

A standalone PHP application designed to be uploaded directly to a web server without requiring a database, framework, package manager, or terminal access.

## Features

- private password-protected client galleries
- unique UUID link for every gallery
- optional gallery name
- individual photo downloads
- full gallery ZIP downloads
- JPG, PNG, and WebP uploads
- photo previews
- configurable global gallery background
- per-gallery custom background
- GIF, JPG, PNG, WebP, MP4, and WebM background support
- encrypted recoverable gallery passwords for administrators
- activate or deactivate gallery links
- change gallery passwords
- delete individual photos or complete galleries
- configurable storage disks
- storage usage overview
- improved gallery statistics and storage calculations
- hardened authentication and login protection
- gallery sessions are invalidated after password changes
- improved ZIP generation and download handling
- improved upload workflow
- Apache and Nginx deployment support
- no database required

## Version 5

Version 5 focuses on security, performance, and handling larger galleries.

### Security

- stronger authentication and login protection
- improved rate limiting for login attempts
- gallery access sessions can be invalidated when a password changes
- CSRF protection for administrative actions
- secure password hashing
- administrator-readable gallery passwords are stored encrypted
- uploaded files are validated before being accepted
- protected application directories are not intended to be publicly accessible

### Performance

- improved gallery storage statistics
- reduced unnecessary filesystem calculations
- improved ZIP archive handling
- better handling of large photo collections and downloads

### Gallery management

- gallery names
- generated gallery passwords
- copyable public gallery URLs
- copyable gallery passwords
- gallery activation/deactivation
- custom gallery backgrounds
- configurable storage disks
- individual photo management
- improved upload workflow

## Installation

### Installation without terminal access

1. Upload the entire application folder to your web root, for example:

```text
/var/www/html/downloads/
```

2. Make sure the configured storage location exists and is writable by PHP.

Default example:

```text
/mnt/ssd/photos/downloads/
```

3. Open the application in your browser:

```text
https://your-domain.com/downloads/
```

4. The setup page will check the server configuration.

5. Enter the administrator password twice and complete the setup.

Once setup has been completed, the installation page is locked and the application opens the admin area instead.

## Routes

Default installation under `/downloads/`:

```text
Admin dashboard
/downloads/admin

Public gallery
/downloads/<gallery-uuid>

Gallery storage
/mnt/ssd/photos/downloads/<gallery-uuid>
```

Gallery files are stored outside the public application directory and are served through the application after access verification.

## Server requirements

- PHP 8.2 or newer
- Apache or Nginx
- writable storage directory

### Required PHP extensions

- `fileinfo`
- `openssl`

### Recommended PHP extensions

- `zip`
- `gd`
- `mbstring`

The included `.user.ini` attempts to configure uploads up to 200 MB per file.

Some hosting providers ignore custom `.user.ini` settings. The effective PHP limits can be checked during the initial setup.

## Supported photo formats

Uploads:

- JPEG / JPG
- PNG
- WebP

Maximum configured upload size:

```text
200 MB per file
```

Actual maximum size also depends on the server's PHP configuration.

## Backgrounds

A global background can be configured for client galleries.

Supported background media include:

- GIF
- JPG
- PNG
- WebP
- MP4
- WebM

A direct HTTPS GIF URL can also be used.

Each gallery can either use the global background or override it with its own custom background.

## Gallery passwords

Each gallery has its own password.

Visitor authentication uses a secure password hash.

For administrators, the original gallery password can additionally be stored in encrypted form so it remains available from the admin dashboard.

Changing a gallery password invalidates previously authorized gallery access according to the current authentication state.

## Gallery storage

Gallery metadata is stored in:

```text
gallery.json
```

A gallery directory contains its metadata, original photos, generated previews, and ZIP data required by the application.

The application supports multiple configured storage disks. The administrator can choose which storage location should be used for newly created galleries.

## Downloads

Visitors can download photos individually or download the complete gallery as a ZIP archive.

ZIP handling is designed to avoid unnecessary archive work and provide better behavior for larger galleries.

Download requests remain protected by gallery authentication.

## Security

The physical gallery storage directory must **not** be exposed directly by Apache or Nginx.

Do not create a public alias or symlink such as:

```text
https://your-domain.com/photos/
    -> /mnt/ssd/photos/downloads/
```

Original photos must only be served through the application after gallery authorization.

Production deployments should also prevent direct HTTP access to internal application and deployment files.

Example Nginx configuration is available in:

```text
deploy/nginx.conf.example
```

For Apache, enable:

- `mod_rewrite`
- `.htaccess` overrides

## Upgrade from version 4

1. Back up the application and gallery storage.
2. Leave existing gallery directories and photos unchanged.
3. Replace the web application files with the new version.
4. Keep your existing application configuration.
5. Open the application and verify the admin dashboard.
6. Test an existing gallery, photo download, and ZIP download.

Existing `gallery.json` files are intended to remain compatible where possible.

If an older gallery does not contain a recoverable administrator password, simply set a new gallery password from the admin dashboard.

## Upgrade from version 2 or 3

1. Leave the contents of the gallery storage directory unchanged.
2. Replace the web application files.
3. Open the application.
4. Required encryption data will be initialized automatically where applicable.
5. Older password hashes cannot reveal the original gallery password. Change the gallery password once to make the new password available in the admin area.

## Backup

For a complete backup, preserve both:

```text
Web application files
```

and

```text
Gallery storage directories
```

The gallery storage contains the photos and gallery metadata and should be treated as the primary persistent data.

## Database

There isn't one.

Gallery Delivery intentionally uses a file-based architecture to keep deployment and maintenance simple.

No MySQL, PostgreSQL, SQLite, Composer, Node.js, or framework installation is required for normal operation.

## License

GPL-3.0

---

Yes, the UI may look AI generated.

Yes, it is.

I'm too lazy to do UI stuff.