<?php

declare(strict_types=1);

require_company_access('hotspots');

if (!stream_installer_setup()) {
    $_SESSION['flash_error'] = 'O instalador ainda não está disponível. Entre em contato com o suporte.';
    header('Location: /app/hotspots');
    exit;
}
