<?php

declare(strict_types=1);

require_company_access('reports');
$companyId = current_company_id();
if (($_GET['export'] ?? '') === 'access') {
    $csv = export_access_csv($companyId);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="acessos.csv"');
    echo $csv;
    exit;
}
header('Location: /app?tab=relatorios');
exit;
