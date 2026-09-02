<?php

declare(strict_types=1);

require_client_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    client_redirect(client_url());
}

csrf_verify();

$mode = client_portal_mode();
$do = (string) ($_POST['do'] ?? '');

if ($mode === 'company') {
    $companyId = current_company_id();
    $user = current_user();
    if ($do === 'password' && $user) {
        $next = (string) ($_POST['new_pass'] ?? '');
        $confirm = (string) ($_POST['new_pass2'] ?? '');
        if ($next !== $confirm) {
            $_SESSION['flash_error'] = 'A confirmação da senha não confere.';
            client_redirect(client_url('conta'));
        }
        try {
            update_user_password((int) $user['id'], (string) ($_POST['current_pass'] ?? ''), $next);
            $_SESSION['flash_ok'] = 'Senha alterada com sucesso.';
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        client_redirect(client_url('conta'));
    }
    if ($do === 'charge') {
        try {
            if (!portal_can_request_company_charge()) {
                throw new RuntimeException('Não é possível gerar cobrança no momento.');
            }
            $planId = (int) ($_POST['plan_id'] ?? 0);
            $created = payment_create_company_charge(
                $companyId,
                true,
                $planId > 0 ? $planId : null
            );
            $url = (string) ($created['pay_url'] ?? '');
            $_SESSION['flash_ok'] = $url !== ''
                ? 'Link de pagamento gerado. Conclua o pagamento para regularizar sua assinatura.'
                : 'Cobrança criada.';
            if ($url !== '') {
                $_SESSION['flash_pay_url'] = $url;
            }
        } catch (Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }
        client_redirect(client_url());
    }
    client_redirect(client_url());
}

$store = current_client_store();
if (!$store) {
    client_redirect(client_url('entrar'));
}

$id = (int) $store['id'];

if ($do === 'password') {
    $next = (string) ($_POST['new_pass'] ?? '');
    $confirm = (string) ($_POST['new_pass2'] ?? '');
    if ($next !== $confirm) {
        $_SESSION['flash_error'] = 'A confirmação da senha não confere.';
        client_redirect(client_url('conta'));
    }
    try {
        portal_update_password(
            $id,
            (string) ($_POST['current_pass'] ?? ''),
            $next
        );
        $_SESSION['flash_ok'] = 'Senha alterada com sucesso.';
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    client_redirect(client_url('conta'));
}

if ($do === 'charge') {
    try {
        if (!portal_can_request_charge($store)) {
            throw new RuntimeException('Não é possível gerar cobrança no momento.');
        }
        $created = payment_create_charge($id, true);
        $url = (string) ($created['pay_url'] ?? '');
        $_SESSION['flash_ok'] = $url !== ''
            ? 'Link de pagamento gerado. Conclua o pagamento para regularizar sua assinatura.'
            : 'Cobrança criada.';
        if ($url !== '') {
            $_SESSION['flash_pay_url'] = $url;
        }
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    client_redirect(client_url());
}

client_redirect(client_url());
