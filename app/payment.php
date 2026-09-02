<?php

declare(strict_types=1);

function payment_provider(): string
{
    $provider = setting('payment_provider', 'pagseguro');
    return in_array($provider, ['pagseguro', 'picpay'], true) ? $provider : 'pagseguro';
}

function payment_provider_label(?string $provider = null): string
{
    return match ($provider ?? payment_provider()) {
        'picpay' => 'PicPay',
        default => 'PagSeguro',
    };
}

function payment_configured(): bool
{
    return payment_provider() === 'picpay' ? picpay_configured() : pagseguro_configured();
}

function payment_webhook_url(): string
{
    return payment_provider() === 'picpay' ? picpay_webhook_url() : pagseguro_webhook_url();
}

function payment_auto_enabled(): bool
{
    $v = setting('payment_auto', '');
    if ($v === '') {
        return pagseguro_auto_enabled();
    }
    return $v !== '0';
}

function payment_advance_days(): int
{
    $v = setting('payment_advance_days', '');
    if ($v === '') {
        return pagseguro_advance_days();
    }
    return max(0, min(30, (int) $v));
}

function payment_cron_key(): string
{
    return pagseguro_cron_key();
}

function payment_cron_url(): string
{
    return pagseguro_cron_url();
}

function payment_test_credentials(): array
{
    return payment_provider() === 'picpay' ? picpay_test_credentials() : pagseguro_test_token();
}

function payment_create_charge(int $storeId, bool $force = false): array
{
    return payment_provider() === 'picpay'
        ? picpay_create_charge($storeId, $force)
        : pagseguro_create_charge($storeId, $force);
}

function payment_create_company_charge(int $companyId, bool $force = false, ?int $planId = null): array
{
    return payment_provider() === 'picpay'
        ? picpay_create_company_charge($companyId, $force, $planId)
        : pagseguro_create_company_charge($companyId, $force, $planId);
}

function payment_run_billing(): array
{
    return payment_provider() === 'picpay' ? picpay_run_billing() : pagseguro_run_billing();
}

function payment_maybe_run_billing(): void
{
    if (!payment_configured() || !payment_auto_enabled()) {
        return;
    }
    $last = strtotime((string) setting('payment_last_run', setting('pagseguro_last_run', ''))) ?: 0;
    if (time() - $last < 4 * 3600) {
        return;
    }
    try {
        subscription_run_daily();
    } catch (Throwable $e) {
        // cron e webhook tentam de novo
    }
}

function payment_not_configured_message(): string
{
    return payment_provider() === 'picpay'
        ? 'Pagamento online (PicPay) ainda não configurado na plataforma.'
        : 'Pagamento online ainda não configurado na plataforma.';
}
