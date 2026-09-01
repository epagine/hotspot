<?php

declare(strict_types=1);

function audit_log(
    string $action,
    ?int $companyId = null,
    ?int $actorUserId = null,
    array $meta = []
): void {
    try {
        $actorUserId = $actorUserId ?? (int) ($_SESSION['user_id'] ?? 0) ?: null;
        db()->prepare(
            'INSERT INTO audit_logs (company_id, actor_user_id, action, meta_json, ip, created_at)
             VALUES (?,?,?,?,?,?)'
        )->execute([
            $companyId,
            $actorUserId,
            $action,
            $meta === [] ? '{}' : json_encode($meta, JSON_UNESCAPED_UNICODE),
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        // audit must not break primary flows
    }
}
