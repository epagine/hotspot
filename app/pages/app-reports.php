<?php

declare(strict_types=1);

require_company_access('reports');
$companyId = current_company_id();
$export = (string) ($_GET['export'] ?? '');
$days = company_report_days((int) ($_GET['days'] ?? 30));

if ($export !== '') {
    if (!company_has_feature($companyId, 'reports')) {
        $_SESSION['flash_error'] = company_feature_error('reports');
        header('Location: /app?tab=relatorios');
        exit;
    }
    $filename = 'relatorio.csv';
    $csv = '';
    if ($export === 'access') {
        $filename = 'acessos.csv';
        $csv = export_access_csv($companyId);
    } elseif ($export === 'clients') {
        $filename = 'clientes.csv';
        $csv = export_clients_csv($companyId);
    } elseif ($export === 'campaigns') {
        $filename = 'campanhas.csv';
        $csv = export_campaigns_csv($companyId, $days);
    } else {
        header('Location: /app?tab=relatorios&days=' . $days);
        exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $csv;
    exit;
}

header('Location: /app?tab=relatorios&days=' . $days);
exit;
