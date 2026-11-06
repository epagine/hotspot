<?php

declare(strict_types=1);

require_client_login();

if (!stream_installer_setup()) {
    $_SESSION['flash_error'] = 'O instalador ainda não está disponível. Fale com o suporte.';
    client_redirect(client_url());
}
