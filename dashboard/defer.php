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
$counterparty = isset($_GET['counterparty']) ? strtolower(trim((string) $_GET['counterparty'])) : '';
if ($counterparty !== '' && !preg_match('/^0x[a-f0-9]{40}$/', $counterparty)) {
    $counterparty = '';
}

try {
    $pdo = monitor_pdo($cfg);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Connexion MySQL : ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $f = monitor_dashboard_filter_parts($dateFrom, $dateTo, $counterparty);
    $shell = monitor_dashboard_collect_shell($pdo, $f, $cfg);
    $heavy = monitor_dashboard_collect_heavy($pdo, $f, $cfg, $shell['byType']);
    $merged = array_merge($shell, $heavy);
    extract($merged, EXTR_OVERWRITE);
    $dateFrom = $f['dateFrom'];
    $dateTo = $f['dateTo'];
    $counterparty = $f['counterparty'];

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

    ob_start();
    require __DIR__ . '/views/cards_panel.php';
    $cardsHtml = ob_get_clean();

    ob_start();
    require __DIR__ . '/views/deferred_panel.php';
    $html = ob_get_clean();

    $initCharts = $chartDaily !== [] || $hasWeeklyPaymentChart || ($hasGasDailyChart ?? false) || ($hasMintDailyChart ?? false);

    echo json_encode(
        [
            'cardsHtml' => $cardsHtml,
            'html' => $html,
            'chartPayloadJson' => $chartPayloadJson,
            'initCharts' => $initCharts,
        ],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
