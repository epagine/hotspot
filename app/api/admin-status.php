<?php

declare(strict_types=1);

require_admin();
if ((int) ($_GET['store'] ?? 0) > 0) {
    select_store((int) $_GET['store']);
}
json_out(dashboard_payload());
