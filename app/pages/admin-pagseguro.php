<?php

declare(strict_types=1);

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('configuracoes', 0, 'integracao'));
    exit;
}

$returnTo = trim((string) ($_POST['return_to'] ?? ''));
$redirect = static function () use ($returnTo): void {
    header('Location: ' . ($returnTo !== '' ? $returnTo : admin_url('configuracoes', 0, 'integracao')));
    exit;
};

$do = (string) ($_POST['do'] ?? 'save');

if ($do === 'save') {
    $env = (string) ($_POST['pagseguro_env'] ?? 'sandbox');
    set_setting('pagseguro_env', $env === 'production' ? 'production' : 'sandbox');
    $token = pagseguro_normalize_token((string) ($_POST['pagseguro_token'] ?? ''));
    if ($token !== '') {
        set_setting('pagseguro_token', $token);
    }
    set_setting('pagseguro_auto', (string) ($_POST['pagseguro_auto'] ?? '0') === '1' ? '1' : '0');
    $days = (int) ($_POST['pagseguro_advance_days'] ?? 5);
    set_setting('pagseguro_advance_days', (string) max(0, min(30, $days)));
    pagseguro_cron_key();
    if (!pagseguro_configured()) {
        $_SESSION['flash_error'] = 'Cole o token gerado no PagSeguro (Vendas → Integrações).';
        $redirect();
    }
    $_SESSION['flash_ok'] = 'Integração PagSeguro salva.';
    $redirect();
}

if ($do === 'test') {
    $r = pagseguro_test_token();
    if ($r['ok']) {
        $_SESSION['flash_ok'] = $r['message'];
    } else {
        $_SESSION['flash_error'] = $r['message'];
    }
    $redirect();
}

if ($do === 'charge') {
    $id = (int) ($_POST['id'] ?? $GLOBALS['route_id'] ?? 0);
    try {
        $created = pagseguro_create_charge($id, true);
        $url = $created['pay_url'];
        $_SESSION['flash_ok'] = !empty($created['recurring'])
            ? 'Checkout recorrente criado no intervalo do plano. Envie o link; o primeiro pagamento (cartão) ativa as próximas cobranças.'
            : ($url !== '' ? 'Cobrança avulsa criada. Envie o link ao cliente.' : 'Cobrança criada no PagSeguro.');
        if ($url !== '') {
            $_SESSION['flash_pay_url'] = $url;
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    header('Location: ' . admin_url('financeiro', $id));
    exit;
}

if ($do === 'run') {
    $r = pagseguro_run_billing();
    $msg = 'Rotina: ' . (int) $r['created'] . ' cobrança(s) gerada(s)';
    if ((int) ($r['overdue'] ?? 0) > 0) {
        $msg .= ', ' . (int) $r['overdue'] . ' marcado(s) atrasado(s)';
    }
    $msg .= '.';
    if (!empty($r['errors'])) {
        $_SESSION['flash_error'] = $msg . ' ' . implode(' ', $r['errors']);
    } else {
        $_SESSION['flash_ok'] = $msg;
    }
    $redirect();
}

header('Location: ' . admin_url('financeiro'));
exit;
