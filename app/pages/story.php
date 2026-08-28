<?php

declare(strict_types=1);

function render_story(string $code): void
{
    $code = strtoupper($code);
    $store = setting('store_name', 'Loja');
    $city = setting('store_city', '');
    $w = 1080;
    $h = 1920;
    $im = imagecreatetruecolor($w, $h);
    $bg1 = imagecolorallocate($im, 18, 16, 14);
    $gold = imagecolorallocate($im, 232, 176, 88);
    $cream = imagecolorallocate($im, 250, 244, 232);
    $muted = imagecolorallocate($im, 196, 184, 164);
    imagefilledrectangle($im, 0, 0, $w, $h, $bg1);

    $brandFile = brand_image_path();
    if (is_file($brandFile)) {
        $src = @imagecreatefrompng($brandFile);
        if ($src !== false) {
            $sw = imagesx($src);
            $sh = imagesy($src);
            $scale = max($w / max(1, $sw), $h / max(1, $sh));
            $nw = (int) round($sw * $scale);
            $nh = (int) round($sh * $scale);
            $dx = (int) round(($w - $nw) / 2);
            $dy = (int) round(($h - $nh) / 2);
            imagecopyresampled($im, $src, $dx, $dy, 0, 0, $nw, $nh, $sw, $sh);
            imagedestroy($src);
            $veil = imagecolorallocatealpha($im, 18, 16, 14, 70);
            imagefilledrectangle($im, 0, 0, $w, $h, $veil);
        }
    } else {
        for ($i = 0; $i < 18; $i++) {
            $c = imagecolorallocatealpha($im, 232, 176, 88, 110);
            imagefilledellipse($im, 200 + $i * 40, 220 + $i * 70, 420, 420, $c);
        }
    }

    $font = 'C:\\Windows\\Fonts\\segoeui.ttf';
    if (!is_file($font)) {
        $font = 'C:\\Windows\\Fonts\\arial.ttf';
    }

    if (is_file($font)) {
        imagettftext($im, 28, 0, 90, 220, $gold, $font, 'ESTOU AQUI');
        imagettftext($im, 64, 0, 90, 340, $cream, $font, wrap_ttf($store, $font, 64, 900));
        if ($city !== '') {
            imagettftext($im, 28, 0, 90, 430, $muted, $font, $city);
        }
        imagettftext($im, 26, 0, 90, 980, $muted, $font, 'Código desta visita');
        imagettftext($im, 92, 0, 90, 1120, $gold, $font, $code);
        imagettftext($im, 26, 0, 90, 1720, $cream, $font, 'Wi-Fi da casa · ' . date('d/m/Y H:i'));
    } else {
        imagestring($im, 5, 90, 300, $store, $cream);
        imagestring($im, 5, 90, 500, $code, $gold);
    }

    header('Content-Type: image/png');
    header('Cache-Control: no-store');
    imagepng($im);
    imagedestroy($im);
    exit;
}

function wrap_ttf(string $text, string $font, int $size, int $maxWidth): string
{
    $words = preg_split('/\s+/', $text) ?: [$text];
    $lines = [];
    $line = '';
    foreach ($words as $word) {
        $try = trim($line . ' ' . $word);
        $box = imagettfbbox($size, 0, $font, $try);
        $width = abs($box[2] - $box[0]);
        if ($width > $maxWidth && $line !== '') {
            $lines[] = $line;
            $line = $word;
        } else {
            $line = $try;
        }
    }
    if ($line !== '') {
        $lines[] = $line;
    }
    return implode("\n", array_slice($lines, 0, 3));
}
