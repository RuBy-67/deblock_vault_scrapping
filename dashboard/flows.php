<?php

declare(strict_types=1);

require __DIR__ . '/config.php';

$root = dirname(__DIR__);
$cfg = monitor_load_env($root);

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
if ($dateFrom === '' || $dateTo === '') {
    $now = new DateTimeImmutable('now');
    $dateFrom = $dateFrom !== '' ? $dateFrom : $now->modify('first day of this month')->format('Y-m-d');
    $dateTo = $dateTo !== '' ? $dateTo : $now->format('Y-m-d');
}
$counterparty = isset($_GET['counterparty']) ? strtolower(trim((string) $_GET['counterparty'])) : '';
if ($counterparty !== '' && !preg_match('/^0x[a-f0-9]{40}$/', $counterparty)) {
    $counterparty = '';
}
$vaultTargetEur = '';
$vaultToleranceEur = '';
$cpDashboardHref = static function (string $cp) use ($dateFrom, $dateTo): string {
    $cp = strtolower(trim($cp));
    $q = ['date_from' => $dateFrom, 'date_to' => $dateTo, 'counterparty' => (preg_match('/^0x[a-f0-9]{40}$/', $cp) ? $cp : '')];
    return 'flows.php?' . http_build_query($q);
};

$activePage = 'flows';
$dashboardUrl = 'index.php?' . http_build_query(['date_from' => $dateFrom, 'date_to' => $dateTo, 'counterparty' => $counterparty]);
$walletsUrl = 'wallets.php?' . http_build_query(['date_from' => $dateFrom, 'date_to' => $dateTo, 'counterparty' => $counterparty]);
$flowsUrl = 'flows.php?' . http_build_query(['date_from' => $dateFrom, 'date_to' => $dateTo, 'counterparty' => $counterparty]);
$costsUrl = 'costs.php?' . http_build_query(['date_from' => $dateFrom, 'date_to' => $dateTo, 'counterparty' => $counterparty]);
$qualityUrl = 'quality.php?' . http_build_query(['date_from' => $dateFrom, 'date_to' => $dateTo, 'counterparty' => $counterparty]);
$concentrationUrl = 'concentration.php?' . http_build_query(['date_from' => $dateFrom, 'date_to' => $dateTo, 'counterparty' => $counterparty]);
$deferEndpoint = 'defer_flows.php';
$loadCharts = true;
$deferredStatusText = 'Chargement des graphiques de flux…';

$dashboardOgBase = monitor_dashboard_public_base($cfg);
$dashboardOgPage = $dashboardOgBase . '/' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'flows.php'));
$dashboardOgImage = $dashboardOgBase . '/lib/deblock.png';

require __DIR__ . '/views/dashboard.php';
