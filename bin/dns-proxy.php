<?php

declare(strict_types=1);

/**
 * DNS cativo: visita não autorizada resolve tudo para o portal,
 * exceto WhatsApp/Facebook. IP autorizado é encaminhado ao DNS real.
 */

$storage = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'authorized.json';
$upstream = '8.8.8.8';
$listenIp = '0.0.0.0';
$listenPort = 53;

$sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
if ($sock === false) {
    fwrite(STDERR, "Não criou o socket UDP. Rode como Administrador.\n");
    exit(1);
}
socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
if (!@socket_bind($sock, $listenIp, $listenPort)) {
    fwrite(STDERR, "Não bindou a porta 53. Feche outros DNS ou rode como Administrador.\n");
    fwrite(STDERR, socket_strerror(socket_last_error($sock)) . "\n");
    exit(1);
}

fwrite(STDOUT, "DNS cativo em {$listenIp}:{$listenPort}\n");

while (true) {
    $buf = '';
    $from = '';
    $port = 0;
    $bytes = @socket_recvfrom($sock, $buf, 4096, 0, $from, $port);
    if ($bytes < 12) {
        continue;
    }
    $state = load_state($storage);
    $qname = dns_qname($buf);
    $portalIp = $state['portal_ip'] ?? '192.168.137.1';
    $authorized = in_array($from, $state['authorized'] ?? [], true);
    $allowed = $qname !== '' && domain_allowed($qname, $state['allow_suffixes'] ?? []);

    if ($authorized || $allowed) {
        $reply = forward_dns($buf, $upstream);
        if ($reply !== null) {
            socket_sendto($sock, $reply, strlen($reply), 0, $from, $port);
            continue;
        }
    }

    $reply = spoof_a($buf, $portalIp);
    if ($reply !== null) {
        socket_sendto($sock, $reply, strlen($reply), 0, $from, $port);
    }
}

function load_state(string $path): array
{
    static $cache = ['at' => 0, 'data' => []];
    if (!is_file($path)) {
        return $cache['data'];
    }
    $mtime = filemtime($path) ?: 0;
    if ($mtime !== $cache['at']) {
        $json = json_decode((string) file_get_contents($path), true);
        $cache = ['at' => $mtime, 'data' => is_array($json) ? $json : []];
    }
    return $cache['data'];
}

function domain_allowed(string $name, array $suffixes): bool
{
    $name = strtolower(rtrim($name, '.'));
    foreach ($suffixes as $suf) {
        $suf = strtolower(ltrim($suf, '.'));
        if ($suf === '') {
            continue;
        }
        if ($name === $suf || str_ends_with($name, '.' . $suf)) {
            return true;
        }
    }
    return false;
}

function dns_qname(string $pkt): string
{
    $pos = 12;
    $len = strlen($pkt);
    $labels = [];
    while ($pos < $len) {
        $n = ord($pkt[$pos]);
        if ($n === 0) {
            break;
        }
        if (($n & 0xC0) === 0xC0) {
            break;
        }
        $pos++;
        if ($pos + $n > $len) {
            return '';
        }
        $labels[] = substr($pkt, $pos, $n);
        $pos += $n;
    }
    return implode('.', $labels);
}

function spoof_a(string $query, string $ip): ?string
{
    if (strlen($query) < 12) {
        return null;
    }
    $hdr = unpack('nid/nflags/nqd/nanc/nns/nar', substr($query, 0, 12));
    if (!$hdr || $hdr['qd'] < 1) {
        return null;
    }
    $pos = 12;
    $len = strlen($query);
    while ($pos < $len && ord($query[$pos]) !== 0) {
        $n = ord($query[$pos]);
        if (($n & 0xC0) === 0xC0) {
            $pos += 2;
            break;
        }
        $pos += $n + 1;
    }
    if ($pos >= $len) {
        return null;
    }
    if (ord($query[$pos]) === 0) {
        $pos++;
    }
    if ($pos + 4 > $len) {
        return null;
    }
    $qtype = unpack('nqtype/nqclass', substr($query, $pos, 4));
    $question = substr($query, 12, $pos + 4 - 12);

    $flags = 0x8180;
    $ancount = 0;
    $answer = '';
    if (($qtype['qtype'] ?? 0) === 1) {
        $ancount = 1;
        $packedIp = @inet_pton($ip);
        if ($packedIp === false || strlen($packedIp) !== 4) {
            $packedIp = inet_pton('192.168.137.1');
        }
        $answer = "\xc0\x0c" . pack('nnNn', 1, 1, 30, 4) . $packedIp;
    }

    return pack('nnnnnn', $hdr['id'], $flags, 1, $ancount, 0, 0) . $question . $answer;
}

function forward_dns(string $query, string $upstream): ?string
{
    $fp = @fsockopen('udp://' . $upstream, 53, $errno, $errstr, 1.5);
    if (!$fp) {
        return null;
    }
    stream_set_timeout($fp, 1);
    fwrite($fp, $query);
    $reply = fread($fp, 4096);
    fclose($fp);
    return ($reply !== false && strlen($reply) >= 12) ? $reply : null;
}
