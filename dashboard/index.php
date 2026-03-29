<?php

declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/lib/data.php';

$root = dirname(__DIR__);
$cfg = monitor_load_env($root);

$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$counterparty = isset($_GET['counterparty']) ? strtolower(trim((string) $_GET['counterparty'])) : '';
if ($counterparty !== '' && !preg_match('/^0x[a-f0-9]{40}$/', $counterparty)) {
    $counterparty = '';
}

try {
    $pdo = monitor_pdo($cfg);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>Connexion MySQL : ' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}

$view = monitor_dashboard_collect($pdo, $dateFrom, $dateTo, $counterparty, $cfg);
extract($view, EXTR_OVERWRITE);

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

require __DIR__ . '/views/dashboard.php';
