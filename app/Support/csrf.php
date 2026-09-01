<?php

declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . h(csrf_token()) . '">';
}

function csrf_verify(?string $token = null): void
{
    $token = $token ?? (string) ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $session = (string) ($_SESSION['csrf_token'] ?? '');
    if ($session === '' || $token === '' || !hash_equals($session, $token)) {
        http_response_code(419);
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            json_out(['ok' => false, 'error' => 'csrf'], 419);
        }
        echo 'Token de segurança inválido. Volte e tente novamente.';
        exit;
    }
}

function require_post_csrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        csrf_verify();
    }
}
