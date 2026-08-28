<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/app/helpers.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . trim($path, '/');
if ($path === '/') {
    $path = '/';
}

if (!is_installed() && !str_starts_with($path, '/install') && !str_starts_with($path, '/assets')) {
    header('Location: /install');
    exit;
}

if (is_installed()) {
    require_once __DIR__ . '/app/helpers.php';
}

switch (true) {
    case $path === '/install':
        require __DIR__ . '/app/pages/install.php';
        break;
    case $path === '/assets/app.css':
        header('Content-Type: text/css; charset=utf-8');
        readfile(__DIR__ . '/public/assets/app.css');
        break;
    case $path === '/assets/portal.js':
        header('Content-Type: application/javascript; charset=utf-8');
        readfile(__DIR__ . '/public/assets/portal.js');
        break;
    case $path === '/assets/admin.js':
        header('Content-Type: application/javascript; charset=utf-8');
        readfile(__DIR__ . '/public/assets/admin.js');
        break;
    case $path === '/api/session':
        require __DIR__ . '/app/api/session.php';
        break;
    case $path === '/api/confirm':
        require __DIR__ . '/app/api/confirm.php';
        break;
    case $path === '/agent/sync':
        require __DIR__ . '/app/api/agent-sync.php';
        break;
    case $path === '/agent/brand':
        require __DIR__ . '/app/pages/agent-brand.php';
        break;
    case $path === '/brand.png':
        if (!empty($_SESSION['admin']) && (int) ($_GET['store'] ?? 0) > 0) {
            $GLOBALS['force_store_id'] = (int) $_GET['store'];
        }
        $brand = brand_image_path();
        if (!is_file($brand)) {
            http_response_code(404);
            break;
        }
        header('Content-Type: image/png');
        header('Cache-Control: private, max-age=3600');
        readfile($brand);
        break;
    case preg_match('#^/story/([A-Z0-9]+)\.png$#', $path, $m) === 1:
        require __DIR__ . '/app/pages/story.php';
        render_story($m[1]);
        break;
    case $path === '/admin' || $path === '/admin/':
        require __DIR__ . '/app/pages/admin.php';
        break;
    case $path === '/admin/login':
        require __DIR__ . '/app/pages/admin-login.php';
        break;
    case $path === '/admin/logout':
        $_SESSION = [];
        session_destroy();
        header('Location: /admin/login');
        break;
    case $path === '/admin/save':
        require __DIR__ . '/app/pages/admin-save.php';
        break;
    case $path === '/admin/hotspot':
        require __DIR__ . '/app/pages/admin-hotspot.php';
        break;
    case $path === '/admin/status':
        require __DIR__ . '/app/api/admin-status.php';
        break;
    case $path === '/admin/action':
        require __DIR__ . '/app/pages/admin-action.php';
        break;
    case $path === '/admin/stores':
        require __DIR__ . '/app/pages/admin-stores.php';
        break;
    case in_array($path, [
        '/generate_204',
        '/gen_204',
        '/hotspot-detect.html',
        '/canonical.html',
        '/ncsi.txt',
        '/connecttest.txt',
        '/redirect',
        '/success.txt',
    ], true):
        require __DIR__ . '/app/pages/captive.php';
        break;
    default:
        require __DIR__ . '/app/pages/portal.php';
}
