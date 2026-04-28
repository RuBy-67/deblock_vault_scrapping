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

$vaultTargetEur = isset($_GET['vault_target_eur']) ? trim((string) $_GET['vault_target_eur']) : '';
$vaultToleranceEur = isset($_GET['vault_tolerance_eur']) ? trim((string) $_GET['vault_tolerance_eur']) : '';
$vaultTargetEur = preg_match('/^\d+([.,]\d+)?$/', str_replace(',', '.', $vaultTargetEur)) ? $vaultTargetEur : '';
$vaultToleranceEur = preg_match('/^\d+([.,]\d+)?$/', str_replace(',', '.', $vaultToleranceEur)) ? $vaultToleranceEur : '';

try {
    $pdo = monitor_pdo($cfg);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Connexion MySQL : ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $f = monitor_dashboard_filter_parts($dateFrom, $dateTo, $counterparty);

    $amountSearchMode = ($counterparty === '' && $vaultTargetEur !== '');
    $vaultMatches = [];
    if ($amountSearchMode) {
        $vaultTargetRawWei = eur_to_raw_wei($vaultTargetEur);
        if ($vaultTargetRawWei !== '0') {
            $vaultToleranceRawWei = $vaultToleranceEur !== '' ? eur_to_raw_wei($vaultToleranceEur) : null;
            $vaultMatches = monitor_dashboard_search_vault_v1_counterparties(
                $pdo,
                $f,
                $vaultTargetRawWei,
                $vaultToleranceRawWei,
                25
            );
        }
    }

    if ($amountSearchMode) {
        $vaultTargetEur = $vaultTargetEur !== '' ? str_replace(',', '.', $vaultTargetEur) : '';
        $vaultToleranceEur = $vaultToleranceEur !== '' ? str_replace(',', '.', $vaultToleranceEur) : '';
        $chartDaily = [];
        $hasWeeklyPaymentChart = false;
        $chartPayloadJson = '{}';
        $recentTransfersLimit = MONITOR_RECENT_TRANSFERS_LIMIT;
        $cpDashboardHref = static function (string $cp) use ($dateFrom, $dateTo): string {
            $cp = strtolower(trim($cp));
            $q = [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'counterparty' => (preg_match('/^0x[a-f0-9]{40}$/', $cp) ? $cp : ''),
            ];
            return 'index.php?' . http_build_query($q);
        };
        ob_start();
        require __DIR__ . '/views/cards_search_mode.php';
        $cardsHtml = ob_get_clean();
        ob_start();
        require __DIR__ . '/views/deferred_panel.php';
        $html = ob_get_clean();
        echo json_encode(['cardsHtml' => $cardsHtml, 'html' => $html, 'chartPayloadJson' => '{}', 'initCharts' => false], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
        exit;
    }

    if (($f['counterparty'] ?? '') === '' && monitor_daily_metrics_available($pdo)) {
        try {
            $merged = monitor_dashboard_collect_from_daily_metrics($pdo, $f);
            $vaultGlobal = monitor_dashboard_collect_vault_global($pdo, $f);
            $merged['vaultApproxBizRaw'] = $vaultGlobal['vaultApproxGlobalRaw'];
            $payload = json_decode((string) ($merged['chartPayloadJson'] ?? '{}'), true);
            if (is_array($payload)) {
                $payload['vaultDaily'] = $vaultGlobal['chartVaultGlobalDaily'];
                $payload['vaultDeltaDaily'] = $vaultGlobal['chartVaultGlobalDeltaDaily'];
                $merged['chartPayloadJson'] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
            }
        } catch (Throwable $e) {
            $shell = monitor_dashboard_collect_shell($pdo, $f, $cfg);
            $vaultGlobal = monitor_dashboard_collect_vault_global($pdo, $f);
            if (($f['counterparty'] ?? '') === '') {
                $shell['vaultApproxBizRaw'] = $vaultGlobal['vaultApproxGlobalRaw'];
            }
            $heavy = monitor_dashboard_collect_heavy($pdo, $f, $cfg, $shell['byType'], false);
            if (($f['counterparty'] ?? '') === '') {
                $payload = json_decode((string) ($heavy['chartPayloadJson'] ?? '{}'), true);
                if (is_array($payload)) {
                    $payload['vaultDaily'] = $vaultGlobal['chartVaultGlobalDaily'];
                    $payload['vaultDeltaDaily'] = $vaultGlobal['chartVaultGlobalDeltaDaily'];
                    $heavy['chartPayloadJson'] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
                }
            }
            $merged = array_merge($shell, $heavy);
        }
    } else {
        $shell = monitor_dashboard_collect_shell($pdo, $f, $cfg);
        $vaultGlobal = monitor_dashboard_collect_vault_global($pdo, $f);
        if (($f['counterparty'] ?? '') === '') {
            $shell['vaultApproxBizRaw'] = $vaultGlobal['vaultApproxGlobalRaw'];
        }
        $heavy = monitor_dashboard_collect_heavy($pdo, $f, $cfg, $shell['byType'], false);
        if (($f['counterparty'] ?? '') === '') {
            $payload = json_decode((string) ($heavy['chartPayloadJson'] ?? '{}'), true);
            if (is_array($payload)) {
                $payload['vaultDaily'] = $vaultGlobal['chartVaultGlobalDaily'];
                $payload['vaultDeltaDaily'] = $vaultGlobal['chartVaultGlobalDeltaDaily'];
                $heavy['chartPayloadJson'] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
            }
        }
        $merged = array_merge($shell, $heavy);
    }

    extract($merged, EXTR_OVERWRITE);
    $dateFrom = $f['dateFrom'];
    $dateTo = $f['dateTo'];
    $counterparty = $f['counterparty'];
    $cpDashboardHref = static function (string $cp) use ($dateFrom, $dateTo): string {
        $cp = strtolower(trim($cp));
        $q = ['date_from' => $dateFrom, 'date_to' => $dateTo, 'counterparty' => (preg_match('/^0x[a-f0-9]{40}$/', $cp) ? $cp : '')];
        return 'index.php?' . http_build_query($q);
    };

    ob_start();
    require __DIR__ . '/views/cards_panel.php';
    $cardsHtml = ob_get_clean();
    ob_start();
    require __DIR__ . '/views/deferred_panel.php';
    $html = ob_get_clean();

    $initCharts = $chartDaily !== [] || $hasWeeklyPaymentChart || ($hasGasDailyChart ?? false);
    echo json_encode(['cardsHtml' => $cardsHtml, 'html' => $html, 'chartPayloadJson' => $chartPayloadJson, 'initCharts' => $initCharts], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
