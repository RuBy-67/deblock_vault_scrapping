<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/config.php';
require __DIR__ . '/db.php';
require_once __DIR__ . '/lib/data.php';

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

try {
    $pdo = monitor_pdo($cfg);
    $f = monitor_dashboard_filter_parts($dateFrom, $dateTo, $counterparty);
    extract(monitor_dashboard_collect_flows($pdo, $f), EXTR_OVERWRITE);
    ob_start();
    require __DIR__ . '/views/flows_panel.php';
    $html = ob_get_clean();
    echo json_encode(['cardsHtml' => '', 'html' => $html, 'chartPayloadJson' => $chartPayloadJson, 'initCharts' => true], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
