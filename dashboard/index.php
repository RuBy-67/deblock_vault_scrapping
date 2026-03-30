<?php

declare(strict_types=1);

require __DIR__ . '/config.php';

$root = dirname(__DIR__);
$cfg = monitor_load_env($root);

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
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

    return '?' . http_build_query($q);
};

$dashboardOgBase = monitor_dashboard_public_base($cfg);
$dashboardOgPage = $dashboardOgBase . '/' . basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
$dashboardOgImage = $dashboardOgBase . '/lib/deblock.png';

require __DIR__ . '/views/dashboard.php';
