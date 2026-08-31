<?php

declare(strict_types=1);

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . admin_url('assinaturas'));
    exit;
}

$id = (int) ($_POST['id'] ?? $GLOBALS['route_id'] ?? 0);
$do = (string) ($_POST['do'] ?? 'save');

if ($do === 'save' && $id > 0) {
    $override = (string) ($_POST['billing_override'] ?? 'auto');
    $allowed = array_merge(['auto'], subscription_locked_statuses());
    if (!in_array($override, $allowed, true)) {
        $override = 'auto';
    }
    subscription_update($id, [
        'plan' => (string) ($_POST['plan'] ?? 'mensal'),
        'monthly_fee' => trim((string) ($_POST['monthly_fee'] ?? '')),
        'paid_until' => trim((string) ($_POST['paid_until'] ?? '')),
        'auto_billing' => (string) ($_POST['auto_billing'] ?? '0') === '1',
        'billing_override' => $override,
        'notes' => trim((string) ($_POST['notes'] ?? '')),
    ], 'admin');
    try {
        portal_update($id, [
            'enabled' => (string) ($_POST['portal_enabled'] ?? '0') === '1',
            'email' => trim((string) ($_POST['portal_email'] ?? '')),
            'password' => trim((string) ($_POST['portal_pass'] ?? '')),
        ]);
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: ' . admin_url('assinaturas', $id));
        exit;
    }
    $_SESSION['flash_ok'] = 'Assinatura salva.';
    header('Location: ' . admin_url('assinaturas', $id));
    exit;
}

if ($do === 'charge' && $id > 0) {
    try {
        $created = pagseguro_create_charge($id, true);
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
    header('Location: ' . admin_url('assinaturas', $id));
    exit;
}

if ($id > 0 && in_array($do, ['cortesia', 'cancel', 'reactivate', 'extend'], true)) {
    if ($do === 'cortesia') {
        subscription_transition($id, 'cortesia', 'Marcada como cortesia', 'admin');
        $_SESSION['flash_ok'] = 'Assinatura marcada como cortesia.';
    } elseif ($do === 'cancel') {
        subscription_transition($id, 'cancelada', 'Cancelada manualmente', 'admin');
        $_SESSION['flash_ok'] = 'Assinatura cancelada. O serviço foi suspenso.';
    } elseif ($do === 'reactivate') {
        db()->prepare('UPDATE stores SET billing_status = ?, cancelled_at = ?, suspended_at = ? WHERE id = ?')->execute(['ativa', '', '', $id]);
        subscription_reconcile($id, 'Reativada manualmente', 'admin');
        $_SESSION['flash_ok'] = 'Assinatura reativada.';
    } elseif ($do === 'extend') {
        $store = find_store($id);
        if ($store) {
            $until = trim((string) ($store['paid_until'] ?? ''));
            $base = ($until !== '' && strtotime($until) !== false && strtotime($until) > time())
                ? strtotime($until)
                : time();
            $newUntil = date('Y-m-d', strtotime('+7 days', $base) ?: $base);
            db()->prepare('UPDATE stores SET paid_until = ? WHERE id = ?')->execute([$newUntil, $id]);
            subscription_log_event($id, 'extend', (string) ($store['billing_status'] ?? ''), (string) ($store['billing_status'] ?? ''), '+7 dias', 'admin');
            subscription_reconcile($id, 'Prorrogação de 7 dias', 'admin');
            $_SESSION['flash_ok'] = 'Vigência estendida em 7 dias.';
        }
    }
    header('Location: ' . admin_url('assinaturas', $id));
    exit;
}

header('Location: ' . admin_url('assinaturas'));
