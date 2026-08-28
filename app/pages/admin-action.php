<?php

declare(strict_types=1);

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin?tab=clientes');
    exit;
}

$sid = (int) ($_POST['store_id'] ?? 0);
if ($sid > 0) {
    select_store($sid);
}

$id = (int) ($_POST['id'] ?? 0);
$do = (string) ($_POST['do'] ?? '');
$stmt = db()->prepare('SELECT * FROM clients WHERE id = ? AND store_id = ?');
$stmt->execute([$id, current_store_id()]);
$client = $stmt->fetch();
$back = '/admin?tab=clientes&store=' . current_store_id();
if (!$client) {
    header('Location: ' . $back);
    exit;
}

if ($do === 'allow') {
    if ($client['state'] !== 'online' && online_count() >= max_clients()) {
        header('Location: ' . $back);
        exit;
    }
    $hours = max(1, (int) setting('session_hours', '2'));
    db()->prepare(
        'UPDATE clients SET state = "online", authorized_at = ?, expires_at = ? WHERE id = ?'
    )->execute([
        date('Y-m-d H:i:s'),
        date('Y-m-d H:i:s', time() + $hours * 3600),
        $id,
    ]);
}
if ($do === 'block') {
    db()->prepare('UPDATE clients SET state = "blocked" WHERE id = ?')->execute([$id]);
}
if ($do === 'kick') {
    db()->prepare(
        'UPDATE clients SET state = "expired", expires_at = ? WHERE id = ?'
    )->execute([date('Y-m-d H:i:s'), $id]);
}
sync_authorized_file();
header('Location: ' . $back);
