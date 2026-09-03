<?php

declare(strict_types=1);

function app_debug_enabled(): bool
{
    return env('APP_DEBUG', '0') === '1';
}

function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    $fwd = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return $fwd === 'https';
}

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    if (!app_debug_enabled()) {
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        $logDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0750, true);
        }
        ini_set('error_log', $logDir . DIRECTORY_SEPARATOR . 'php.log');
    }
    ini_set('allow_url_include', '0');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.tailwindcss.com; "
        . "font-src 'self' https://fonts.gstatic.com data:; "
        . "img-src 'self' data: blob:; "
        . "connect-src 'self' https://cdn.tailwindcss.com; "
        . "frame-ancestors 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'"
    );
}

function is_safe_internal_path(string $url): bool
{
    $url = trim($url);
    if ($url === '' || str_contains($url, '\\') || str_contains($url, "\0")) {
        return false;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
        return false;
    }
    if (!str_starts_with($url, '/') || str_starts_with($url, '//')) {
        return false;
    }
    return true;
}

function safe_internal_redirect(string $url, string $fallback): void
{
    if (!is_safe_internal_path($fallback)) {
        $fallback = '/';
    }
    $dest = is_safe_internal_path($url) ? $url : $fallback;
    header('Location: ' . $dest, true, 302);
    exit;
}

function client_ip_address(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip !== '' ? $ip : '0.0.0.0';
}

function rate_limit_bucket(string $action): string
{
    return $action . ':' . client_ip_address();
}

function rate_limit_is_blocked(string $action, int $max = 10, int $windowSec = 900): bool
{
    try {
        if (!is_installed() || !database_ready()) {
            return false;
        }
        $bucket = rate_limit_bucket($action);
        $stmt = db()->prepare('SELECT hits, window_start FROM rate_limits WHERE bucket = ? LIMIT 1');
        $stmt->execute([$bucket]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        $start = strtotime((string) $row['window_start']) ?: 0;
        if (time() - $start > $windowSec) {
            db()->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([$bucket]);
            return false;
        }
        return (int) $row['hits'] >= $max;
    } catch (Throwable $e) {
        return false;
    }
}

function rate_limit_fail(string $action): void
{
    try {
        if (!is_installed() || !database_ready()) {
            return;
        }
        $bucket = rate_limit_bucket($action);
        $now = date('Y-m-d H:i:s');
        $stmt = db()->prepare('SELECT hits, window_start FROM rate_limits WHERE bucket = ? LIMIT 1');
        $stmt->execute([$bucket]);
        $row = $stmt->fetch();
        if (!$row) {
            db()->prepare('INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?,?,?)')
                ->execute([$bucket, 1, $now]);
            return;
        }
        $start = strtotime((string) $row['window_start']) ?: 0;
        if (time() - $start > 900) {
            db()->prepare('UPDATE rate_limits SET hits = 1, window_start = ? WHERE bucket = ?')
                ->execute([$now, $bucket]);
            return;
        }
        db()->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?')->execute([$bucket]);
    } catch (Throwable $e) {
        // ignore
    }
}

function rate_limit_clear(string $action): void
{
    try {
        if (!is_installed() || !database_ready()) {
            return;
        }
        db()->prepare('DELETE FROM rate_limits WHERE bucket = ?')->execute([rate_limit_bucket($action)]);
    } catch (Throwable $e) {
        // ignore
    }
}

function rate_limit_reject_message(): string
{
    return 'Muitas tentativas. Aguarde alguns minutos e tente de novo.';
}

function honeypot_tripped(): bool
{
    $v = trim((string) ($_POST['website'] ?? ''));
    return $v !== '';
}

function agent_request_token(array $body = []): string
{
    $header = trim((string) ($_SERVER['HTTP_X_AGENT_TOKEN'] ?? ''));
    if ($header !== '') {
        return $header;
    }
    $fromBody = trim((string) ($body['token'] ?? ''));
    if ($fromBody !== '') {
        return $fromBody;
    }
    return trim((string) ($_GET['token'] ?? ''));
}
