<?php
declare(strict_types=1);

function request_ip(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function rate_limit_dir(array $config): string
{
    $dir = app_data_dir($config) . '/ratelimits';
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create rate limit storage.');
    }
    if (!is_writable($dir)) {
        throw new RuntimeException('Rate limit storage is not writable.');
    }
    return $dir;
}

function rate_limit_file(array $config, string $type, string $scope): string
{
    $key = hash('sha256', $type . '|' . $scope . '|' . request_ip());
    return rate_limit_dir($config) . '/' . $key . '.json';
}

function rate_limit_gc(array $config): void
{
    if (random_int(1, 100) > 5) {
        return;
    }
    $dir = rate_limit_dir($config);
    $now = time();
    $checked = 0;
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $checked++;
        if ($checked > 200) {
            break;
        }
        $lock = @fopen($file . '.lock', 'c');
        if (!$lock) continue;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) continue;
            $data = read_json_file($file);
            $expires = (int) ($data['expires_at'] ?? 0);
            if ($expires > 0 && $expires <= $now) @unlink($file);
        } finally { fclose($lock); }
    }
}

function rate_limit_default_state(): array
{
    return [
        'fails' => 0,
        'cooldown_level' => 0,
        'locked_until' => 0,
        'last_failed_at' => 0,
        'expires_at' => 0,
    ];
}

function rate_limit_with_lock(string $path, callable $callback): mixed
{
    // Stable lock inode; JSON can be replaced atomically or collected safely.
    $fp = fopen($path . '.lock', 'c+');
    if ($fp === false) {
        throw new RuntimeException('Could not open rate limit file.');
    }

    try {
        if (!flock($fp, LOCK_EX)) {
            throw new RuntimeException('Could not lock rate limit file.');
        }

        $raw = is_file($path) ? file_get_contents($path) : '';
        $state = rate_limit_default_state();
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $state = array_merge($state, $decoded);
            }
        }

        $result = $callback($state);

        write_json_file($path, $state);

        flock($fp, LOCK_UN);
        return $result;
    } finally {
        fclose($fp);
    }
}

function rate_limit_remaining_seconds(array $config, string $type, string $scope = ''): int
{
    rate_limit_gc($config);
    $path = rate_limit_file($config, $type, $scope);
    if (!is_file($path)) {
        return 0;
    }

    return (int) rate_limit_with_lock($path, static function (array &$state): int {
        $now = time();
        $lockedUntil = max(0, (int) ($state['locked_until'] ?? 0));
        if ($lockedUntil <= $now) {
            $state['locked_until'] = 0;
            return 0;
        }
        return $lockedUntil - $now;
    });
}

function rate_limit_register_failure(array $config, string $type, string $scope = ''): int
{
    rate_limit_gc($config);
    $path = rate_limit_file($config, $type, $scope);

    return (int) rate_limit_with_lock($path, static function (array &$state): int {
        $now = time();

        $fails = max(0, (int) ($state['fails'] ?? 0));
        $level = max(0, (int) ($state['cooldown_level'] ?? 0));
        $lockedUntil = max(0, (int) ($state['locked_until'] ?? 0));
        $lastFailedAt = max(0, (int) ($state['last_failed_at'] ?? 0));

        if ($lockedUntil > $now) {
            $state['expires_at'] = $lockedUntil + 86400;
            return $lockedUntil - $now;
        }

        if ($lastFailedAt > 0 && ($now - max($lastFailedAt, $lockedUntil)) > 86400) {
            $fails = 0;
            $level = max(0, $level - 1);
        }

        $fails++;
        $lockSeconds = 0;

        if ($fails >= 8) {
            $level = min(8, $level + 1);
            $lockSeconds = min(3600, 300 * (2 ** max(0, $level - 1)));
            $fails = 0;
            $lockedUntil = $now + $lockSeconds;
            $state['locked_until'] = $lockedUntil;
        } else {
            $state['locked_until'] = $lockedUntil;
        }

        $state['fails'] = $fails;
        $state['cooldown_level'] = $level;
        $state['last_failed_at'] = $now;
        $state['expires_at'] = max($now + 86400, (int) ($state['locked_until'] ?? 0) + 86400);

        return $lockSeconds;
    });
}

function rate_limit_reset(array $config, string $type, string $scope = ''): void
{
    $path = rate_limit_file($config, $type, $scope);
    rate_limit_with_lock($path, static function (array &$state): void {
        $state = rate_limit_default_state();
        $state['expires_at'] = time() + 86400;
    });
}
