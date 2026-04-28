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

$vaultTargetEur = isset($_GET['vault_target_eur']) ? trim((string) $_GET['vault_target_eur']) : '';
$vaultToleranceEur = isset($_GET['vault_tolerance_eur']) ? trim((string) $_GET['vault_tolerance_eur']) : '';
$vaultTargetEur = preg_match('/^\d+([.,]\d+)?$/', str_replace(',', '.', $vaultTargetEur)) ? str_replace(',', '.', $vaultTargetEur) : '';
$vaultToleranceEur = preg_match('/^\d+([.,]\d+)?$/', str_replace(',', '.', $vaultToleranceEur)) ? str_replace(',', '.', $vaultToleranceEur) : '';

/** @var callable(string): string $cpDashboardHref */
$cpDashboardHref = static function (string $cp) use ($dateFrom, $dateTo): string {
    $cp = strtolower(trim($cp));
    $q = [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'counterparty' => (preg_match('/^0x[a-f0-9]{40}$/', $cp) ? $cp : ''),
    ];

    return 'wallets.php?' . http_build_query($q);
};

$activePage = 'transfers';
$dashboardUrl = 'index.php?' . http_build_query([
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'counterparty' => $counterparty,
]);
$walletsUrl = 'wallets.php?' . http_build_query([
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'counterparty' => $counterparty,
    'pa' => max(1, (int) ($_GET['pa'] ?? 1)),
    'pw' => max(1, (int) ($_GET['pw'] ?? 1)),
]);
$transfersUrl = 'transfers.php?' . http_build_query([
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'counterparty' => $counterparty,
    'pt' => max(1, (int) ($_GET['pt'] ?? 1)),
]);
$qualityUrl = 'quality.php?' . http_build_query([
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'counterparty' => $counterparty,
]);
$concentrationUrl = 'concentration.php?' . http_build_query([
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'counterparty' => $counterparty,
]);
$deferEndpoint = 'defer_transfers.php';
$loadCharts = false;
$deferredStatusText = 'Chargement des transferts (requête lourde sur la période)…';

$dashboardOgBase = monitor_dashboard_public_base($cfg);
$dashboardOgPage = $dashboardOgBase . '/' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'transfers.php'));
$dashboardOgImage = $dashboardOgBase . '/lib/deblock.png';

require __DIR__ . '/views/dashboard.php';
