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
    case preg_match('#^/admin/clientes/(\d+)/marca\.png$#', $path, $m) === 1:
        if (empty($_SESSION['admin'])) {
            admin_redirect(admin_url('entrar'));
        }
        output_brand_png((int) $m[1]);
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
    case $path === '/admin' || $path === '/admin/':
        $legacy = admin_legacy_url();
        admin_redirect($legacy ?? admin_url(), $legacy ? 301 : 302);
        break;
    case $path === '/admin/login':
        admin_redirect(admin_url('entrar'), 301);
        break;
    case $path === '/admin/entrar':
        require __DIR__ . '/app/pages/admin-login.php';
        break;
    case $path === '/admin/logout':
        admin_redirect(admin_url('sair'), 301);
        break;
    case $path === '/admin/sair':
        $_SESSION = [];
        session_destroy();
        admin_redirect(admin_url('entrar'));
        break;
    case $path === '/admin/save':
        if (!is_http_post()) {
            admin_redirect(admin_url('conta'), 301);
        }
        require __DIR__ . '/app/pages/admin-save.php';
        break;
    case $path === '/admin/estado':
    case $path === '/admin/status':
        require __DIR__ . '/app/api/admin-status.php';
        break;
    case $path === '/admin/stores':
        if (!is_http_post()) {
            admin_redirect(admin_url(), 301);
        }
        require __DIR__ . '/app/pages/admin-stores.php';
        break;
    case $path === '/admin/pagseguro':
        if (!is_http_post()) {
            admin_redirect(admin_url('configuracoes', 0, 'integracao'), 301);
        }
        require __DIR__ . '/app/pages/admin-pagseguro.php';
        break;
    case $path === '/admin/instalador/baixar':
        require __DIR__ . '/app/pages/admin-instalador.php';
        break;
    case $path === '/admin/instalador':
        if (is_http_post()) {
            require __DIR__ . '/app/pages/admin-instalador.php';
            break;
        }
        $_GET['tab'] = 'instalador';
        require __DIR__ . '/app/pages/admin.php';
        break;
    case $path === '/admin/conta':
        if (is_http_post()) {
            require __DIR__ . '/app/pages/admin-save.php';
            break;
        }
        admin_redirect(admin_url('conta'), 301);
        break;
    case $path === '/admin/configuracoes':
        admin_redirect(admin_url('configuracoes', 0, 'conta'));
        break;
    case $path === '/admin/configuracoes/conta':
        if (is_http_post()) {
            require __DIR__ . '/app/pages/admin-save.php';
            break;
        }
        $_GET['tab'] = 'configuracoes';
        $_GET['sec'] = 'conta';
        require __DIR__ . '/app/pages/admin.php';
        break;
    case $path === '/admin/configuracoes/pagseguro':
        admin_redirect(admin_url('configuracoes', 0, 'integracao'), 301);
        break;
    case $path === '/admin/configuracoes/integracao':
        if (is_http_post()) {
            require __DIR__ . '/app/pages/admin-pagseguro.php';
            break;
        }
        $_GET['tab'] = 'configuracoes';
        $_GET['sec'] = 'integracao';
        unset($_GET['loja'], $_GET['id']);
        require __DIR__ . '/app/pages/admin.php';
        break;
    case $path === '/admin/financeiro/pagseguro':
        if (is_http_post()) {
            require __DIR__ . '/app/pages/admin-pagseguro.php';
            break;
        }
        admin_redirect(admin_url('configuracoes', 0, 'integracao'), 301);
        break;
    case preg_match('#^/admin/financeiro(?:/(\d+))?$#', $path, $m) === 1:
        $loja = (int) ($m[1] ?? 0);
        if (is_http_post() && (string) ($_POST['do'] ?? '') === 'charge') {
            $GLOBALS['route_id'] = $loja;
            if ($loja > 0 && (int) ($_POST['id'] ?? 0) === 0) {
                $_POST['id'] = (string) $loja;
            }
            require __DIR__ . '/app/pages/admin-pagseguro.php';
            break;
        }
        if (($_GET['sec'] ?? '') === 'pagseguro') {
            admin_redirect(admin_url('configuracoes', 0, 'integracao'), 301);
        }
        $loja = (int) ($m[1] ?? $_GET['loja'] ?? 0);
        if ($loja > 0 && empty($m[1])) {
            admin_redirect(admin_url('financeiro', $loja), 301);
        }
        $_GET['tab'] = 'financeiro';
        $_GET['sec'] = 'cobrancas';
        if ($loja > 0) {
            $_GET['loja'] = (string) $loja;
        } else {
            unset($_GET['loja']);
        }
        unset($_GET['id']);
        require __DIR__ . '/app/pages/admin.php';
        break;
    case preg_match('#^/admin/assinaturas(?:/(\d+))?$#', $path, $m) === 1:
        $id = (int) ($m[1] ?? 0);
        if (is_http_post()) {
            $GLOBALS['route_id'] = $id;
            if ($id > 0 && (int) ($_POST['id'] ?? 0) === 0) {
                $_POST['id'] = (string) $id;
            }
            require __DIR__ . '/app/pages/admin-subscription.php';
            break;
        }
        $id = (int) ($m[1] ?? $_GET['id'] ?? 0);
        if ($id > 0 && empty($m[1])) {
            admin_redirect(admin_url('assinaturas', $id), 301);
        }
        $_GET['tab'] = 'assinaturas';
        if ($id > 0) {
            $_GET['id'] = (string) $id;
        } else {
            unset($_GET['id']);
        }
        require __DIR__ . '/app/pages/admin.php';
        break;
    case $path === '/admin/configuracoes/politicas':
        if (is_http_post()) {
            require __DIR__ . '/app/pages/admin-policies.php';
            break;
        }
        $_GET['tab'] = 'configuracoes';
        $_GET['sec'] = 'politicas';
        require __DIR__ . '/app/pages/admin.php';
        break;
    case preg_match('#^/admin/clientes(?:/(\d+))?$#', $path, $m) === 1:
        $id = (int) ($m[1] ?? 0);
        if (is_http_post()) {
            $GLOBALS['route_id'] = $id;
            if ($id > 0 && (int) ($_POST['id'] ?? 0) === 0) {
                $_POST['id'] = (string) $id;
            }
            require __DIR__ . '/app/pages/admin-stores.php';
            break;
        }
        $id = (int) ($m[1] ?? $_GET['id'] ?? 0);
        if ($id > 0 && empty($m[1])) {
            admin_redirect(admin_url('clientes', $id), 301);
        }
        $_GET['tab'] = 'clientes';
        if ($id > 0) {
            $_GET['id'] = (string) $id;
        } else {
            unset($_GET['id']);
        }
        require __DIR__ . '/app/pages/admin.php';
        break;
    case $path === '/notificacoes/pagbank':
    case $path === '/webhooks/pagbank':
        require __DIR__ . '/app/pages/webhook-pagbank.php';
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
    case $path === '/wifi':
    case $path === '/portal':
        if (!is_hotspot_lan()) {
            header('Location: ' . admin_url());
            exit;
        }
        require __DIR__ . '/app/pages/portal.php';
        break;
    default:
        if (!is_hotspot_lan()) {
            header('Location: ' . admin_url());
            exit;
        }
        require __DIR__ . '/app/pages/portal.php';
}
