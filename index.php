<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/app/helpers.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = '/' . trim($path, '/');
if ($path === '/') {
    $path = '/';
}

if (!is_installed() && !str_starts_with($path, '/instalar') && !str_starts_with($path, '/install') && !str_starts_with($path, '/assets')) {
    header('Location: /instalar');
    exit;
}

// Config existe, mas banco sumiu / inacessível → força reinstalação.
if (
    is_installed()
    && !database_ready()
    && !str_starts_with($path, '/instalar')
    && !str_starts_with($path, '/install')
    && !str_starts_with($path, '/assets')
) {
    if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['install_reason'])) {
        $_SESSION['install_reason'] = 'db_unavailable';
    }
    header('Location: /instalar');
    exit;
}

if (is_installed()) {
    require_once __DIR__ . '/app/helpers.php';
}

switch (true) {
    case $path === '/install':
        if (!is_http_post()) {
            admin_redirect('/instalar', 301);
        }
        require __DIR__ . '/app/pages/install.php';
        break;
    case $path === '/instalar':
        require __DIR__ . '/app/pages/install.php';
        break;
    case $path === '/assets/landing.css':
        header('Content-Type: text/css; charset=utf-8');
        readfile(__DIR__ . '/public/assets/landing.css');
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
    case $path === '/assets/logo-wifidaloja.jpg':
        $logo = __DIR__ . '/public/assets/logo-wifidaloja.jpg';
        if (!is_file($logo)) {
            http_response_code(404);
            break;
        }
        header('Content-Type: image/jpeg');
        header('Cache-Control: public, max-age=86400');
        readfile($logo);
        break;
    case $path === '/entrar':
        require __DIR__ . '/app/pages/auth-login.php';
        break;
    case $path === '/comecar':
        require __DIR__ . '/app/pages/auth-register.php';
        break;
    case $path === '/sair':
        auth_logout();
        header('Location: /entrar');
        exit;
    case $path === '/app':
        require __DIR__ . '/app/pages/app-shell.php';
        break;
    case $path === '/app/empresa':
        require __DIR__ . '/app/pages/app-company-save.php';
        break;
    case $path === '/app/hotspots':
        require __DIR__ . '/app/pages/app-hotspots-save.php';
        break;
    case $path === '/app/usuarios':
        require __DIR__ . '/app/pages/app-users-save.php';
        break;
    case $path === '/app/campanhas':
        require __DIR__ . '/app/pages/app-campaigns-save.php';
        break;
    case $path === '/app/cupons':
        require __DIR__ . '/app/pages/app-coupons-save.php';
        break;
    case $path === '/app/assinatura':
        require __DIR__ . '/app/pages/app-billing-save.php';
        break;
    case $path === '/app/relatorios':
        require __DIR__ . '/app/pages/app-reports.php';
        break;
    case $path === '/super':
        require __DIR__ . '/app/pages/super.php';
        break;
    case $path === '/super/empresas':
        require __DIR__ . '/app/pages/super-companies.php';
        break;
    case $path === '/super/planos':
        if (!is_http_post()) {
            header('Location: /super?tab=planos', true, 301);
            exit;
        }
        require __DIR__ . '/app/pages/super-plans.php';
        break;
    case $path === '/super/instalador/baixar':
        require __DIR__ . '/app/pages/admin-instalador.php';
        break;
    case $path === '/super/instalador':
        if (is_http_post()) {
            require __DIR__ . '/app/pages/admin-instalador.php';
            break;
        }
        $_GET['tab'] = 'instalador';
        require __DIR__ . '/app/pages/super.php';
        break;
    case preg_match('#^/portal/([a-fA-F0-9]+)$#', $path, $m) === 1:
        $GLOBALS['portal_token'] = $m[1];
        require __DIR__ . '/app/pages/portal-v2.php';
        break;
    case preg_match('#^/api/v1(/.*)?$#', $path, $m) === 1:
        $GLOBALS['api_path'] = $m[1] ?? '';
        if ($GLOBALS['api_path'] === '') {
            $GLOBALS['api_path'] = '/';
        }
        require __DIR__ . '/app/api/v1.php';
        break;
    case $path === '/sessao':
    case $path === '/api/session':
        require __DIR__ . '/app/api/session.php';
        break;
    case $path === '/confirmar':
    case $path === '/api/confirm':
        require __DIR__ . '/app/api/confirm.php';
        break;
    case $path === '/agente/sincronizar':
    case $path === '/agent/sync':
        require __DIR__ . '/app/api/agent-sync.php';
        break;
    case preg_match('#^/agente/marca/([a-fA-F0-9]+)$#', $path, $m) === 1:
        $GLOBALS['agent_token'] = $m[1];
        require __DIR__ . '/app/pages/agent-brand.php';
        break;
    case $path === '/agent/brand':
        require __DIR__ . '/app/pages/agent-brand.php';
        break;
    case preg_match('#^/marca(?:/\d+)?\.png$#', $path) === 1:
    case $path === '/brand.png':
        $storeId = !empty($_SESSION['admin']) ? (int) ($_GET['store'] ?? 0) : 0;
        output_brand_png($storeId > 0 ? $storeId : null);
        break;
    case preg_match('#^/(?:admin/clientes|hotspots)/(\d+)/marca\.png$#', $path, $m) === 1:
        output_brand_png((int) $m[1]);
        break;
    case $path === '/super/pagseguro':
    case $path === '/admin/pagseguro':
    case $path === '/admin/financeiro/pagseguro':
        if (!is_http_post()) {
            header('Location: /super?tab=configuracoes&sec=integracao', true, 301);
            exit;
        }
        require __DIR__ . '/app/pages/super-pagseguro-save.php';
        break;
    case $path === '/super/politicas':
        if (!is_http_post()) {
            header('Location: /super?tab=configuracoes&sec=politicas', true, 301);
            exit;
        }
        require __DIR__ . '/app/pages/super-policies-save.php';
        break;
    case $path === '/super/whatsapp':
        if (!is_http_post()) {
            header('Location: /super?tab=configuracoes&sec=whatsapp', true, 301);
            exit;
        }
        require __DIR__ . '/app/pages/super-whatsapp-save.php';
        break;
    case $path === '/super/migrations':
        if (!is_http_post()) {
            header('Location: /super?tab=configuracoes&sec=sistema', true, 301);
            exit;
        }
        require __DIR__ . '/app/pages/super-migrate-run.php';
        break;
    case $path === '/admin/configuracoes/politicas':
        if (is_http_post()) {
            require __DIR__ . '/app/pages/super-policies-save.php';
            break;
        }
        header('Location: /super?tab=configuracoes&sec=politicas', true, 301);
        exit;
    case $path === '/admin/configuracoes/integracao':
        if (is_http_post()) {
            require __DIR__ . '/app/pages/super-pagseguro-save.php';
            break;
        }
        header('Location: /super?tab=configuracoes&sec=integracao', true, 301);
        exit;
    case preg_match('#^/admin/financeiro(?:/(\d+))?$#', $path, $m) === 1:
        if (is_http_post() && (string) ($_POST['do'] ?? '') === 'charge') {
            $GLOBALS['route_id'] = (int) ($m[1] ?? 0);
            if ((int) ($m[1] ?? 0) > 0 && (int) ($_POST['id'] ?? 0) === 0) {
                $_POST['id'] = (string) $m[1];
            }
            require __DIR__ . '/app/pages/super-pagseguro-save.php';
            break;
        }
        header('Location: /cliente', true, 301);
        exit;
    case preg_match('#^/admin/clientes(?:/(\d+))?$#', $path, $m) === 1:
        if (is_http_post()) {
            require __DIR__ . '/app/pages/app-hotspots-save.php';
            break;
        }
        legacy_admin_route($path);
        break;
    case $path === '/admin/stores':
        if (is_http_post()) {
            require __DIR__ . '/app/pages/app-hotspots-save.php';
            break;
        }
        legacy_admin_route($path);
        break;
    case $path === '/admin/instalador/baixar':
        require __DIR__ . '/app/pages/admin-instalador.php';
        break;
    case $path === '/admin/instalador':
        if (is_http_post()) {
            require __DIR__ . '/app/pages/admin-instalador.php';
            break;
        }
        header('Location: /super?tab=instalador', true, 301);
        exit;
    case preg_match('#^/admin#', $path) === 1:
        legacy_admin_route($path);
        break;
    case preg_match('#^/(?:arte|story)/([A-Z0-9]+)\.png$#', $path, $m) === 1:
        require __DIR__ . '/app/pages/story.php';
        render_story($m[1]);
        break;
    case $path === '/cliente/login':
        client_redirect(client_url('entrar'), 301);
        break;
    case $path === '/cliente/entrar':
        require __DIR__ . '/app/pages/client-login.php';
        break;
    case $path === '/cliente/sair':
        unset($_SESSION['client_store_id']);
        auth_logout();
        client_redirect(client_url('entrar'));
        break;
    case $path === '/cliente/conta':
        if (is_http_post()) {
            require __DIR__ . '/app/pages/client-action.php';
            break;
        }
        $_GET['sec'] = 'conta';
        require __DIR__ . '/app/pages/client.php';
        break;
    case $path === '/cliente':
        if (is_http_post()) {
            require __DIR__ . '/app/pages/client-action.php';
            break;
        }
        require __DIR__ . '/app/pages/client.php';
        break;
    case $path === '/notificacoes/pagbank':
    case $path === '/webhooks/pagbank':
        require __DIR__ . '/app/pages/webhook-pagbank.php';
        break;
    case $path === '/notificacoes/picpay':
    case $path === '/webhooks/picpay':
        require __DIR__ . '/app/pages/webhook-picpay.php';
        break;
    case preg_match('#^/cron/pagseguro(?:/([a-fA-F0-9]+))?$#', $path, $m) === 1:
        if (!empty($m[1])) {
            $GLOBALS['cron_key'] = $m[1];
        }
        require __DIR__ . '/app/pages/cron-pagseguro.php';
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
    case $path === '/':
    case $path === '/inicio':
        if (is_hotspot_lan()) {
            $portalUrl = local_store_portal_url();
            if ($portalUrl !== null) {
                header('Location: ' . $portalUrl);
                exit;
            }
            require __DIR__ . '/app/pages/portal.php';
            break;
        }
        require __DIR__ . '/app/pages/landing.php';
        break;
    case $path === '/wifi':
    case $path === '/portal':
        if (!is_hotspot_lan()) {
            header('Location: /');
            exit;
        }
        $portalUrl = local_store_portal_url();
        if ($portalUrl !== null) {
            header('Location: ' . $portalUrl);
            exit;
        }
        require __DIR__ . '/app/pages/portal.php';
        break;
    default:
        if (!is_hotspot_lan()) {
            header('Location: /');
            exit;
        }
        $portalUrl = local_store_portal_url();
        if ($portalUrl !== null) {
            header('Location: ' . $portalUrl);
            exit;
        }
        require __DIR__ . '/app/pages/portal.php';
}
