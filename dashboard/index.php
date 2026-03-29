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
