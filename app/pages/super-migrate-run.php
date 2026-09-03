<?php

declare(strict_types=1);

require_super_admin();
csrf_verify();

$returnTo = trim((string) ($_POST['return_to'] ?? ''));
if (($_POST['do'] ?? '') === 'panel_url') {
    $url = rtrim(trim((string) ($_POST['panel_url'] ?? '')), '/');
    if ($url !== '' && !preg_match('#^https://[a-zA-Z0-9.-]+#', $url) && !preg_match('#^http://(localhost|127\.0\.0\.1)#', $url)) {
        $_SESSION['flash_error'] = 'Informe a URL canônica do painel (https://seu-dominio).';
        safe_internal_redirect($returnTo, '/super/configuracoes/sistema');
    }
    set_setting('panel_url', $url);
    $_SESSION['flash_ok'] = 'URL do painel salva.';
    audit_log('panel.url.save', null, null, ['host' => (string) (parse_url($url, PHP_URL_HOST) ?: '')]);
    safe_internal_redirect($returnTo, '/super/configuracoes/sistema');
}

if ($returnTo === '' || !str_starts_with($returnTo, '/super')) {
    $returnTo = '/super/configuracoes/sistema';
}

try {
    $result = run_migrations(db(), true);
    if ($result['error']) {
        $_SESSION['flash_error'] = $result['error'];
    } elseif ($result['ran'] === []) {
        $_SESSION['flash_ok'] = 'Schema já está atualizado. Nenhuma migration pendente.';
    } else {
        $_SESSION['flash_ok'] = 'Migrations aplicadas: ' . implode(', ', $result['ran']);
    }
} catch (Throwable $e) {
    $_SESSION['flash_error'] = $e->getMessage();
}

safe_internal_redirect($returnTo, '/super/configuracoes/sistema');
