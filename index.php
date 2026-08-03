<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

$path = route_path($config);
$method = request_method();

// Static assets are normally served by Apache/Nginx before this router.
if (str_starts_with($path, '/assets/')) {
    http_response_code(404);
    exit;
}

try {
    if (!app_is_installed($config)) {
        if ($path !== '/' && $path !== '' && $path !== '/setup') {
            redirect_to($config, '');
        }

        $checks = setup_checks($config);
        if ($method === 'POST') {
            verify_csrf();
            if (setup_has_blockers($checks)) {
                throw new RuntimeException('The server does not yet meet the required installation requirements.');
            }
            $password = (string) ($_POST['password'] ?? '');
            $confirmation = (string) ($_POST['password_confirmation'] ?? '');
            if (app_strlen($password) < 10) {
                throw new RuntimeException('The admin password must be at least 10 characters long.');
            }
            if (!hash_equals($password, $confirmation)) {
                throw new RuntimeException('The entered passwords do not match.');
            }
            complete_initial_setup($config, $password);
            session_regenerate_id(true);
            $_SESSION['admin_authenticated'] = true;
            flash('success', 'Setup completed.');
            redirect_to($config, 'admin');
        }

        render_setup_page($config, $checks);
        exit;
    }

    if ($path === '/setup') {
        redirect_to($config, 'admin');
    }

    ensure_storage_layout($config);

    if ($path === '/admin' && $method === 'GET') {
        if (!is_admin()) {
            render_header($config, 'Admin login', false, 'admin-login-page');
            echo '<main class="login-card"><h1>Admin dashboard</h1><p class="muted">Manage your private galleries</p>';
            render_flashes();
            if (empty($config['admin_password_hash'])) {
                echo '<div class="flash error">Setup is not complete. Open the main address <code>' . e(base_url($config)) . '</code>.</div>';
            }
            echo '<form method="post" action="' . e(base_url($config, 'admin/login')) . '"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
            echo '<label>Password<input type="password" name="password" required autocomplete="current-password" autofocus></label>';
            echo '<button class="primary wide">Sign in</button></form></main>';
            render_footer($config);
            exit;
        }
        $galleries = list_galleries($config);
        $free = @disk_free_space((string) $config['storage_root']);
        $total = @disk_total_space((string) $config['storage_root']);
        render_header($config, 'Galleries', true, 'admin-page');
        echo '<main class="container">'; render_flashes();
        echo '<section class="hero-card"><div><p class="eyebrow">Overview</p><h1>Galleries and storage</h1><p class="muted">Manage photos, ZIP files, and the active storage disk from one place.</p></div><div class="hero-actions"><a class="button" href="' . e(base_url($config, 'admin/settings')) . '">Settings</a><a class="button primary" href="' . e(base_url($config, 'admin/create')) . '">New gallery</a></div></section>';
        if ($free !== false && $total !== false) {
            $usedPct = $total > 0 ? (int) round((1 - $free / $total) * 100) : 0;
            echo '<section class="storage-card"><div><strong>Active disk</strong><span>' . e((string) $config['storage_root']) . '</span></div><div class="storage-right">' . format_bytes((int) $free) . ' free · ' . $usedPct . '% used</div></section>';
        }
        echo '<section class="top-row"><div><h1>Galleries</h1><p class="muted">' . count($galleries) . ' items</p></div></section>';
        echo '<section class="gallery-list">';
        if (!$galleries) echo '<div class="empty">No galleries have been created yet.</div>';
        foreach ($galleries as $gallery) {
            $uuid = (string) $gallery['id'];
            $active = !empty($gallery['active']);
            echo '<a class="gallery-row" href="' . e(base_url($config, 'admin/gallery/' . $uuid)) . '">';
            echo '<div><code>' . e($uuid) . '</code><div class="row-meta">' . count($gallery['files'] ?? []) . ' photos · ' . format_bytes((int) ($gallery['_disk_bytes'] ?? 0)) . ' · ' . (int) ($gallery['download_count'] ?? 0) . ' downloads</div></div>';
            echo '<span class="status ' . ($active ? 'on' : 'off') . '">' . ($active ? 'Active' : 'Disabled') . '</span></a>';
        }
        echo '</section></main>';
        render_footer($config); exit;
    }

    if ($path === '/admin/login' && $method === 'POST') {
        verify_csrf();
        $password = (string) ($_POST['password'] ?? '');
        $attempts = (int) ($_SESSION['admin_login_attempts'] ?? 0);
        $lockedUntil = (int) ($_SESSION['admin_locked_until'] ?? 0);
        if ($lockedUntil > time()) {
            flash('error', 'Too many attempts. Please try again later.');
            redirect_to($config, 'admin');
        }
        if (!empty($config['admin_password_hash']) && password_verify($password, (string) $config['admin_password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin_authenticated'] = true;
            unset($_SESSION['admin_login_attempts'], $_SESSION['admin_locked_until']);
            redirect_to($config, 'admin');
        }
        $attempts++;
        $_SESSION['admin_login_attempts'] = $attempts;
        if ($attempts >= 8) {
            $_SESSION['admin_locked_until'] = time() + 300;
            $_SESSION['admin_login_attempts'] = 0;
        }
        flash('error', 'Incorrect password.');
        redirect_to($config, 'admin');
    }

    if ($path === '/admin/logout' && $method === 'POST') {
        verify_csrf();
        unset($_SESSION['admin_authenticated']);
        session_regenerate_id(true);
        redirect_to($config, 'admin');
    }

    if ($path === '/admin/create' && $method === 'GET') {
        require_admin($config);
        $generated = random_password();
        render_header($config, 'New gallery', true, 'admin-page');
        echo '<main class="container narrow">'; render_flashes();
        echo '<h1>New gallery</h1><p class="muted">The gallery will not have a name. The password will remain permanently available to the administrator in the gallery details.</p>';
        echo '<form class="panel" method="post" action="' . e(base_url($config, 'admin/create')) . '"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
        echo '<label>Gallery password<input type="text" name="password" minlength="8" maxlength="100" value="' . e($generated) . '" required></label>';
        echo '<button class="primary">Create gallery</button></form></main>';
        render_footer($config); exit;
    }

    if ($path === '/admin/create' && $method === 'POST') {
        require_admin($config); verify_csrf();
        $password = trim((string) ($_POST['password'] ?? ''));
        if (app_strlen($password) < 8) throw new RuntimeException('The password must be at least 8 characters long.');
        $gallery = create_gallery($config, $password);
        redirect_to($config, 'admin/gallery/' . $gallery['id']);
    }

    if (preg_match('#^/admin/gallery/([0-9a-f-]+)$#i', $path, $m) && $method === 'GET') {
        require_admin($config);
        $gallery = load_gallery($config, $m[1]);
        if (!$gallery) { http_response_code(404); exit('Gallery not found.'); }
        $uuid = (string) $gallery['id'];
        $shownPassword = gallery_admin_password($config, $gallery);
        render_header($config, 'Gallery details', true, 'admin-page');
        echo '<main class="container">'; render_flashes();
        echo '<section class="top-row"><div><h1>Gallery details</h1><code>' . e($uuid) . '</code></div><a class="button" target="_blank" rel="noopener" href="' . e(base_url($config, $uuid)) . '">Open gallery</a></section>';
        echo '<div class="secret-box"><strong>Public link</strong><div><code id="gallery-link">' . e(absolute_url($config, $uuid)) . '</code><button type="button" class="small" data-copy="#gallery-link">Copy</button></div></div>';
        if ($shownPassword !== null) {
            echo '<div class="secret-box"><strong>Gallery password</strong><div><code id="gallery-password">' . e($shownPassword) . '</code><button type="button" class="small" data-copy="#gallery-password">Copy</button></div></div>';
        } else {
            echo '<div class="secret-box"><strong>Gallery password</strong><p class="muted">For older galleries, the original password cannot be recovered. Set a new password below; it will then remain visible in the admin area.</p></div>';
        }
        echo '<section class="stats-grid"><div><span>Photos</span><strong>' . count($gallery['files'] ?? []) . '</strong></div><div><span>Downloads</span><strong>' . (int) ($gallery['download_count'] ?? 0) . '</strong></div><div><span>ZIP</span><strong>' . (!empty($gallery['zip']['ready']) ? format_bytes((int) $gallery['zip']['size']) : 'none') . '</strong></div><div><span>Status</span><strong>' . (!empty($gallery['active']) ? 'Active' : 'Disabled') . '</strong></div></section>';
        echo '<section class="panel"><h2>Upload photos</h2><p class="muted">JPG, PNG, or WebP, up to 200 MB per file.</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . e(base_url($config, 'admin/gallery/' . $uuid . '/upload')) . '"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><label class="dropzone">Select photos<input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required></label><button class="primary">Upload and rebuild ZIP</button></form></section>';
        $galleryBackground = is_array($gallery['background'] ?? null) ? $gallery['background'] : ['mode' => 'global'];
        $backgroundMode = (($galleryBackground['mode'] ?? 'global') === 'custom') ? 'custom' : 'global';
        $customBackgroundUrl = $backgroundMode === 'custom' ? (string) ($galleryBackground['url'] ?? '') : '';
        $globalBackground = current_background($config);
        echo '<section class="panel"><h2>Gallery background</h2><p class="muted">The gallery can use the global background or a custom direct HTTPS GIF link.</p>';
        if ($globalBackground && ($globalBackground['source'] ?? '') === 'url') {
            echo '<p class="muted">Global GIF: <code>' . e((string) ($globalBackground['url'] ?? '')) . '</code></p>';
        } elseif ($globalBackground) {
            echo '<p class="muted">Global background: <strong>' . e((string) ($globalBackground['file'] ?? 'uploaded file')) . '</strong></p>';
        } else {
            echo '<p class="muted">No global background is set yet.</p>';
        }
        echo '<form method="post" action="' . e(base_url($config, 'admin/gallery/' . $uuid . '/background')) . '"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
        echo '<label>Background mode<select name="background_mode"><option value="global"' . ($backgroundMode === 'global' ? ' selected' : '') . '>Use global background</option><option value="custom"' . ($backgroundMode === 'custom' ? ' selected' : '') . '>Custom GIF for this gallery</option></select></label>';
        echo '<label>Custom HTTPS GIF link<input type="url" name="background_url" maxlength="2048" placeholder="https://example.com/auto.gif" value="' . e($customBackgroundUrl) . '"></label>';
        echo '<button>Save gallery background</button></form></section>';
        echo '<section class="actions panel"><h2>Settings</h2><div class="action-row">';
        echo '<form method="post" action="' . e(base_url($config, 'admin/gallery/' . $uuid . '/toggle')) . '"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><button>' . (!empty($gallery['active']) ? 'Deactivate link' : 'Activate link') . '</button></form>';
        echo '<form method="post" action="' . e(base_url($config, 'admin/gallery/' . $uuid . '/zip')) . '"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><button>Regenerate ZIP</button></form></div>';
        echo '<form class="inline-form" method="post" action="' . e(base_url($config, 'admin/gallery/' . $uuid . '/password')) . '"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><label>New password<input type="text" name="password" minlength="8" maxlength="100" value="' . e(random_password()) . '" required></label><button>Change password</button></form>';
        echo '<form class="danger-zone" method="post" action="' . e(base_url($config, 'admin/gallery/' . $uuid . '/delete')) . '" data-confirm="Really delete the gallery including all photos?"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><button class="danger">Delete gallery</button></form></section>';
        echo '<section><h2>Photos</h2><div class="admin-photo-grid">';
        if (empty($gallery['files'])) echo '<div class="empty">The gallery is empty.</div>';
        foreach ($gallery['files'] ?? [] as $file) {
            $fid = (string) $file['id'];
            echo '<article class="admin-photo"><img loading="lazy" src="' . e(base_url($config, $uuid . '/preview/' . $fid . '?admin=1')) . '" alt=""><div><strong title="' . e((string) $file['original_name']) . '">' . e((string) $file['original_name']) . '</strong><span>' . format_bytes((int) $file['size']) . '</span></div><form method="post" action="' . e(base_url($config, 'admin/gallery/' . $uuid . '/photo/' . $fid . '/delete')) . '" data-confirm="Delete this photo?"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><button class="danger small">Delete</button></form></article>';
        }
        echo '</div></section></main>';
        render_footer($config); exit;
    }

    if (preg_match('#^/admin/gallery/([0-9a-f-]+)/upload$#i', $path, $m) && $method === 'POST') {
        require_admin($config); verify_csrf();
        $gallery = load_gallery($config, $m[1]); if (!$gallery) throw new RuntimeException('Gallery not found.');
        if (empty($_FILES['photos'])) throw new RuntimeException('No file was selected.');
        $gallery = add_uploaded_photos($config, $gallery, $_FILES['photos']);
        try { $gallery = build_gallery_zip($config, $gallery); flash('success', 'Photos were uploaded and the ZIP was created.'); }
        catch (Throwable $e) { flash('warning', 'Photos were uploaded, but the ZIP was not created: ' . $e->getMessage()); }
        redirect_to($config, 'admin/gallery/' . $gallery['id']);
    }

    if (preg_match('#^/admin/gallery/([0-9a-f-]+)/toggle$#i', $path, $m) && $method === 'POST') {
        require_admin($config); verify_csrf();
        $gallery = load_gallery($config, $m[1]); if (!$gallery) throw new RuntimeException('Gallery not found.');
        $gallery['active'] = empty($gallery['active']); save_gallery($config, $gallery);
        flash('success', $gallery['active'] ? 'The gallery is active.' : 'The gallery was deactivated.');
        redirect_to($config, 'admin/gallery/' . $gallery['id']);
    }

    if (preg_match('#^/admin/gallery/([0-9a-f-]+)/zip$#i', $path, $m) && $method === 'POST') {
        require_admin($config); verify_csrf();
        $gallery = load_gallery($config, $m[1]); if (!$gallery) throw new RuntimeException('Gallery not found.');
        build_gallery_zip($config, $gallery); flash('success', 'ZIP was created.');
        redirect_to($config, 'admin/gallery/' . $gallery['id']);
    }

    if (preg_match('#^/admin/gallery/([0-9a-f-]+)/background$#i', $path, $m) && $method === 'POST') {
        require_admin($config); verify_csrf();
        $gallery = load_gallery($config, $m[1]); if (!$gallery) throw new RuntimeException('Gallery not found.');
        $mode = (string) ($_POST['background_mode'] ?? 'global');
        $url = (string) ($_POST['background_url'] ?? '');
        save_gallery_background($config, $gallery, $mode, $url);
        flash('success', $mode === 'custom' ? 'The gallery custom background was saved.' : 'The gallery is using the global background again.');
        redirect_to($config, 'admin/gallery/' . $gallery['id']);
    }

    if (preg_match('#^/admin/gallery/([0-9a-f-]+)/password$#i', $path, $m) && $method === 'POST') {
        require_admin($config); verify_csrf();
        $gallery = load_gallery($config, $m[1]); if (!$gallery) throw new RuntimeException('Gallery not found.');
        $password = trim((string) ($_POST['password'] ?? ''));
        if (app_strlen($password) < 8) throw new RuntimeException('The password must be at least 8 characters long.');
        $gallery['password_hash'] = secure_password_hash($password);
        $gallery['password_admin'] = encrypt_admin_value($config, $password);
        save_gallery($config, $gallery);
        flash('success', 'The password was changed and will remain visible in the admin area.');
        redirect_to($config, 'admin/gallery/' . $gallery['id']);
    }

    if (preg_match('#^/admin/gallery/([0-9a-f-]+)/delete$#i', $path, $m) && $method === 'POST') {
        require_admin($config); verify_csrf(); delete_gallery($config, $m[1]);
        flash('success', 'The gallery was deleted.'); redirect_to($config, 'admin');
    }

    if (preg_match('#^/admin/gallery/([0-9a-f-]+)/photo/([0-9a-f]+)/delete$#i', $path, $m) && $method === 'POST') {
        require_admin($config); verify_csrf();
        $gallery = load_gallery($config, $m[1]); if (!$gallery) throw new RuntimeException('Gallery not found.');
        $gallery = remove_photo($config, $gallery, $m[2]);
        try { build_gallery_zip($config, $gallery); } catch (Throwable $e) { flash('warning', 'The photo was deleted, but the ZIP needs to be regenerated: ' . $e->getMessage()); }
        flash('success', 'The photo was deleted.'); redirect_to($config, 'admin/gallery/' . $gallery['id']);
    }

    if ($path === '/admin/settings' && $method === 'GET') {
        require_admin($config);
        $settings = load_app_settings($config);
        $disks = array_values(array_unique(array_filter(array_map(fn($disk) => trim((string) $disk), (array) ($settings['storage_disks'] ?? [])), fn($disk) => $disk !== '')));
        if (!$disks) {
            $disks[] = (string) $config['storage_root'];
        }
        $activeDisk = trim((string) ($settings['active_storage_disk'] ?? $config['storage_root']));
        if ($activeDisk === '' || !in_array($activeDisk, $disks, true)) {
            $activeDisk = $disks[0];
        }
        $appName = (string) ($settings['app_name'] ?? $config['app_name'] ?? 'Downloads');
        render_header($config, 'Settings', true, 'admin-page');
        echo '<main class="container narrow">'; render_flashes();
        echo '<section class="hero-card"><div><p class="eyebrow">Settings</p><h1>Disks and basic settings</h1><p class="muted">Add additional disks and choose the active storage location for new galleries.</p></div></section>';
        echo '<section class="panel"><h2>Active disk</h2><div class="disk-list">';
        foreach ($disks as $disk) {
            $isActive = $disk === $activeDisk;
            echo '<article class="disk-card' . ($isActive ? ' active' : '') . '"><div><strong>' . e($disk) . '</strong><small>' . ($isActive ? 'Active storage location' : 'Available disk') . '</small></div><span class="pill' . ($isActive ? ' active' : '') . '">' . ($isActive ? 'Active' : 'Available') . '</span></article>';
        }
        echo '</div></section>';
        echo '<section class="panel"><h2>Add disk</h2><form method="post" action="' . e(base_url($config, 'admin/settings/add-disk')) . '"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><label>New disk path<input type="text" name="new_disk_path" placeholder="/mnt/ssd/photos/downloads-2" required></label><button class="primary">Add disk</button></form></section>';
        echo '<section class="panel"><h2>Save settings</h2><form method="post" action="' . e(base_url($config, 'admin/settings')) . '"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><label>Application name<input type="text" name="app_name" maxlength="80" value="' . e($appName) . '"></label><label>Active disk<select name="active_storage_disk">';
        foreach ($disks as $disk) {
            echo '<option value="' . e($disk) . '"' . ($disk === $activeDisk ? ' selected' : '') . '>' . e($disk) . '</option>';
        }
        echo '</select></label><button class="primary">Save settings</button></form></section></main>';
        render_footer($config); exit;
    }

    if ($path === '/admin/settings' && $method === 'POST') {
        require_admin($config); verify_csrf();
        $settings = load_app_settings($config);
        $disks = array_values(array_unique(array_filter(array_map(fn($disk) => trim((string) $disk), (array) ($settings['storage_disks'] ?? [])), fn($disk) => $disk !== '')));
        if (!$disks) {
            $disks[] = (string) $config['storage_root'];
        }
        $activeDisk = trim((string) ($_POST['active_storage_disk'] ?? ''));
        if ($activeDisk === '' || !in_array($activeDisk, $disks, true)) {
            $activeDisk = $disks[0];
        }
        $settings = array_merge($settings, [
            'app_name' => trim((string) ($_POST['app_name'] ?? 'Downloads')) !== '' ? trim((string) ($_POST['app_name'] ?? 'Downloads')) : 'Downloads',
            'storage_disks' => $disks,
            'active_storage_disk' => $activeDisk,
            'updated_at' => date(DATE_ATOM),
        ]);
        write_app_settings($config, $settings);
        $config['storage_root'] = $activeDisk;
        ensure_storage_layout($config);
        flash('success', 'Settings were saved.');
        redirect_to($config, 'admin/settings');
    }

    if ($path === '/admin/settings/add-disk' && $method === 'POST') {
        require_admin($config); verify_csrf();
        $settings = load_app_settings($config);
        $newDisk = trim((string) ($_POST['new_disk_path'] ?? ''));
        if ($newDisk === '') {
            throw new RuntimeException('Please enter a disk path.');
        }
        $disks = array_values(array_unique(array_filter(array_map(fn($disk) => trim((string) $disk), (array) ($settings['storage_disks'] ?? [])), fn($disk) => $disk !== '')));
        if (!in_array($newDisk, $disks, true)) {
            $disks[] = $newDisk;
        }
        $activeDisk = trim((string) ($settings['active_storage_disk'] ?? $config['storage_root']));
        if ($activeDisk === '' || !in_array($activeDisk, $disks, true)) {
            $activeDisk = $newDisk;
        }
        $settings = array_merge($settings, [
            'storage_disks' => $disks,
            'active_storage_disk' => $activeDisk,
            'updated_at' => date(DATE_ATOM),
        ]);
        write_app_settings($config, $settings);
        $config['storage_root'] = $activeDisk;
        ensure_storage_layout($config);
        flash('success', 'The disk was added.');
        redirect_to($config, 'admin/settings');
    }

    if ($path === '/admin/background' && $method === 'GET') {
        require_admin($config); $background = current_background($config);
        render_header($config, 'Background', true, 'admin-page');
        echo '<main class="container narrow">'; render_flashes(); echo '<h1>Global background</h1>';
        echo '<section class="panel"><h2>GIF via URL</h2><p class="muted">Insert a direct public HTTPS URL to a GIF. It will be used by default for all galleries that do not have their own background.</p>';
        if ($background && ($background['source'] ?? '') === 'url') {
            echo '<p>Current link:</p><div class="secret-box compact"><code id="background-url">' . e((string) $background['url']) . '</code><button type="button" class="small" data-copy="#background-url">Copy</button></div>';
        } elseif ($background) {
            echo '<p>The uploaded background is currently in use: <strong>' . e((string) ($background['file'] ?? 'file')) . '</strong></p>';
        }
        echo '<form method="post" action="' . e(base_url($config, 'admin/background/url')) . '"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><label>HTTPS GIF URL<input type="url" name="background_url" maxlength="2048" placeholder="https://example.com/auto.gif" value="' . e((string) (($background['source'] ?? '') === 'url' ? ($background['url'] ?? '') : '')) . '" required></label><button class="primary">Save link</button></form></section>';
        echo '<section class="panel"><h2>Alternative: upload a file</h2><p class="muted">You can also upload a GIF, JPG, PNG, WebP, MP4, or WebM file directly to the storage disk.</p><form method="post" enctype="multipart/form-data" action="' . e(base_url($config, 'admin/background/upload')) . '"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><label class="dropzone">Select background<input type="file" name="background" accept="image/gif,image/jpeg,image/png,image/webp,video/mp4,video/webm" required></label><button>Save file</button></form></section></main>';
        render_footer($config); exit;
    }

    if ($path === '/admin/background/url' && $method === 'POST') {
        require_admin($config); verify_csrf();
        save_background_url($config, (string) ($_POST['background_url'] ?? ''));
        flash('success', 'The background link was saved.');
        redirect_to($config, 'admin/background');
    }

    if ($path === '/admin/background/upload' && $method === 'POST') {
        require_admin($config); verify_csrf();
        if (empty($_FILES['background'])) throw new RuntimeException('No file was selected.');
        save_background_upload($config, $_FILES['background']); flash('success', 'The background was changed.'); redirect_to($config, 'admin/background');
    }

    if ($path === '/_background' && $method === 'GET') {
        $bg = current_background($config); if (!$bg) { http_response_code(404); exit; }
        $file = rtrim((string) $config['storage_root'], '/') . '/_background/' . basename((string) $bg['file']);
        if (!is_file($file)) { http_response_code(404); exit; }
        header('Content-Type: ' . (string) $bg['mime']);
        header('Cache-Control: public, max-age=3600');
        header('Content-Length: ' . filesize($file)); readfile($file); exit;
    }

    if (preg_match('#^/([0-9a-f-]+)/unlock$#i', $path, $m) && $method === 'POST') {
        verify_csrf(); $gallery = load_gallery($config, $m[1]);
        if (!$gallery || empty($gallery['active'])) { http_response_code(404); exit('The gallery is not available.'); }
        $attempts = (int) ($_SESSION['gallery_attempts_' . $m[1]] ?? 0);
        $locked = (int) ($_SESSION['gallery_locked_' . $m[1]] ?? 0);
        if ($locked > time()) { flash('error', 'Too many attempts. Please try again later.'); redirect_to($config, $m[1]); }
        if (password_verify((string) ($_POST['password'] ?? ''), (string) $gallery['password_hash'])) {
            set_gallery_unlocked((string) $gallery['id']); unset($_SESSION['gallery_attempts_' . $m[1]], $_SESSION['gallery_locked_' . $m[1]]); redirect_to($config, $m[1]);
        }
        $attempts++; $_SESSION['gallery_attempts_' . $m[1]] = $attempts;
        if ($attempts >= 8) { $_SESSION['gallery_locked_' . $m[1]] = time() + 300; $_SESSION['gallery_attempts_' . $m[1]] = 0; }
        flash('error', 'Incorrect password.'); redirect_to($config, $m[1]);
    }

    if (preg_match('#^/([0-9a-f-]+)$#i', $path, $m) && $method === 'GET') {
        $gallery = load_gallery($config, $m[1]);
        if (!$gallery || empty($gallery['active'])) { http_response_code(404); exit('The gallery is not available.'); }
        render_header($config, 'Private gallery', false, 'gallery-page'); render_background($config, $gallery);
        echo '<main class="gallery-shell">'; render_flashes();
        if (!gallery_is_unlocked((string) $gallery['id'])) {
            echo '<section class="unlock-card"><div class="lock-icon">↓</div><h1>Private gallery</h1><p>Enter the password to view the photos.</p><form method="post" action="' . e(base_url($config, $gallery['id'] . '/unlock')) . '"><input type="hidden" name="csrf" value="' . e(csrf_token()) . '"><input type="password" name="password" placeholder="Password" required autofocus autocomplete="current-password"><button class="primary wide">Unlock</button></form></section>';
        } else {
            echo '<section class="gallery-toolbar"><div><h1>Photos</h1><span>' . count($gallery['files'] ?? []) . ' files</span></div>';
            if (!empty($gallery['zip']['ready'])) echo '<a class="button primary" href="' . e(base_url($config, $gallery['id'] . '/full.zip')) . '">Download all · ' . format_bytes((int) $gallery['zip']['size']) . '</a>';
            echo '</section><section class="public-photo-grid">';
            foreach ($gallery['files'] ?? [] as $file) {
                $fid = (string) $file['id'];
                echo '<article class="public-photo"><a href="' . e(base_url($config, $gallery['id'] . '/photo/' . $fid)) . '" title="Download ' . e((string) $file['original_name']) . '"><img loading="lazy" src="' . e(base_url($config, $gallery['id'] . '/preview/' . $fid)) . '" alt=""><span>Download</span></a></article>';
            }
            if (empty($gallery['files'])) echo '<div class="empty glass">The gallery is empty.</div>';
            echo '</section>';
        }
        echo '</main>'; render_footer($config); exit;
    }

    if (preg_match('#^/([0-9a-f-]+)/(photo|preview)/([0-9a-f]+)$#i', $path, $m) && $method === 'GET') {
        $gallery = load_gallery($config, $m[1]);
        $adminOverride = is_admin() && isset($_GET['admin']);
        if (!$gallery || (!$adminOverride && (empty($gallery['active']) || !gallery_is_unlocked((string) $gallery['id'])))) { http_response_code(403); exit('Access denied.'); }
        $file = null; foreach ($gallery['files'] ?? [] as $candidate) if (($candidate['id'] ?? '') === $m[3]) { $file = $candidate; break; }
        if (!$file) { http_response_code(404); exit; }
        $dir = gallery_dir($config, (string) $gallery['id']);
        if ($m[2] === 'preview' && !empty($file['preview']) && is_file($dir . '/previews/' . basename((string) $file['preview']))) {
            $pathName = $dir . '/previews/' . basename((string) $file['preview']); $mime = 'image/jpeg'; $disposition = 'inline'; $name = 'preview.jpg';
        } else {
            $pathName = $dir . '/originals/' . basename((string) $file['stored_name']); $mime = (string) $file['mime']; $disposition = $m[2] === 'photo' ? 'attachment' : 'inline'; $name = (string) $file['original_name'];
        }
        if (!is_file($pathName)) { http_response_code(404); exit; }
        header('Content-Type: ' . $mime); header('Content-Length: ' . filesize($pathName)); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . $disposition . '; filename="' . safe_download_name($name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        if ($m[2] === 'photo' && !$adminOverride) {
            $gallery['download_count'] = (int) ($gallery['download_count'] ?? 0) + 1;
            save_gallery($config, $gallery);
        }
        readfile($pathName); exit;
    }

    if (preg_match('#^/([0-9a-f-]+)/full\.zip$#i', $path, $m) && $method === 'GET') {
        $gallery = load_gallery($config, $m[1]);
        if (!$gallery || empty($gallery['active']) || !gallery_is_unlocked((string) $gallery['id'])) { http_response_code(403); exit('Access denied.'); }
        $file = gallery_dir($config, (string) $gallery['id']) . '/full.zip';
        if (empty($gallery['zip']['ready']) || !is_file($file)) { http_response_code(404); exit('The ZIP is not ready.'); }
        $gallery['download_count'] = (int) ($gallery['download_count'] ?? 0) + 1; save_gallery($config, $gallery);
        header('Content-Type: application/zip'); header('Content-Length: ' . filesize($file)); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="photos-' . $gallery['id'] . '.zip"'); readfile($file); exit;
    }

    if ($path === '/' || $path === '') {
        redirect_to($config, 'admin');
    }

    http_response_code(404); echo 'Page not found.';
} catch (Throwable $e) {
    if (!app_is_installed($config)) {
        flash('error', $e->getMessage());
        redirect_to($config, '');
    }
    if (str_starts_with($path, '/admin')) {
        flash('error', $e->getMessage());
        $fallback = preg_match('#^/admin/gallery/([0-9a-f-]+)#i', $path, $m) ? 'admin/gallery/' . $m[1] : 'admin';
        redirect_to($config, $fallback);
    }
    http_response_code(500);
    echo 'Error: ' . e($e->getMessage());
}
