<?php

declare(strict_types=1);

interface NetworkProviderInterface
{
    public function key(): string;

    public function label(): string;

    /** @return array{ok:bool,message?:string} */
    public function authorizeClient(array $hotspot, array $client, array $session): array;

    /** @return array{ok:bool,message?:string} */
    public function disconnectClient(array $hotspot, array $client, array $session): array;

    /** @return array<string,mixed> */
    public function status(array $hotspot): array;
}

final class WindowsHotspotProvider implements NetworkProviderInterface
{
    public function key(): string
    {
        return 'windows';
    }

    public function label(): string
    {
        return 'Windows Hotspot (agente)';
    }

    public function authorizeClient(array $hotspot, array $client, array $session): array
    {
        // Authorization is enforced via authorized.json + agent sync (existing flow).
        return ['ok' => true, 'message' => 'Autorizado via agente Windows'];
    }

    public function disconnectClient(array $hotspot, array $client, array $session): array
    {
        return ['ok' => true, 'message' => 'Desconexão solicitada ao agente'];
    }

    public function status(array $hotspot): array
    {
        $raw = (string) ($hotspot['last_status'] ?? '');
        $decoded = $raw !== '' ? json_decode($raw, true) : [];
        return is_array($decoded) ? $decoded : [];
    }
}

final class MikroTikProvider implements NetworkProviderInterface
{
    public function key(): string
    {
        return 'mikrotik';
    }

    public function label(): string
    {
        return 'MikroTik (em breve)';
    }

    public function authorizeClient(array $hotspot, array $client, array $session): array
    {
        return ['ok' => false, 'message' => 'Integração MikroTik ainda não habilitada'];
    }

    public function disconnectClient(array $hotspot, array $client, array $session): array
    {
        return ['ok' => false, 'message' => 'Integração MikroTik ainda não habilitada'];
    }

    public function status(array $hotspot): array
    {
        return ['provider' => 'mikrotik', 'ready' => false];
    }
}

final class OpenWrtProvider implements NetworkProviderInterface
{
    public function key(): string
    {
        return 'openwrt';
    }

    public function label(): string
    {
        return 'OpenWrt (em breve)';
    }

    public function authorizeClient(array $hotspot, array $client, array $session): array
    {
        return ['ok' => false, 'message' => 'Integração OpenWrt ainda não habilitada'];
    }

    public function disconnectClient(array $hotspot, array $client, array $session): array
    {
        return ['ok' => false, 'message' => 'Integração OpenWrt ainda não habilitada'];
    }

    public function status(array $hotspot): array
    {
        return ['provider' => 'openwrt', 'ready' => false];
    }
}

final class UniFiProvider implements NetworkProviderInterface
{
    public function key(): string
    {
        return 'unifi';
    }

    public function label(): string
    {
        return 'UniFi (em breve)';
    }

    public function authorizeClient(array $hotspot, array $client, array $session): array
    {
        return ['ok' => false, 'message' => 'Integração UniFi ainda não habilitada'];
    }

    public function disconnectClient(array $hotspot, array $client, array $session): array
    {
        return ['ok' => false, 'message' => 'Integração UniFi ainda não habilitada'];
    }

    public function status(array $hotspot): array
    {
        return ['provider' => 'unifi', 'ready' => false];
    }
}

/** @return array<string, NetworkProviderInterface> */
function network_providers(): array
{
    static $map = null;
    if (is_array($map)) {
        return $map;
    }
    $providers = [
        new WindowsHotspotProvider(),
        new MikroTikProvider(),
        new OpenWrtProvider(),
        new UniFiProvider(),
    ];
    $map = [];
    foreach ($providers as $p) {
        $map[$p->key()] = $p;
    }
    return $map;
}

function network_provider(string $key): NetworkProviderInterface
{
    $all = network_providers();
    return $all[$key] ?? $all['windows'];
}
