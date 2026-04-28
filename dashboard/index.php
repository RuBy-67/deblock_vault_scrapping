<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';

$root = dirname(__DIR__);
$cfg = monitor_load_env($root);
$minDateFrom = '2026-03-08';
try {
    $pdo = monitor_pdo($cfg);
    $stMinDate = $pdo->query("SELECT DATE(MIN(block_time)) AS min_day FROM raw_transfers");
    $minRow = $stMinDate ? ($stMinDate->fetch() ?: []) : [];
    $minDb = (string) ($minRow['min_day'] ?? '');
    if ($minDb !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $minDb)) {
        $minDateFrom = $minDb;
    }
} catch (Throwable $e) {
    // Fallback silencieux : garde une date minimale statique si la DB n'est pas accessible.
}

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
if ($dateFrom === '' || $dateTo === '') {
    $now = new DateTimeImmutable('now');
    $dateFrom = $dateFrom !== '' ? $dateFrom : $minDateFrom;
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

    return 'index.php?' . http_build_query($q);
};

$activePage = 'dashboard';
$dashboardUrl = 'index.php?' . http_build_query([
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'counterparty' => $counterparty,
]);
$walletsUrl = 'wallets.php?' . http_build_query([
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'counterparty' => $counterparty,
]);
$transfersUrl = 'transfers.php?' . http_build_query([
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'counterparty' => $counterparty,
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
$deferEndpoint = 'defer_dashboard.php';
$loadCharts = true;
$deferredStatusText = 'Chargement des graphiques…';

$dashboardOgBase = monitor_dashboard_public_base($cfg);
$dashboardOgPage = $dashboardOgBase . '/' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
$dashboardOgImage = $dashboardOgBase . '/lib/deblock.png';

require __DIR__ . '/views/dashboard.php';
