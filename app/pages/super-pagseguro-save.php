<?php

declare(strict_types=1);

require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /super/configuracoes/integracao');
    exit;
}

require_post_csrf();

$returnTo = trim((string) ($_POST['return_to'] ?? ''));
$redirect = static function () use ($returnTo): void {
    safe_internal_redirect($returnTo, '/super/financeiro/cobrancas');
};

$do = (string) ($_POST['do'] ?? 'save');

if ($do === 'save') {
    $provider = (string) ($_POST['payment_provider'] ?? 'pagseguro');
    set_setting('payment_provider', $provider === 'picpay' ? 'picpay' : 'pagseguro');

    $env = (string) ($_POST['pagseguro_env'] ?? 'sandbox');
    set_setting('pagseguro_env', $env === 'production' ? 'production' : 'sandbox');
    $token = pagseguro_normalize_token((string) ($_POST['pagseguro_token'] ?? ''));
    if ($token !== '') {
        set_setting('pagseguro_token', $token);
    }

    $picpayEnv = (string) ($_POST['picpay_env'] ?? 'sandbox');
    set_setting('picpay_env', $picpayEnv === 'production' ? 'production' : 'sandbox');
    $clientId = trim((string) ($_POST['picpay_client_id'] ?? ''));
    if ($clientId !== '') {
        set_setting('picpay_client_id', $clientId);
    }
    $clientSecret = trim((string) ($_POST['picpay_client_secret'] ?? ''));
    if ($clientSecret !== '') {
        set_setting('picpay_client_secret', $clientSecret);
    }
    $sellerToken = trim((string) ($_POST['picpay_seller_token'] ?? ''));
    if ($sellerToken !== '') {
        set_setting('picpay_seller_token', $sellerToken);
    }

    $auto = (string) ($_POST['payment_auto'] ?? $_POST['pagseguro_auto'] ?? '0') === '1' ? '1' : '0';
    set_setting('payment_auto', $auto);
    set_setting('pagseguro_auto', $auto);
    $days = (int) ($_POST['payment_advance_days'] ?? $_POST['pagseguro_advance_days'] ?? 5);
    $days = (string) max(0, min(30, $days));
    set_setting('payment_advance_days', $days);
    set_setting('pagseguro_advance_days', $days);
    pagseguro_cron_key();

    if (!payment_configured()) {
        $_SESSION['flash_error'] = payment_provider() === 'picpay'
            ? 'Informe client_id, client_secret e x-seller-token do PicPay.'
            : 'Cole o token gerado no PagSeguro (Vendas → Integrações).';
        $redirect();
    }
    $_SESSION['flash_ok'] = 'Integração ' . payment_provider_label() . ' salva.';
    audit_log('payment.settings.save', null, null, ['provider' => payment_provider()]);
    $redirect();
}

if ($do === 'test') {
    $r = payment_test_credentials();
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
        $created = payment_create_charge($id, true);
        $url = $created['pay_url'];
        $_SESSION['flash_ok'] = !empty($created['recurring'])
            ? 'Checkout recorrente criado. Envie o link ao cliente.'
            : ($url !== '' ? 'Cobrança criada. Envie o link ao cliente.' : 'Cobrança criada.');
        if ($url !== '') {
            $_SESSION['flash_pay_url'] = $url;
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    header('Location: /cliente');
    exit;
}

if ($do === 'run') {
    $r = payment_run_billing();
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

header('Location: /super/financeiro/cobrancas');
exit;
