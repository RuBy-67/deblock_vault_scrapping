<?php

declare(strict_types=1);

require_once __DIR__ . '/format.php';

/** Nombre de lignes pour le tableau « Derniers transferts ». */
const MONITOR_RECENT_TRANSFERS_LIMIT = 100;

/**
 * Filtres SQL communs (dates, contrepartie).
 *
 * @return array{w: string, params: list<mixed>, cpSql: string, cpJoinLeftSql: string, excludeZeroPeerSql: string, zeroAddr: string, dateFrom: string, dateTo: string, counterparty: string}
 */
function monitor_dashboard_filter_parts(string $dateFrom, string $dateTo, string $counterparty): array
{
    $whereRt = ['1=1'];
    $params = [];
    if ($dateFrom !== '') {
        $whereRt[] = 'rt.block_time >= ?';
        $params[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $whereRt[] = 'rt.block_time <= ?';
        $params[] = $dateTo . ' 23:59:59';
    }
    $cpSql = '';
    $cpJoinLeftSql = '';
    if ($counterparty !== '') {
        $cpJoinLeftSql = ' LEFT JOIN classified_events ce ON ce.raw_transfer_id = rt.id ';
        $cpSql = ' AND ce.counterparty = ? ';
        $params[] = $counterparty;
    }

    $zeroAddr = '0x0000000000000000000000000000000000000000';
    $excludeZeroPeerSql = " AND NOT (
  (rt.direction = 'in'  AND rt.from_addr = '$zeroAddr')
  OR (rt.direction = 'out' AND rt.to_addr = '$zeroAddr')
)";

    return [
        'w' => implode(' AND ', $whereRt),
        'params' => $params,
        'cpSql' => $cpSql,
        'cpJoinLeftSql' => $cpJoinLeftSql,
        'excludeZeroPeerSql' => $excludeZeroPeerSql,
        'zeroAddr' => $zeroAddr,
        'dateFrom' => $dateFrom,
        'dateTo' => $dateTo,
        'counterparty' => $counterparty,
    ];
}

/**
 * Métriques « cartes » du haut (requêtes légères + agrégats cartes).
 *
 * @param array{w: string, params: list<mixed>, cpSql: string, cpJoinLeftSql: string, excludeZeroPeerSql: string, zeroAddr: string, dateFrom: string, dateTo: string, counterparty: string} $f
 * @return array<string, mixed>
 */
function monitor_dashboard_collect_shell(PDO $pdo, array $f, array $cfg): array
{
    $w = $f['w'];
    $params = $f['params'];
    $cpSql = $f['cpSql'];
    $cpJoinLeftSql = $f['cpJoinLeftSql'];
    $excludeZeroPeerSql = $f['excludeZeroPeerSql'];
    $dateFrom = $f['dateFrom'];
    $dateTo = $f['dateTo'];
    $zeroAddr = $f['zeroAddr'];
    $counterparty = $f['counterparty'];

    $sqlFlux = "
SELECT
  SUM(CASE WHEN rt.direction = 'in'  THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_in_raw,
  SUM(CASE WHEN rt.direction = 'out' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_out_raw,
  COUNT(*) AS n_tx
FROM raw_transfers rt
{$cpJoinLeftSql}
WHERE $w
  $cpSql
  $excludeZeroPeerSql
";
    $st = $pdo->prepare($sqlFlux);
    $st->execute($params);
    $flux = $st->fetch() ?: [];

    $sqlClass = "
SELECT
  ce.event_type,
  COUNT(*) AS n,
  SUM(
    CASE
      WHEN ce.event_type = 'interest'
        AND ce.paired_transfer_id IS NOT NULL
        AND ce.raw_transfer_id > ce.paired_transfer_id
      THEN 0
      ELSE CAST(rt.amount_raw AS DECIMAL(65,0))
    END
  ) AS sum_raw
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE $w
  $cpSql
  $excludeZeroPeerSql
GROUP BY ce.event_type
";
    $st2 = $pdo->prepare($sqlClass);
    $st2->execute($params);
    $byType = $st2->fetchAll();

    $sqlFees = "
SELECT
  COALESCE(SUM(x.fee_raw), 0) AS fee_sum_raw
FROM (
  SELECT CAST(NULLIF(ce.fee_token_raw, '') AS DECIMAL(65,0)) AS fee_raw
  FROM raw_transfers rt
  JOIN classified_events ce ON ce.raw_transfer_id = rt.id
  WHERE $w
    AND ce.fee_token_raw IS NOT NULL
    AND (
      ce.paired_transfer_id IS NULL
      OR ce.raw_transfer_id < ce.paired_transfer_id
    )
    $cpSql
    $excludeZeroPeerSql
) x
";
    $st3 = $pdo->prepare($sqlFees);
    $st3->execute($params);
    $feeRow = $st3->fetch() ?: [];

    $sqlGas = "
SELECT COALESCE(SUM(tg.cost_eth), 0) AS gas_eth
FROM tx_gas tg
WHERE EXISTS (
  SELECT 1 FROM raw_transfers rt
  {$cpJoinLeftSql}
  WHERE rt.tx_hash = tg.tx_hash
    AND $w
    $cpSql
    $excludeZeroPeerSql
)
";
    $st4 = $pdo->prepare($sqlGas);
    $st4->execute($params);
    $gasRow = $st4->fetch() ?: [];

    $inUserRaw = normalize_amount_raw($flux['sum_in_raw'] ?? '0');
    $outUserRaw = normalize_amount_raw($flux['sum_out_raw'] ?? '0');
    $inTotalRaw = $inUserRaw;
    $outTotalRaw = $outUserRaw;
    $nTxAllRaw = (int) ($flux['n_tx'] ?? 0);
    $paymentSumRaw = '0';
    $topUpSumRaw = '0';
    $nPaymentClass = 0;
    $nTopUpClass = 0;
    foreach ($byType as $br) {
        $et = (string) ($br['event_type'] ?? '');
        if ($et === 'payment') {
            $paymentSumRaw = normalize_amount_raw($br['sum_raw'] ?? '0');
            $nPaymentClass = (int) ($br['n'] ?? 0);
        } elseif ($et === 'top_up') {
            $topUpSumRaw = normalize_amount_raw($br['sum_raw'] ?? '0');
            $nTopUpClass = (int) ($br['n'] ?? 0);
        }
    }
    $vaultApproxBizRaw = raw_sub($topUpSumRaw, $paymentSumRaw);
    $netChainRaw = raw_sub($outTotalRaw, $inTotalRaw);
    $reconciliationGapRaw = raw_sub($vaultApproxBizRaw, $netChainRaw);

    return [
        'cfg' => $cfg,
        'dateFrom' => $dateFrom,
        'dateTo' => $dateTo,
        'counterparty' => $counterparty,
        'flux' => $flux,
        'byType' => $byType,
        'feeRow' => $feeRow,
        'gasRow' => $gasRow,
        'inTotalRaw' => $inTotalRaw,
        'outTotalRaw' => $outTotalRaw,
        'nTxAllRaw' => $nTxAllRaw,
        'fluxCardShowOnchainSplit' => ($counterparty === ''),
        'inMintRaw' => '0',
        'inUserRaw' => $inUserRaw,
        'outBurnRaw' => '0',
        'outUserRaw' => $outUserRaw,
        'paymentSumRaw' => $paymentSumRaw,
        'topUpSumRaw' => $topUpSumRaw,
        'nPaymentClass' => $nPaymentClass,
        'nTopUpClass' => $nTopUpClass,
        'vaultApproxBizRaw' => $vaultApproxBizRaw,
        'netChainRaw' => $netChainRaw,
        'reconciliationGapRaw' => $reconciliationGapRaw,
        'recentTransfersLimit' => MONITOR_RECENT_TRANSFERS_LIMIT,
    ];
}

/**
 * Recherche de wallets dont le "vault v1" (top_up - payment) est proche d’une valeur cible.
 *
 * Important : calcul basé sur la même classification v1 que le dashboard (event_type payment/top_up).
 *
 * @return list<array<string, mixed>>
 */
function monitor_dashboard_search_vault_v1_counterparties(
    PDO $pdo,
    array $f,
    string $vaultTargetRawWei,
    ?string $vaultToleranceRawWei,
    int $limit = 20
): array {
    $w = $f['w'];
    $params = $f['params'];
    $cpSql = $f['cpSql'];
    $excludeZeroPeerSql = $f['excludeZeroPeerSql'];

    // inner = agrégats par contrepartie.
    $sqlInner = "
SELECT
  LOWER(ce.counterparty) AS cp,
  SUM(CASE WHEN ce.event_type = 'payment' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_payment_raw,
  SUM(CASE WHEN ce.event_type = 'top_up' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_topup_raw,
  (
    SUM(CASE WHEN ce.event_type = 'top_up' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END)
    -
    SUM(CASE WHEN ce.event_type = 'payment' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END)
  ) AS vault_approx_raw,
  COUNT(CASE WHEN ce.event_type = 'payment' THEN 1 END) AS n_payment,
  COUNT(CASE WHEN ce.event_type = 'top_up' THEN 1 END) AS n_topup
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE $w
  $cpSql
  $excludeZeroPeerSql
  AND ce.event_type IN ('payment', 'top_up')
GROUP BY LOWER(ce.counterparty)
";

    if ($vaultToleranceRawWei !== null && $vaultToleranceRawWei !== '0') {
        $sql = "
SELECT
  x.cp,
  x.sum_payment_raw,
  x.sum_topup_raw,
  x.vault_approx_raw,
  x.n_payment,
  x.n_topup,
  CAST(ABS(x.vault_approx_raw - ?) AS DECIMAL(65,0)) AS abs_diff_raw
FROM (
  {$sqlInner}
) x
WHERE ABS(x.vault_approx_raw - ?) <= ?
ORDER BY ABS(x.vault_approx_raw - ?) ASC
LIMIT {$limit}
";

        $bind = array_merge($params, [$vaultTargetRawWei, $vaultTargetRawWei, $vaultToleranceRawWei, $vaultTargetRawWei]);
    } else {
        $sql = "
SELECT
  x.cp,
  x.sum_payment_raw,
  x.sum_topup_raw,
  x.vault_approx_raw,
  x.n_payment,
  x.n_topup,
  CAST(ABS(x.vault_approx_raw - ?) AS DECIMAL(65,0)) AS abs_diff_raw
FROM (
  {$sqlInner}
) x
ORDER BY ABS(x.vault_approx_raw - ?) ASC
LIMIT {$limit}
";
        $bind = array_merge($params, [$vaultTargetRawWei, $vaultTargetRawWei]);
    }

    $st = $pdo->prepare($sql);
    $st->execute($bind);
    return $st->fetchAll() ?: [];
}

/**
 * Graphiques, agrégats détaillés, tableaux (requêtes lourdes).
 *
 * @param array{w: string, params: list<mixed>, cpSql: string, cpJoinLeftSql: string, excludeZeroPeerSql: string, zeroAddr: string, dateFrom: string, dateTo: string, counterparty: string} $f
 * @param list<array<string, mixed>>|null $reuseByType si fourni, évite de relire le GROUP BY par type (fusion page complète).
 * @param bool $includeTables false: saute les tableaux détaillés (rows/top wallets) pour accélérer la vue dashboard.
 * @return array<string, mixed>
 */
function monitor_dashboard_collect_heavy(PDO $pdo, array $f, array $cfg, ?array $reuseByType = null, bool $includeTables = true): array
{
    $w = $f['w'];
    $params = $f['params'];
    $cpSql = $f['cpSql'];
    $cpJoinLeftSql = $f['cpJoinLeftSql'];
    $excludeZeroPeerSql = $f['excludeZeroPeerSql'];
    $dateFrom = $f['dateFrom'];
    $dateTo = $f['dateTo'];
    $counterparty = $f['counterparty'];
    $zeroAddr = $f['zeroAddr'];
    $monthStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00');
    $monthEnd = (new DateTimeImmutable('now'))->format('Y-m-d 23:59:59');

    if ($reuseByType !== null) {
        $byType = $reuseByType;
    } else {
        $sqlClass = "
SELECT
  ce.event_type,
  COUNT(*) AS n,
  SUM(
    CASE
      WHEN ce.event_type = 'interest'
        AND ce.paired_transfer_id IS NOT NULL
        AND ce.raw_transfer_id > ce.paired_transfer_id
      THEN 0
      ELSE CAST(rt.amount_raw AS DECIMAL(65,0))
    END
  ) AS sum_raw
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE $w
  $cpSql
  $excludeZeroPeerSql
GROUP BY ce.event_type
";
        $st2 = $pdo->prepare($sqlClass);
        $st2->execute($params);
        $byType = $st2->fetchAll();
    }

    $sqlDaily = "
SELECT
  DATE(rt.block_time) AS day,
  COUNT(*) AS n_tx
FROM raw_transfers rt
{$cpJoinLeftSql}
WHERE $w
  $cpSql
GROUP BY DATE(rt.block_time)
ORDER BY day ASC
";
    $stDaily = $pdo->prepare($sqlDaily);
    $stDaily->execute($params);
    $dailyRows = $stDaily->fetchAll();

    // Gas (ETH) total par jour (coût ETH de tx, sans doublons par tx_hash).
    $sqlDailyGas = "
SELECT
  DATE(t.block_time) AS day,
  SUM(t.cost_eth) AS gas_eth
FROM (
  SELECT
    tg.tx_hash,
    MIN(rt.block_time) AS block_time,
    tg.cost_eth
  FROM tx_gas tg
  JOIN raw_transfers rt ON rt.tx_hash = tg.tx_hash
  {$cpJoinLeftSql}
  WHERE $w
    $cpSql
    $excludeZeroPeerSql
  GROUP BY tg.tx_hash
) t
GROUP BY DATE(t.block_time)
ORDER BY day ASC
";
    $stDailyGas = $pdo->prepare($sqlDailyGas);
    $stDailyGas->execute($params);
    $gasDailyRows = $stDailyGas->fetchAll();

    $sqlDailyInterest = "
SELECT
  DATE(rt.block_time) AS day,
  SUM(
    CASE
      WHEN ce.paired_transfer_id IS NOT NULL
        AND ce.raw_transfer_id > ce.paired_transfer_id
      THEN 0
      ELSE CAST(rt.amount_raw AS DECIMAL(65,0))
    END
  ) AS sum_interest_raw,
  COUNT(*) AS n_interest_lines
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE $w
  $cpSql
  $excludeZeroPeerSql
  AND ce.event_type = 'interest'
GROUP BY DATE(rt.block_time)
ORDER BY day ASC
";
    $stDailyInterest = $pdo->prepare($sqlDailyInterest);
    $stDailyInterest->execute($params);
    $interestDailyRows = $stDailyInterest->fetchAll();

    $sqlDailyNodeVolume = "
SELECT
  DATE(rt.block_time) AS day,
  SUM(CAST(rt.amount_raw AS DECIMAL(65,0))) AS sum_volume_raw,
  COUNT(*) AS n_tx
FROM raw_transfers rt
{$cpJoinLeftSql}
WHERE $w
  $cpSql
  $excludeZeroPeerSql
GROUP BY DATE(rt.block_time)
ORDER BY day ASC
";
    $stDailyNodeVol = $pdo->prepare($sqlDailyNodeVolume);
    $stDailyNodeVol->execute($params);
    $nodeVolumeDailyRows = $stDailyNodeVol->fetchAll();

    $sqlDailyClass = "
SELECT
  DATE(rt.block_time) AS day,
  ce.event_type,
  SUM(CAST(rt.amount_raw AS DECIMAL(65,0))) AS sum_raw,
  COUNT(*) AS n_type
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE $w
  $cpSql
  $excludeZeroPeerSql
  AND ce.event_type IN ('payment', 'top_up')
GROUP BY DATE(rt.block_time), ce.event_type
ORDER BY day ASC, ce.event_type
";
    $stDailyClass = $pdo->prepare($sqlDailyClass);
    $stDailyClass->execute($params);
    $classDailyRows = $stDailyClass->fetchAll();

    $sqlWeeklyPayment = "
SELECT
  DATE(DATE_SUB(DATE(rt.block_time), INTERVAL WEEKDAY(rt.block_time) DAY)) AS week_start,
  SUM(CAST(rt.amount_raw AS DECIMAL(65,0))) AS sum_pay_raw,
  COUNT(*) AS n_pay,
  COUNT(DISTINCT LOWER(ce.counterparty)) AS n_distinct_payers
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE $w
  $cpSql
  $excludeZeroPeerSql
  AND ce.event_type = 'payment'
GROUP BY week_start
ORDER BY week_start ASC
";
    $stWeekly = $pdo->prepare($sqlWeeklyPayment);
    $stWeekly->execute($params);
    $weekPayRows = $stWeekly->fetchAll();

    $sqlPaymentMonthlyByAccount = "
SELECT
  COUNT(DISTINCT LOWER(ce.counterparty)) AS n_distinct_month,
  COALESCE(SUM(CAST(rt.amount_raw AS DECIMAL(65,0))), 0) AS sum_month_raw
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE rt.block_time >= ?
  AND rt.block_time <= ?
  $cpSql
  $excludeZeroPeerSql
  AND ce.event_type = 'payment'
";
    $paramsMonthPayment = [$monthStart, $monthEnd];
    if ($counterparty !== '') {
        $paramsMonthPayment[] = $counterparty;
    }
    $stPayMonth = $pdo->prepare($sqlPaymentMonthlyByAccount);
    $stPayMonth->execute($paramsMonthPayment);
    $rowPayMonth = $stPayMonth->fetch() ?: [];
    $nDistinctPayersPaymentMonth = (int) ($rowPayMonth['n_distinct_month'] ?? 0);
    $totalPaymentMonthRaw = normalize_amount_raw($rowPayMonth['sum_month_raw'] ?? '0');
    $avgPayPerDistinctAccountMonthRaw = ($nDistinctPayersPaymentMonth > 0 && function_exists('bcdiv'))
        ? bcdiv($totalPaymentMonthRaw, (string) $nDistinctPayersPaymentMonth, 0)
        : '0';

    $rows = [];
    $topCounterparties = [];
    $topWalletsByToken = [];
    $lim = MONITOR_RECENT_TRANSFERS_LIMIT;
    if ($includeTables) {
        $sqlList = "
SELECT
  rt.id,
  rt.tx_hash,
  rt.block_time,
  rt.direction,
  rt.from_addr,
  rt.to_addr,
  rt.amount_raw,
  ce.event_type,
  ce.counterparty,
  ce.fee_token_raw,
  ce.rule_version,
  ce.confidence,
  tg.cost_eth
FROM raw_transfers rt
LEFT JOIN classified_events ce ON ce.raw_transfer_id = rt.id
LEFT JOIN tx_gas tg ON tg.tx_hash = rt.tx_hash
WHERE $w
  $cpSql
  $excludeZeroPeerSql
ORDER BY rt.block_time DESC, rt.id DESC
LIMIT {$lim}
";
        $st5 = $pdo->prepare($sqlList);
        $st5->execute($params);
        $rows = $st5->fetchAll();

        if ($counterparty === '') {
            $sqlTopCp = "
SELECT
  (CASE WHEN rt.direction = 'in' THEN rt.from_addr ELSE rt.to_addr END) AS cp,
  SUM(CASE WHEN rt.direction = 'in' THEN 1 ELSE 0 END) AS n_in,
  SUM(CASE WHEN rt.direction = 'out' THEN 1 ELSE 0 END) AS n_out,
  SUM(CASE WHEN rt.direction = 'in' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_in_raw,
  SUM(CASE WHEN rt.direction = 'out' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_out_raw,
  MIN(rt.block_time) AS first_seen,
  MAX(rt.block_time) AS last_seen,
  COUNT(*) AS n_total
FROM raw_transfers rt
{$cpJoinLeftSql}
WHERE $w
  $excludeZeroPeerSql
GROUP BY (CASE WHEN rt.direction = 'in' THEN rt.from_addr ELSE rt.to_addr END)
ORDER BY n_total DESC
LIMIT 50
";
            $stCp = $pdo->prepare($sqlTopCp);
            $stCp->execute($params);
            $topCounterparties = $stCp->fetchAll();

            $sqlTopWallets = "
SELECT
  LOWER(ce.counterparty) AS cp,
  SUM(CASE WHEN ce.event_type = 'payment' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_payment_raw,
  SUM(CASE WHEN ce.event_type = 'top_up' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_topup_raw,
  (
    SUM(CASE WHEN ce.event_type = 'top_up' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END)
    -
    SUM(CASE WHEN ce.event_type = 'payment' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END)
  ) AS wallet_tokens_raw,
  COUNT(CASE WHEN ce.event_type = 'payment' THEN 1 END) AS n_payment,
  COUNT(CASE WHEN ce.event_type = 'top_up' THEN 1 END) AS n_topup
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE $w
  $excludeZeroPeerSql
  AND ce.event_type IN ('payment', 'top_up')
GROUP BY LOWER(ce.counterparty)
ORDER BY wallet_tokens_raw DESC
LIMIT 50
";
            $stTopWallets = $pdo->prepare($sqlTopWallets);
            $stTopWallets->execute($params);
            $topWalletsByToken = $stTopWallets->fetchAll();
        }
    }

    $chartDaily = [];
    foreach ($dailyRows as $dr) {
        $chartDaily[] = [
            'day' => (string) $dr['day'],
            'n' => (int) ($dr['n_tx'] ?? 0),
        ];
    }

    // Gas (ETH) cumulé au fil du temps (progression).
    $chartGasDaily = [];
    $gasRunningEth = 0.0;
    foreach ($gasDailyRows as $gr) {
        $gasRunningEth += (float) ($gr['gas_eth'] ?? 0);
        $chartGasDaily[] = [
            'day' => (string) ($gr['day'] ?? ''),
            'gasEth' => $gasRunningEth,
        ];
    }

    $interestByDay = [];
    foreach ($interestDailyRows as $idr) {
        $dInt = (string) ($idr['day'] ?? '');
        $interestByDay[$dInt] = [
            'sumRaw' => normalize_amount_raw($idr['sum_interest_raw'] ?? '0'),
            'nLines' => (int) ($idr['n_interest_lines'] ?? 0),
        ];
    }
    $chartInterestDaily = [];
    foreach ($chartDaily as $cdRow) {
        $dKey = $cdRow['day'];
        $pack = $interestByDay[$dKey] ?? null;
        $sumR = $pack === null ? '0' : $pack['sumRaw'];
        $chartInterestDaily[] = [
            'day' => $dKey,
            'interestEur' => raw_wei_to_float_eur($sumR),
            'nInterest' => $pack === null ? 0 : $pack['nLines'],
        ];
    }

    $nodeVolumeByDay = [];
    foreach ($nodeVolumeDailyRows as $nvr) {
        $dVol = (string) ($nvr['day'] ?? '');
        $nodeVolumeByDay[$dVol] = [
            'sumRaw' => normalize_amount_raw($nvr['sum_volume_raw'] ?? '0'),
            'nTx' => (int) ($nvr['n_tx'] ?? 0),
        ];
    }
    $chartNodeVolumeDaily = [];
    foreach ($chartDaily as $cdRow) {
        $dKey = $cdRow['day'];
        $vp = $nodeVolumeByDay[$dKey] ?? null;
        $sumR = $vp === null ? '0' : $vp['sumRaw'];
        $chartNodeVolumeDaily[] = [
            'day' => $dKey,
            'volumeEur' => raw_wei_to_float_eur($sumR),
            'nTx' => $vp === null ? 0 : $vp['nTx'],
        ];
    }

    $classByDay = [];
    foreach ($chartDaily as $row) {
        $classByDay[$row['day']] = [
            'payment' => 0.0,
            'top_up' => 0.0,
            'nPayment' => 0,
            'nTopUp' => 0,
        ];
    }
    foreach ($classDailyRows as $r) {
        $day = (string) $r['day'];
        $type = (string) $r['event_type'];
        if (!isset($classByDay[$day])) {
            $classByDay[$day] = [
                'payment' => 0.0,
                'top_up' => 0.0,
                'nPayment' => 0,
                'nTopUp' => 0,
            ];
        }
        $sumF = raw_wei_to_float_eur((string) ($r['sum_raw'] ?? '0'));
        $n = (int) ($r['n_type'] ?? 0);
        if ($type === 'payment') {
            $classByDay[$day]['payment'] = $sumF;
            $classByDay[$day]['nPayment'] = $n;
        } elseif ($type === 'top_up') {
            $classByDay[$day]['top_up'] = $sumF;
            $classByDay[$day]['nTopUp'] = $n;
        }
    }

    $chartClassDaily = [];
    foreach ($chartDaily as $row) {
        $d = $row['day'];
        $c = $classByDay[$d];
        $chartClassDaily[] = [
            'day' => $d,
            'payment' => $c['payment'],
            'top_up' => $c['top_up'],
            'nPayment' => $c['nPayment'],
            'nTopUp' => $c['nTopUp'],
        ];
    }

    // Série v1 :
    // - delta jour : top_up - payment
    // - cumul fil du temps : somme des deltas depuis le début du filtre
    $chartVaultDaily = [];
    $chartVaultDeltaDaily = [];
    $vaultRunningEur = 0.0;
    foreach ($chartDaily as $row) {
        $d = $row['day'];
        $c = $classByDay[$d] ?? ['payment' => 0.0, 'top_up' => 0.0];
        $deltaEur = (float) ($c['top_up'] ?? 0.0) - (float) ($c['payment'] ?? 0.0);
        $vaultRunningEur += $deltaEur;
        $chartVaultDeltaDaily[] = [
            'day' => $d,
            'vaultDeltaEur' => $deltaEur,
        ];
        $chartVaultDaily[] = [
            'day' => $d,
            'vaultEur' => $vaultRunningEur,
        ];
    }

    $chartPaymentAvgDaily = [];
    foreach ($chartClassDaily as $cd) {
        $nP = (int) ($cd['nPayment'] ?? 0);
        $vol = (float) ($cd['payment'] ?? 0);
        $chartPaymentAvgDaily[] = [
            'day' => $cd['day'],
            'avgTicketEur' => $nP > 0 ? $vol / $nP : 0.0,
            'nPayment' => $nP,
        ];
    }

    $totalPaymentPeriodRaw = '0';
    $totalPaymentTxWeek = 0;
    $bestWeekStart = null;
    $bestWeekRaw = '0';
    $chartWeeklyPayment = [];
    foreach ($weekPayRows as $wr) {
        $ws = (string) ($wr['week_start'] ?? '');
        $sr = normalize_amount_raw($wr['sum_pay_raw'] ?? '0');
        $np = (int) ($wr['n_pay'] ?? 0);
        $ndPayers = (int) ($wr['n_distinct_payers'] ?? 0);
        $avgPerDistinctWeekRaw = ($ndPayers > 0 && function_exists('bcdiv'))
            ? bcdiv($sr, (string) $ndPayers, 0)
            : '0';
        $totalPaymentPeriodRaw = raw_add($totalPaymentPeriodRaw, $sr);
        $totalPaymentTxWeek += $np;
        if ($bestWeekStart === null) {
            $bestWeekRaw = $sr;
            $bestWeekStart = $ws;
        } elseif (function_exists('bccomp') && bccomp($sr, $bestWeekRaw, 0) > 0) {
            $bestWeekRaw = $sr;
            $bestWeekStart = $ws;
        } elseif (!function_exists('bccomp') && (float) $sr > (float) $bestWeekRaw) {
            $bestWeekRaw = $sr;
            $bestWeekStart = $ws;
        }
        $chartWeeklyPayment[] = [
            'weekStart' => substr($ws, 0, 10),
            'volumeEur' => raw_wei_to_float_eur($sr),
            'nPay' => $np,
            'nDistinctPayers' => $ndPayers,
            'avgPerAccountEur' => raw_wei_to_float_eur($avgPerDistinctWeekRaw),
        ];
    }
    $nWeeksPaymentActive = count($weekPayRows);
    $avgPayActiveWeekRaw = ($nWeeksPaymentActive > 0 && function_exists('bcdiv'))
        ? bcdiv($totalPaymentPeriodRaw, (string) $nWeeksPaymentActive, 0)
        : '0';
    $daysFilterSpan = 0.0;
    if ($dateFrom !== '' && $dateTo !== '') {
        $t0 = strtotime($dateFrom . ' 00:00:00');
        $t1 = strtotime($dateTo . ' 23:59:59');
        if ($t0 !== false && $t1 !== false && $t1 >= $t0) {
            $daysFilterSpan = (float) (($t1 - $t0) / 86400) + 1.0;
        }
    }
    $weeksInFilterSpan = $daysFilterSpan > 0
        ? max(1.0, $daysFilterSpan / 7.0)
        : (float) max(1, $nWeeksPaymentActive);
    $totalPayFloatInsight = raw_wei_to_float_eur($totalPaymentPeriodRaw);
    $avgPayActiveWeekEur = $nWeeksPaymentActive > 0 ? $totalPayFloatInsight / $nWeeksPaymentActive : 0.0;
    $avgPayCalWeekEur = $weeksInFilterSpan > 0 ? $totalPayFloatInsight / $weeksInFilterSpan : 0.0;
    $avgTicketPaymentRaw = ($totalPaymentTxWeek > 0 && function_exists('bcdiv'))
        ? bcdiv($totalPaymentPeriodRaw, (string) $totalPaymentTxWeek, 0)
        : '0';
    $bestPayDay = null;
    $bestPayDayRaw = '0';
    foreach ($classDailyRows as $cdr) {
        if (($cdr['event_type'] ?? '') !== 'payment') {
            continue;
        }
        $sr = normalize_amount_raw($cdr['sum_raw'] ?? '0');
        $win = $bestPayDay === null;
        if (!$win && function_exists('bccomp')) {
            $win = bccomp($sr, $bestPayDayRaw, 0) > 0;
        } elseif (!$win) {
            $win = (float) $sr > (float) $bestPayDayRaw;
        }
        if ($win) {
            $bestPayDayRaw = $sr;
            $bestPayDay = (string) ($cdr['day'] ?? '');
        }
    }
    $topUpInsightRaw = '0';
    $nTopUpInsight = 0;
    foreach ($byType as $brInsight) {
        $eti = (string) ($brInsight['event_type'] ?? '');
        if ($eti === 'top_up') {
            $topUpInsightRaw = normalize_amount_raw($brInsight['sum_raw'] ?? '0');
            $nTopUpInsight = (int) ($brInsight['n'] ?? 0);
        }
    }
    $payVsPayPlusTopFloat = 0.0;
    $tPayF = raw_wei_to_float_eur($totalPaymentPeriodRaw);
    $tTopF = raw_wei_to_float_eur($topUpInsightRaw);
    if (($tPayF + $tTopF) > 0) {
        $payVsPayPlusTopFloat = 100.0 * $tPayF / ($tPayF + $tTopF);
    }
    $hasWeeklyPaymentChart = $chartWeeklyPayment !== [];

    $chartPayload = [
        'daily' => $chartDaily,
        'dailyClass' => $chartClassDaily,
        'interestDaily' => $chartInterestDaily,
        'nodeVolumeDaily' => $chartNodeVolumeDaily,
        'paymentAvgDaily' => $chartPaymentAvgDaily,
        'vaultDaily' => $chartVaultDaily,
        'vaultDeltaDaily' => $chartVaultDeltaDaily,
        'gasDaily' => $chartGasDaily,
        'weeklyPay' => $chartWeeklyPayment,
        'weeklyMeta' => [
            'avgActiveEur' => $avgPayActiveWeekEur,
            'avgCalWeekEur' => $avgPayCalWeekEur,
        ],
    ];

    $chartPayloadJson = json_encode(
        $chartPayload,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
    );

    return [
        'byType' => $byType,
        'rows' => $rows,
        'topCounterparties' => $topCounterparties,
        'topWalletsByToken' => $topWalletsByToken,
        'chartDaily' => $chartDaily,
        'hasWeeklyPaymentChart' => $hasWeeklyPaymentChart,
        'hasGasDailyChart' => $chartGasDaily !== [],
        'chartPayloadJson' => $chartPayloadJson,
        'totalPaymentPeriodRaw' => $totalPaymentPeriodRaw,
        'totalPaymentTxWeek' => $totalPaymentTxWeek,
        'bestWeekStart' => $bestWeekStart,
        'bestWeekRaw' => $bestWeekRaw,
        'nWeeksPaymentActive' => $nWeeksPaymentActive,
        'avgPayActiveWeekRaw' => $avgPayActiveWeekRaw,
        'weeksInFilterSpan' => $weeksInFilterSpan,
        'avgPayCalWeekEur' => $avgPayCalWeekEur,
        'avgTicketPaymentRaw' => $avgTicketPaymentRaw,
        'avgPayPerDistinctAccountMonthRaw' => $avgPayPerDistinctAccountMonthRaw,
        'nDistinctPayersPaymentMonth' => $nDistinctPayersPaymentMonth,
        'bestPayDay' => $bestPayDay,
        'bestPayDayRaw' => $bestPayDayRaw,
        'topUpInsightRaw' => $topUpInsightRaw,
        'nTopUpInsight' => $nTopUpInsight,
        'payVsPayPlusTopFloat' => $payVsPayPlusTopFloat,
        'tPayF' => $tPayF,
        'tTopF' => $tTopF,
    ];
}

/**
 * Série et total "vault v1" globaux (historique complet, sans filtre date/counterparty).
 *
 * @return array{vaultApproxGlobalRaw: string, chartVaultGlobalDaily: list<array{day: string, vaultEur: float}>, chartVaultGlobalDeltaDaily: list<array{day: string, vaultDeltaEur: float}>}
 */
function monitor_dashboard_collect_vault_global(PDO $pdo, array $f): array
{
    $excludeZeroPeerSql = $f['excludeZeroPeerSql'];
    $sqlDailyClassGlobal = "
SELECT
  DATE(rt.block_time) AS day,
  ce.event_type,
  SUM(CAST(rt.amount_raw AS DECIMAL(65,0))) AS sum_raw
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE 1=1
  $excludeZeroPeerSql
  AND ce.event_type IN ('payment', 'top_up')
GROUP BY DATE(rt.block_time), ce.event_type
ORDER BY day ASC, ce.event_type
";
    $st = $pdo->prepare($sqlDailyClassGlobal);
    $st->execute([]);
    $rows = $st->fetchAll();

    $byDay = [];
    foreach ($rows as $r) {
        $d = (string) ($r['day'] ?? '');
        if (!isset($byDay[$d])) {
            $byDay[$d] = ['payment' => 0.0, 'top_up' => 0.0];
        }
        $type = (string) ($r['event_type'] ?? '');
        $sumF = raw_wei_to_float_eur((string) ($r['sum_raw'] ?? '0'));
        if ($type === 'payment') {
            $byDay[$d]['payment'] = $sumF;
        } elseif ($type === 'top_up') {
            $byDay[$d]['top_up'] = $sumF;
        }
    }

    ksort($byDay);
    $chartVaultGlobalDaily = [];
    $chartVaultGlobalDeltaDaily = [];
    $vaultRunning = 0.0;
    foreach ($byDay as $day => $pack) {
        $delta = (float) ($pack['top_up'] ?? 0.0) - (float) ($pack['payment'] ?? 0.0);
        $vaultRunning += $delta;
        $chartVaultGlobalDeltaDaily[] = ['day' => $day, 'vaultDeltaEur' => $delta];
        $chartVaultGlobalDaily[] = ['day' => $day, 'vaultEur' => $vaultRunning];
    }

    $sqlGlobalVault = "
SELECT
  COALESCE(SUM(CASE WHEN ce.event_type = 'top_up' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END), 0)
  -
  COALESCE(SUM(CASE WHEN ce.event_type = 'payment' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END), 0) AS vault_global_raw
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE ce.event_type IN ('payment', 'top_up')
  $excludeZeroPeerSql
";
    $st2 = $pdo->prepare($sqlGlobalVault);
    $st2->execute([]);
    $row = $st2->fetch() ?: [];

    return [
        'vaultApproxGlobalRaw' => normalize_amount_raw($row['vault_global_raw'] ?? '0'),
        'chartVaultGlobalDaily' => $chartVaultGlobalDaily,
        'chartVaultGlobalDeltaDaily' => $chartVaultGlobalDeltaDaily,
    ];
}

function monitor_daily_metrics_available(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $st = $pdo->query("SHOW TABLES LIKE 'daily_metrics'");
    $cache = ($st->fetchColumn() !== false);
    return $cache;
}

/**
 * Lit daily_metrics (si dispo) et reconstruit les payloads dashboard.
 *
 * @return array<string, mixed>
 */
function monitor_dashboard_collect_from_daily_metrics(PDO $pdo, array $f): array
{
    if (($f['counterparty'] ?? '') !== '') {
        throw new RuntimeException('daily_metrics ne supporte pas le filtre counterparty');
    }
    if (!monitor_daily_metrics_available($pdo)) {
        throw new RuntimeException('Table daily_metrics absente');
    }

    $where = ['1=1'];
    $params = [];
    if (($f['dateFrom'] ?? '') !== '') {
        $where[] = 'day >= ?';
        $params[] = $f['dateFrom'];
    }
    if (($f['dateTo'] ?? '') !== '') {
        $where[] = 'day <= ?';
        $params[] = $f['dateTo'];
    }
    $sql = "
SELECT
  day,
  n_tx,
  n_payment,
  n_topup,
  n_interest,
  n_unknown,
  sum_in_raw,
  sum_out_raw,
  sum_payment_raw,
  sum_topup_raw,
  sum_interest_raw,
  gas_eth
FROM daily_metrics
WHERE " . implode(' AND ', $where) . "
ORDER BY day ASC
";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll() ?: [];

    $sqlFees = "
SELECT
  COALESCE(SUM(x.fee_raw), 0) AS fee_sum_raw
FROM (
  SELECT CAST(NULLIF(ce.fee_token_raw, '') AS DECIMAL(65,0)) AS fee_raw
  FROM raw_transfers rt
  JOIN classified_events ce ON ce.raw_transfer_id = rt.id
  WHERE 1=1
    " . (($f['dateFrom'] ?? '') !== '' ? " AND rt.block_time >= ? " : "") . "
    " . (($f['dateTo'] ?? '') !== '' ? " AND rt.block_time <= ? " : "") . "
    AND NOT (
      (rt.direction = 'in'  AND rt.from_addr = '0x0000000000000000000000000000000000000000')
      OR (rt.direction = 'out' AND rt.to_addr = '0x0000000000000000000000000000000000000000')
    )
    AND ce.fee_token_raw IS NOT NULL
    AND (
      ce.paired_transfer_id IS NULL
      OR ce.raw_transfer_id < ce.paired_transfer_id
    )
) x
";
    $feeParams = [];
    if (($f['dateFrom'] ?? '') !== '') {
        $feeParams[] = $f['dateFrom'] . ' 00:00:00';
    }
    if (($f['dateTo'] ?? '') !== '') {
        $feeParams[] = $f['dateTo'] . ' 23:59:59';
    }
    $stFee = $pdo->prepare($sqlFees);
    $stFee->execute($feeParams);
    $feeRowAgg = $stFee->fetch() ?: [];

    $inTotalRaw = '0';
    $outTotalRaw = '0';
    $paymentSumRaw = '0';
    $topUpSumRaw = '0';
    $interestSumRaw = '0';
    $unknownSumRaw = '0';
    $nTxAllRaw = 0;
    $nPaymentClass = 0;
    $nTopUpClass = 0;
    $nInterestClass = 0;
    $nUnknownClass = 0;
    $gasEthTotal = 0.0;

    $chartDaily = [];
    $chartClassDaily = [];
    $chartInterestDaily = [];
    $chartNodeVolumeDaily = [];
    $chartPaymentAvgDaily = [];
    $chartVaultDaily = [];
    $chartVaultDeltaDaily = [];
    $chartGasDaily = [];
    $chartWeeklyPayment = [];
    $vaultRunningEur = 0.0;
    $gasRunningEth = 0.0;
    $bestPayDay = null;
    $bestPayDayRaw = '0';

    $weeklyBuckets = [];
    foreach ($rows as $r) {
        $day = (string) ($r['day'] ?? '');
        $nTx = (int) ($r['n_tx'] ?? 0);
        $nP = (int) ($r['n_payment'] ?? 0);
        $nT = (int) ($r['n_topup'] ?? 0);
        $nI = (int) ($r['n_interest'] ?? 0);
        $nU = (int) ($r['n_unknown'] ?? 0);
        $sumIn = normalize_amount_raw($r['sum_in_raw'] ?? '0');
        $sumOut = normalize_amount_raw($r['sum_out_raw'] ?? '0');
        $sumPay = normalize_amount_raw($r['sum_payment_raw'] ?? '0');
        $sumTop = normalize_amount_raw($r['sum_topup_raw'] ?? '0');
        $sumInt = normalize_amount_raw($r['sum_interest_raw'] ?? '0');
        $gasEth = (float) ($r['gas_eth'] ?? 0);

        $inTotalRaw = raw_add($inTotalRaw, $sumIn);
        $outTotalRaw = raw_add($outTotalRaw, $sumOut);
        $paymentSumRaw = raw_add($paymentSumRaw, $sumPay);
        $topUpSumRaw = raw_add($topUpSumRaw, $sumTop);
        $interestSumRaw = raw_add($interestSumRaw, $sumInt);
        $nTxAllRaw += $nTx;
        $nPaymentClass += $nP;
        $nTopUpClass += $nT;
        $nInterestClass += $nI;
        $nUnknownClass += $nU;
        $gasEthTotal += $gasEth;

        $payEur = raw_wei_to_float_eur($sumPay);
        $topEur = raw_wei_to_float_eur($sumTop);
        $intEur = raw_wei_to_float_eur($sumInt);
        $volRaw = raw_add($sumIn, $sumOut);
        $volEur = raw_wei_to_float_eur($volRaw);
        $deltaEur = $topEur - $payEur;
        $vaultRunningEur += $deltaEur;
        $gasRunningEth += $gasEth;

        $chartDaily[] = ['day' => $day, 'n' => $nTx];
        $chartClassDaily[] = ['day' => $day, 'payment' => $payEur, 'top_up' => $topEur, 'nPayment' => $nP, 'nTopUp' => $nT];
        $chartInterestDaily[] = ['day' => $day, 'interestEur' => $intEur, 'nInterest' => $nI];
        $chartNodeVolumeDaily[] = ['day' => $day, 'volumeEur' => $volEur, 'nTx' => $nTx];
        $chartPaymentAvgDaily[] = ['day' => $day, 'avgTicketEur' => $nP > 0 ? ($payEur / $nP) : 0.0, 'nPayment' => $nP];
        $chartVaultDeltaDaily[] = ['day' => $day, 'vaultDeltaEur' => $deltaEur];
        $chartVaultDaily[] = ['day' => $day, 'vaultEur' => $vaultRunningEur];
        $chartGasDaily[] = ['day' => $day, 'gasEth' => $gasRunningEth];

        $ts = strtotime($day . ' 00:00:00');
        if ($ts !== false) {
            $weekStart = date('Y-m-d', strtotime('monday this week', $ts));
            if (!isset($weeklyBuckets[$weekStart])) {
                $weeklyBuckets[$weekStart] = ['sumPayRaw' => '0', 'nPay' => 0];
            }
            $weeklyBuckets[$weekStart]['sumPayRaw'] = raw_add($weeklyBuckets[$weekStart]['sumPayRaw'], $sumPay);
            $weeklyBuckets[$weekStart]['nPay'] += $nP;
        }

        $win = ($bestPayDay === null);
        if (!$win && function_exists('bccomp')) {
            $win = bccomp($sumPay, $bestPayDayRaw, 0) > 0;
        } elseif (!$win) {
            $win = (float) $sumPay > (float) $bestPayDayRaw;
        }
        if ($win) {
            $bestPayDay = $day;
            $bestPayDayRaw = $sumPay;
        }
    }

    ksort($weeklyBuckets);
    $totalPaymentTxWeek = 0;
    $totalPaymentPeriodRaw = '0';
    $bestWeekStart = null;
    $bestWeekRaw = '0';
    foreach ($weeklyBuckets as $weekStart => $w) {
        $sr = normalize_amount_raw($w['sumPayRaw']);
        $np = (int) $w['nPay'];
        $totalPaymentPeriodRaw = raw_add($totalPaymentPeriodRaw, $sr);
        $totalPaymentTxWeek += $np;
        if ($bestWeekStart === null) {
            $bestWeekStart = $weekStart;
            $bestWeekRaw = $sr;
        } elseif (function_exists('bccomp') && bccomp($sr, $bestWeekRaw, 0) > 0) {
            $bestWeekStart = $weekStart;
            $bestWeekRaw = $sr;
        } elseif (!function_exists('bccomp') && (float) $sr > (float) $bestWeekRaw) {
            $bestWeekStart = $weekStart;
            $bestWeekRaw = $sr;
        }
        $chartWeeklyPayment[] = [
            'weekStart' => $weekStart,
            'volumeEur' => raw_wei_to_float_eur($sr),
            'nPay' => $np,
            'nDistinctPayers' => 0,
            'avgPerAccountEur' => 0.0,
        ];
    }

    $nWeeksPaymentActive = count($chartWeeklyPayment);
    $avgPayActiveWeekRaw = ($nWeeksPaymentActive > 0 && function_exists('bcdiv'))
        ? bcdiv($totalPaymentPeriodRaw, (string) $nWeeksPaymentActive, 0)
        : '0';
    $daysFilterSpan = 0.0;
    if (($f['dateFrom'] ?? '') !== '' && ($f['dateTo'] ?? '') !== '') {
        $t0 = strtotime($f['dateFrom'] . ' 00:00:00');
        $t1 = strtotime($f['dateTo'] . ' 23:59:59');
        if ($t0 !== false && $t1 !== false && $t1 >= $t0) {
            $daysFilterSpan = (float) (($t1 - $t0) / 86400) + 1.0;
        }
    }
    $weeksInFilterSpan = $daysFilterSpan > 0 ? max(1.0, $daysFilterSpan / 7.0) : (float) max(1, $nWeeksPaymentActive);
    $avgPayCalWeekEur = $weeksInFilterSpan > 0 ? (raw_wei_to_float_eur($totalPaymentPeriodRaw) / $weeksInFilterSpan) : 0.0;
    $avgTicketPaymentRaw = ($totalPaymentTxWeek > 0 && function_exists('bcdiv'))
        ? bcdiv($totalPaymentPeriodRaw, (string) $totalPaymentTxWeek, 0)
        : '0';

    $monthStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00');
    $monthEnd = (new DateTimeImmutable('now'))->format('Y-m-d 23:59:59');
    $sqlPaymentMonthlyByAccount = "
SELECT
  COUNT(DISTINCT LOWER(ce.counterparty)) AS n_distinct_month,
  COALESCE(SUM(CAST(rt.amount_raw AS DECIMAL(65,0))), 0) AS sum_month_raw
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE rt.block_time >= ?
  AND rt.block_time <= ?
  AND NOT (
    (rt.direction = 'in'  AND rt.from_addr = '0x0000000000000000000000000000000000000000')
    OR (rt.direction = 'out' AND rt.to_addr = '0x0000000000000000000000000000000000000000')
  )
  AND ce.event_type = 'payment'
";
    $stPayMonth = $pdo->prepare($sqlPaymentMonthlyByAccount);
    $stPayMonth->execute([$monthStart, $monthEnd]);
    $rowPayMonth = $stPayMonth->fetch() ?: [];
    $nDistinctPayersPaymentMonth = (int) ($rowPayMonth['n_distinct_month'] ?? 0);
    $totalPaymentMonthRaw = normalize_amount_raw($rowPayMonth['sum_month_raw'] ?? '0');
    $avgPayPerDistinctAccountMonthRaw = ($nDistinctPayersPaymentMonth > 0 && function_exists('bcdiv'))
        ? bcdiv($totalPaymentMonthRaw, (string) $nDistinctPayersPaymentMonth, 0)
        : '0';
    $vaultApproxBizRaw = raw_sub($topUpSumRaw, $paymentSumRaw);
    $netChainRaw = raw_sub($outTotalRaw, $inTotalRaw);
    $reconciliationGapRaw = raw_sub($vaultApproxBizRaw, $netChainRaw);
    $topUpInsightRaw = $topUpSumRaw;
    $nTopUpInsight = $nTopUpClass;
    $tPayF = raw_wei_to_float_eur($paymentSumRaw);
    $tTopF = raw_wei_to_float_eur($topUpSumRaw);
    $payVsPayPlusTopFloat = (($tPayF + $tTopF) > 0) ? (100.0 * $tPayF / ($tPayF + $tTopF)) : 0.0;

    $byType = [
        ['event_type' => 'payment', 'n' => $nPaymentClass, 'sum_raw' => $paymentSumRaw],
        ['event_type' => 'top_up', 'n' => $nTopUpClass, 'sum_raw' => $topUpSumRaw],
        ['event_type' => 'interest', 'n' => $nInterestClass, 'sum_raw' => $interestSumRaw],
        ['event_type' => 'unknown', 'n' => $nUnknownClass, 'sum_raw' => $unknownSumRaw],
    ];
    $chartPayload = [
        'daily' => $chartDaily,
        'dailyClass' => $chartClassDaily,
        'interestDaily' => $chartInterestDaily,
        'nodeVolumeDaily' => $chartNodeVolumeDaily,
        'paymentAvgDaily' => $chartPaymentAvgDaily,
        'vaultDaily' => $chartVaultDaily,
        'vaultDeltaDaily' => $chartVaultDeltaDaily,
        'gasDaily' => $chartGasDaily,
        'weeklyPay' => $chartWeeklyPayment,
        'weeklyMeta' => ['avgActiveEur' => raw_wei_to_float_eur($avgPayActiveWeekRaw), 'avgCalWeekEur' => $avgPayCalWeekEur],
    ];
    $chartPayloadJson = json_encode($chartPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS);

    return [
        'cfg' => [],
        'dateFrom' => $f['dateFrom'],
        'dateTo' => $f['dateTo'],
        'counterparty' => '',
        'flux' => ['n_tx' => $nTxAllRaw],
        'byType' => $byType,
        'feeRow' => ['fee_sum_raw' => normalize_amount_raw($feeRowAgg['fee_sum_raw'] ?? '0')],
        'gasRow' => ['gas_eth' => $gasEthTotal],
        'inTotalRaw' => $inTotalRaw,
        'outTotalRaw' => $outTotalRaw,
        'nTxAllRaw' => $nTxAllRaw,
        'fluxCardShowOnchainSplit' => true,
        'inMintRaw' => '0',
        'inUserRaw' => $inTotalRaw,
        'outBurnRaw' => '0',
        'outUserRaw' => $outTotalRaw,
        'paymentSumRaw' => $paymentSumRaw,
        'topUpSumRaw' => $topUpSumRaw,
        'nPaymentClass' => $nPaymentClass,
        'nTopUpClass' => $nTopUpClass,
        'vaultApproxBizRaw' => $vaultApproxBizRaw,
        'netChainRaw' => $netChainRaw,
        'reconciliationGapRaw' => $reconciliationGapRaw,
        'recentTransfersLimit' => MONITOR_RECENT_TRANSFERS_LIMIT,
        'rows' => [],
        'topCounterparties' => [],
        'topWalletsByToken' => [],
        'chartDaily' => $chartDaily,
        'hasWeeklyPaymentChart' => ($chartWeeklyPayment !== []),
        'hasGasDailyChart' => ($chartGasDaily !== []),
        'chartPayloadJson' => $chartPayloadJson,
        'totalPaymentPeriodRaw' => $totalPaymentPeriodRaw,
        'totalPaymentTxWeek' => $totalPaymentTxWeek,
        'bestWeekStart' => $bestWeekStart,
        'bestWeekRaw' => $bestWeekRaw,
        'nWeeksPaymentActive' => $nWeeksPaymentActive,
        'avgPayActiveWeekRaw' => $avgPayActiveWeekRaw,
        'weeksInFilterSpan' => $weeksInFilterSpan,
        'avgPayCalWeekEur' => $avgPayCalWeekEur,
        'avgTicketPaymentRaw' => $avgTicketPaymentRaw,
        'avgPayPerDistinctAccountMonthRaw' => $avgPayPerDistinctAccountMonthRaw,
        'nDistinctPayersPaymentMonth' => $nDistinctPayersPaymentMonth,
        'bestPayDay' => $bestPayDay,
        'bestPayDayRaw' => $bestPayDayRaw,
        'topUpInsightRaw' => $topUpInsightRaw,
        'nTopUpInsight' => $nTopUpInsight,
        'payVsPayPlusTopFloat' => $payVsPayPlusTopFloat,
        'tPayF' => $tPayF,
        'tTopF' => $tTopF,
    ];
}

/**
 * Vue wallets: requêtes limitées + pagination tables.
 *
 * @return array<string, mixed>
 */
function monitor_dashboard_collect_wallets_only(
    PDO $pdo,
    array $f,
    int $activityPage = 1,
    int $walletsPage = 1,
    int $transfersPage = 1,
    int $perPage = 50
): array
{
    $w = $f['w'];
    $params = $f['params'];
    $cpSql = $f['cpSql'];
    $cpJoinLeftSql = $f['cpJoinLeftSql'];
    $excludeZeroPeerSql = $f['excludeZeroPeerSql'];
    $activityPage = max(1, $activityPage);
    $walletsPage = max(1, $walletsPage);
    $transfersPage = max(1, $transfersPage);
    $perPage = max(10, min(200, $perPage));
    $offActivity = ($activityPage - 1) * $perPage;
    $offWallets = ($walletsPage - 1) * $perPage;
    $offTransfers = ($transfersPage - 1) * $perPage;

    $totalTopCounterparties = 0;
    if (($f['counterparty'] ?? '') === '') {
        $sqlCountTopCp = "
SELECT COUNT(*) AS n
FROM (
  SELECT 1
  FROM raw_transfers rt
  {$cpJoinLeftSql}
  WHERE $w
    $cpSql
    $excludeZeroPeerSql
  GROUP BY (CASE WHEN rt.direction = 'in' THEN rt.from_addr ELSE rt.to_addr END)
) x
";
        $stCountTopCp = $pdo->prepare($sqlCountTopCp);
        $stCountTopCp->execute($params);
        $rowCountTopCp = $stCountTopCp->fetch() ?: [];
        $totalTopCounterparties = (int) ($rowCountTopCp['n'] ?? 0);
    }

    $sqlTopCp = "
SELECT
  (CASE WHEN rt.direction = 'in' THEN rt.from_addr ELSE rt.to_addr END) AS cp,
  SUM(CASE WHEN rt.direction = 'in' THEN 1 ELSE 0 END) AS n_in,
  SUM(CASE WHEN rt.direction = 'out' THEN 1 ELSE 0 END) AS n_out,
  SUM(CASE WHEN rt.direction = 'in' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_in_raw,
  SUM(CASE WHEN rt.direction = 'out' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_out_raw,
  MIN(rt.block_time) AS first_seen,
  MAX(rt.block_time) AS last_seen,
  COUNT(*) AS n_total
FROM raw_transfers rt
{$cpJoinLeftSql}
WHERE $w
  $cpSql
  $excludeZeroPeerSql
GROUP BY (CASE WHEN rt.direction = 'in' THEN rt.from_addr ELSE rt.to_addr END)
ORDER BY n_total DESC
LIMIT {$perPage} OFFSET {$offActivity}
";
    $stCp = $pdo->prepare($sqlTopCp);
    $stCp->execute($params);
    $topCounterparties = $stCp->fetchAll();

    $sqlCountTopWallets = "
SELECT COUNT(*) AS n
FROM (
  SELECT 1
  FROM raw_transfers rt
  JOIN classified_events ce ON ce.raw_transfer_id = rt.id
  WHERE $w
    $cpSql
    $excludeZeroPeerSql
    AND ce.event_type IN ('payment', 'top_up')
  GROUP BY LOWER(ce.counterparty)
) x
";
    $stCountTopWallets = $pdo->prepare($sqlCountTopWallets);
    $stCountTopWallets->execute($params);
    $rowCountTopWallets = $stCountTopWallets->fetch() ?: [];
    $totalTopWallets = (int) ($rowCountTopWallets['n'] ?? 0);

    $sqlTopWallets = "
SELECT
  LOWER(ce.counterparty) AS cp,
  SUM(CASE WHEN ce.event_type = 'payment' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_payment_raw,
  SUM(CASE WHEN ce.event_type = 'top_up' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_topup_raw,
  (
    SUM(CASE WHEN ce.event_type = 'top_up' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END)
    -
    SUM(CASE WHEN ce.event_type = 'payment' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END)
  ) AS wallet_tokens_raw,
  COUNT(CASE WHEN ce.event_type = 'payment' THEN 1 END) AS n_payment,
  COUNT(CASE WHEN ce.event_type = 'top_up' THEN 1 END) AS n_topup
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE $w
  $cpSql
  $excludeZeroPeerSql
  AND ce.event_type IN ('payment', 'top_up')
GROUP BY LOWER(ce.counterparty)
ORDER BY wallet_tokens_raw DESC
LIMIT {$perPage} OFFSET {$offWallets}
";
    $stTopWallets = $pdo->prepare($sqlTopWallets);
    $stTopWallets->execute($params);
    $topWalletsByToken = $stTopWallets->fetchAll();

    $sqlCountTransfers = "
SELECT COUNT(*) AS n
FROM raw_transfers rt
LEFT JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE $w
  $cpSql
  $excludeZeroPeerSql
";
    $stCountTransfers = $pdo->prepare($sqlCountTransfers);
    $stCountTransfers->execute($params);
    $rowCountTransfers = $stCountTransfers->fetch() ?: [];
    $totalTransfers = (int) ($rowCountTransfers['n'] ?? 0);

    $lim = $perPage;
    $sqlList = "
SELECT
  rt.id,
  rt.tx_hash,
  rt.block_time,
  rt.direction,
  rt.from_addr,
  rt.to_addr,
  rt.amount_raw,
  ce.event_type,
  ce.counterparty,
  ce.fee_token_raw,
  ce.rule_version,
  ce.confidence,
  tg.cost_eth
FROM raw_transfers rt
LEFT JOIN classified_events ce ON ce.raw_transfer_id = rt.id
LEFT JOIN tx_gas tg ON tg.tx_hash = rt.tx_hash
WHERE $w
  $cpSql
  $excludeZeroPeerSql
ORDER BY rt.block_time DESC, rt.id DESC
LIMIT {$lim} OFFSET {$offTransfers}
";
    $stList = $pdo->prepare($sqlList);
    $stList->execute($params);
    $rows = $stList->fetchAll();

    return [
        'topCounterparties' => $topCounterparties,
        'topWalletsByToken' => $topWalletsByToken,
        'rows' => $rows,
        'recentTransfersLimit' => $lim,
        'paging' => [
            'activity' => ['page' => $activityPage, 'perPage' => $perPage, 'total' => $totalTopCounterparties],
            'wallets' => ['page' => $walletsPage, 'perPage' => $perPage, 'total' => $totalTopWallets],
            'transfers' => ['page' => $transfersPage, 'perPage' => $perPage, 'total' => $totalTransfers],
        ],
    ];
}

/**
 * Vue flows: séries de flux (sans tableaux).
 *
 * @return array<string, mixed>
 */
function monitor_dashboard_collect_flows(PDO $pdo, array $f): array
{
    if (($f['counterparty'] ?? '') === '' && monitor_daily_metrics_available($pdo)) {
        try {
            $all = monitor_dashboard_collect_from_daily_metrics($pdo, $f);
            return [
                'chartPayloadJson' => $all['chartPayloadJson'],
                'hasCharts' => (($all['chartDaily'] ?? []) !== []),
                'inTotalRaw' => $all['inTotalRaw'] ?? '0',
                'outTotalRaw' => $all['outTotalRaw'] ?? '0',
                'paymentSumRaw' => $all['paymentSumRaw'] ?? '0',
                'topUpSumRaw' => $all['topUpSumRaw'] ?? '0',
                'vaultApproxBizRaw' => $all['vaultApproxBizRaw'] ?? '0',
            ];
        } catch (Throwable $e) {
            // fallback SQL classique
        }
    }

    $shell = monitor_dashboard_collect_shell($pdo, $f, []);
    $heavy = monitor_dashboard_collect_heavy($pdo, $f, [], $shell['byType'], false);

    return [
        'chartPayloadJson' => $heavy['chartPayloadJson'],
        'hasCharts' => (($heavy['chartDaily'] ?? []) !== []),
        'inTotalRaw' => $shell['inTotalRaw'] ?? '0',
        'outTotalRaw' => $shell['outTotalRaw'] ?? '0',
        'paymentSumRaw' => $shell['paymentSumRaw'] ?? '0',
        'topUpSumRaw' => $shell['topUpSumRaw'] ?? '0',
        'vaultApproxBizRaw' => $shell['vaultApproxBizRaw'] ?? '0',
    ];
}

/**
 * Vue costs: coûts/frais.
 *
 * @return array<string, mixed>
 */
function monitor_dashboard_collect_costs(PDO $pdo, array $f): array
{
    if (($f['counterparty'] ?? '') === '' && monitor_daily_metrics_available($pdo)) {
        try {
            $all = monitor_dashboard_collect_from_daily_metrics($pdo, $f);
            $nTx = (int) (($all['flux']['n_tx'] ?? 0));
            $gasEth = (float) (($all['gasRow']['gas_eth'] ?? 0));
            $avgGasEthByTx = $nTx > 0 ? ($gasEth / $nTx) : 0.0;
            return [
                'feeRow' => $all['feeRow'] ?? ['fee_sum_raw' => '0'],
                'gasRow' => $all['gasRow'] ?? ['gas_eth' => 0],
                'nTx' => $nTx,
                'avgGasEthByTx' => $avgGasEthByTx,
                'chartPayloadJson' => $all['chartPayloadJson'],
                'hasGasChart' => (($all['hasGasDailyChart'] ?? false) === true),
            ];
        } catch (Throwable $e) {
            // fallback SQL classique
        }
    }

    $shell = monitor_dashboard_collect_shell($pdo, $f, []);
    $heavy = monitor_dashboard_collect_heavy($pdo, $f, [], $shell['byType'], false);

    $nTx = (int) (($shell['flux']['n_tx'] ?? 0));
    $gasEth = (float) (($shell['gasRow']['gas_eth'] ?? 0));
    $avgGasEthByTx = $nTx > 0 ? ($gasEth / $nTx) : 0.0;

    return [
        'feeRow' => $shell['feeRow'] ?? ['fee_sum_raw' => '0'],
        'gasRow' => $shell['gasRow'] ?? ['gas_eth' => 0],
        'nTx' => $nTx,
        'avgGasEthByTx' => $avgGasEthByTx,
        'chartPayloadJson' => $heavy['chartPayloadJson'],
        'hasGasChart' => (($heavy['hasGasDailyChart'] ?? false) === true),
    ];
}

/**
 * Vue quality: qualité de la classification.
 *
 * @return array<string, mixed>
 */
function monitor_dashboard_collect_quality(PDO $pdo, array $f): array
{
    $w = $f['w'];
    $params = $f['params'];
    $cpSql = $f['cpSql'];
    $excludeZeroPeerSql = $f['excludeZeroPeerSql'];

    $sqlByType = "
SELECT
  ce.event_type,
  COUNT(*) AS n,
  SUM(CASE WHEN ce.paired_transfer_id IS NOT NULL THEN 1 ELSE 0 END) AS n_paired
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE $w
  $cpSql
  $excludeZeroPeerSql
GROUP BY ce.event_type
ORDER BY n DESC
";
    $stByType = $pdo->prepare($sqlByType);
    $stByType->execute($params);
    $byType = $stByType->fetchAll() ?: [];

    $sqlConfidence = "
SELECT
  CASE
    WHEN ce.confidence >= 90 THEN '90-100'
    WHEN ce.confidence >= 75 THEN '75-89'
    WHEN ce.confidence >= 60 THEN '60-74'
    WHEN ce.confidence >= 40 THEN '40-59'
    ELSE '0-39'
  END AS bucket,
  COUNT(*) AS n
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE $w
  $cpSql
  $excludeZeroPeerSql
GROUP BY bucket
ORDER BY bucket DESC
";
    $stConf = $pdo->prepare($sqlConfidence);
    $stConf->execute($params);
    $confidenceBuckets = $stConf->fetchAll() ?: [];

    $sqlStats = "
SELECT
  COUNT(*) AS total_classified,
  SUM(CASE WHEN ce.event_type = 'interest' THEN 1 ELSE 0 END) AS n_interest,
  SUM(CASE WHEN ce.event_type = 'unknown' THEN 1 ELSE 0 END) AS n_unknown,
  SUM(CASE WHEN ce.event_type = 'interest' AND ce.paired_transfer_id IS NOT NULL THEN 1 ELSE 0 END) AS n_interest_paired
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE $w
  $cpSql
  $excludeZeroPeerSql
";
    $stStats = $pdo->prepare($sqlStats);
    $stStats->execute($params);
    $stats = $stStats->fetch() ?: [];

    return [
        'qualityByType' => $byType,
        'qualityConfidenceBuckets' => $confidenceBuckets,
        'qualityStats' => $stats,
    ];
}

/**
 * Concentration des holders (approx v1 via top_up - payment par wallet).
 *
 * @return array<string, mixed>
 */
function monitor_dashboard_collect_concentration(PDO $pdo, array $f): array
{
    // Vue concentration: calculée en "lifetime" (pas bornée par les filtres de dates).
    $w = '1=1';
    $params = [];
    $cpSql = '';
    $excludeZeroPeerSql = $f['excludeZeroPeerSql'];
    $counterparty = (string) ($f['counterparty'] ?? '');
    if ($counterparty !== '') {
        $cpSql = ' AND ce.counterparty = ? ';
        $params[] = $counterparty;
    }
    $teamCutoffDate = '2026-03-12 00:00:00';

    $sql = "
SELECT
  LOWER(ce.counterparty) AS wallet,
  MIN(rt.block_time) AS first_seen,
  (
    SUM(CASE WHEN ce.event_type = 'top_up' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END)
    -
    SUM(CASE WHEN ce.event_type = 'payment' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END)
  ) AS holding_raw
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE $w
  $cpSql
  $excludeZeroPeerSql
  AND ce.event_type IN ('payment', 'top_up')
GROUP BY LOWER(ce.counterparty)
HAVING holding_raw > 0
ORDER BY holding_raw DESC
";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll() ?: [];

    $holders = [];
    $totalSupplyRaw = '0';
    foreach ($rows as $r) {
        $h = normalize_amount_raw($r['holding_raw'] ?? '0');
        if ($h === '0') {
            continue;
        }
        $firstSeen = (string) ($r['first_seen'] ?? '');
        $isTeamWallet = ($firstSeen !== '' && $firstSeen < $teamCutoffDate);
        $holders[] = [
            'wallet' => (string) ($r['wallet'] ?? ''),
            'holding_raw' => $h,
            'first_seen' => $firstSeen,
            'is_team_wallet' => $isTeamWallet,
        ];
        $totalSupplyRaw = raw_add($totalSupplyRaw, $h);
    }
    $nHolders = count($holders);

    $sumTopN = static function (int $n) use ($holders): string {
        $s = '0';
        $max = min($n, count($holders));
        for ($i = 0; $i < $max; $i++) {
            $s = raw_add($s, $holders[$i]['holding_raw']);
        }
        return $s;
    };
    $top5Raw = $sumTopN(5);
    $top10Raw = $sumTopN(10);
    $top100Raw = $sumTopN(100);

    $pct = static function (string $partRaw, string $totalRaw): float {
        $p = raw_wei_to_float_eur($partRaw);
        $t = raw_wei_to_float_eur($totalRaw);
        return $t > 0 ? (100.0 * $p / $t) : 0.0;
    };

    $cohortRanges = [
        ['label' => 'Top 1-5', 'a' => 1, 'b' => 5],
        ['label' => 'Top 6-10', 'a' => 6, 'b' => 10],
        ['label' => 'Top 11-25', 'a' => 11, 'b' => 25],
        ['label' => 'Top 26-50', 'a' => 26, 'b' => 50],
        ['label' => 'Top 51-100', 'a' => 51, 'b' => 100],
    ];
    $cohorts = [];
    foreach ($cohortRanges as $cr) {
        $s = '0';
        for ($i = $cr['a'] - 1; $i < min($cr['b'], $nHolders); $i++) {
            $s = raw_add($s, $holders[$i]['holding_raw']);
        }
        $cohorts[] = ['label' => $cr['label'], 'holding_raw' => $s, 'pct_supply' => $pct($s, $totalSupplyRaw)];
    }
    $outside100Raw = raw_sub($totalSupplyRaw, $top100Raw);
    $cohorts[] = ['label' => 'Outside 100', 'holding_raw' => $outside100Raw, 'pct_supply' => $pct($outside100Raw, $totalSupplyRaw)];

    $tiersDef = [
        ['label' => '🐋 Whale', 'min' => eur_to_raw_wei('100000')],
        ['label' => '🦈 Shark', 'min' => eur_to_raw_wei('10000')],
        ['label' => '🐬 Dolphin', 'min' => eur_to_raw_wei('1000')],
        ['label' => '🐟 Fish', 'min' => eur_to_raw_wei('100')],
        ['label' => '🦀 Crab', 'min' => eur_to_raw_wei('10')],
        ['label' => '🦐 Shrimp', 'min' => eur_to_raw_wei('0')],
    ];
    $tiers = [];
    foreach ($tiersDef as $idx => $tier) {
        $maxRaw = $idx === 0 ? null : $tiersDef[$idx - 1]['min'];
        $count = 0;
        $sumRaw = '0';
        foreach ($holders as $h) {
            $okMin = function_exists('bccomp') ? (bccomp($h['holding_raw'], $tier['min'], 0) >= 0) : ((float) $h['holding_raw'] >= (float) $tier['min']);
            $okMax = true;
            if ($maxRaw !== null) {
                $okMax = function_exists('bccomp') ? (bccomp($h['holding_raw'], $maxRaw, 0) < 0) : ((float) $h['holding_raw'] < (float) $maxRaw);
            }
            if ($okMin && $okMax) {
                $count++;
                $sumRaw = raw_add($sumRaw, $h['holding_raw']);
            }
        }
        $tiers[] = [
            'label' => $tier['label'],
            'holder_count' => $count,
            'pct_holders' => $nHolders > 0 ? (100.0 * $count / $nHolders) : 0.0,
            'pct_supply' => $pct($sumRaw, $totalSupplyRaw),
        ];
    }

    $thresholds = ['10', '50', '100', '250', '500', '1000', '10000', '100000', '1000000'];
    $depth = [];
    foreach ($thresholds as $th) {
        $thRaw = eur_to_raw_wei($th);
        $count = 0;
        foreach ($holders as $h) {
            $ok = function_exists('bccomp') ? (bccomp($h['holding_raw'], $thRaw, 0) > 0) : ((float) $h['holding_raw'] > (float) $thRaw);
            if ($ok) $count++;
        }
        $depth[] = ['threshold' => $th, 'holders' => $count, 'pct_total' => $nHolders > 0 ? (100.0 * $count / $nHolders) : 0.0];
    }

    // Gini (approx float) sur holdings > 0.
    $vals = array_map(static fn (array $h): float => raw_wei_to_float_eur($h['holding_raw']), $holders);
    sort($vals);
    $n = count($vals);
    $gini = 0.0;
    if ($n > 1) {
        $sum = array_sum($vals);
        if ($sum > 0) {
            $weighted = 0.0;
            for ($i = 0; $i < $n; $i++) {
                $weighted += ($i + 1) * $vals[$i];
            }
            $gini = (2.0 * $weighted) / ($n * $sum) - (($n + 1.0) / $n);
        }
    }

    $teamWallets = [];
    $teamHoldingRaw = '0';
    foreach ($holders as $h) {
        if (!empty($h['is_team_wallet'])) {
            $teamWallets[] = (string) $h['wallet'];
            $teamHoldingRaw = raw_add($teamHoldingRaw, (string) $h['holding_raw']);
        }
    }
    $teamWalletCount = count($teamWallets);

    $teamInterestRaw = '0';
    if ($teamWalletCount > 0) {
        $inPlaceholders = implode(',', array_fill(0, $teamWalletCount, '?'));
        $sqlTeamInterest = "
SELECT COALESCE(SUM(CAST(rt.amount_raw AS DECIMAL(65,0))), 0) AS sum_interest_raw
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE $w
  $cpSql
  $excludeZeroPeerSql
  AND ce.event_type = 'interest'
  AND LOWER(ce.counterparty) IN ($inPlaceholders)
";
        $stTeamInterest = $pdo->prepare($sqlTeamInterest);
        $stTeamInterest->execute(array_merge($params, $teamWallets));
        $rTeamInterest = $stTeamInterest->fetch() ?: [];
        $teamInterestRaw = normalize_amount_raw($rTeamInterest['sum_interest_raw'] ?? '0');
    }

    $sqlInterestTotal = "
SELECT COALESCE(SUM(CAST(rt.amount_raw AS DECIMAL(65,0))), 0) AS sum_interest_raw
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE $w
  $cpSql
  $excludeZeroPeerSql
  AND ce.event_type = 'interest'
";
    $stInterestTotal = $pdo->prepare($sqlInterestTotal);
    $stInterestTotal->execute($params);
    $rInterestTotal = $stInterestTotal->fetch() ?: [];
    $interestTotalRaw = normalize_amount_raw($rInterestTotal['sum_interest_raw'] ?? '0');
    $interestNonTeamRaw = raw_sub($interestTotalRaw, $teamInterestRaw);

    return [
        'concTotalSupplyRaw' => $totalSupplyRaw,
        'concTop5Raw' => $top5Raw,
        'concTop10Raw' => $top10Raw,
        'concTop100Raw' => $top100Raw,
        'concTop5Pct' => $pct($top5Raw, $totalSupplyRaw),
        'concTop10Pct' => $pct($top10Raw, $totalSupplyRaw),
        'concTop100Pct' => $pct($top100Raw, $totalSupplyRaw),
        'concGini' => $gini,
        'concHolderCount' => $nHolders,
        'concCohorts' => $cohorts,
        'concTiers' => $tiers,
        'concDepth' => $depth,
        'concTeamCutoffDate' => $teamCutoffDate,
        'concTeamWalletCount' => $teamWalletCount,
        'concTeamHoldingRaw' => $teamHoldingRaw,
        'concTeamHoldingPct' => $pct($teamHoldingRaw, $totalSupplyRaw),
        'concTeamInterestRaw' => $teamInterestRaw,
        'concInterestTotalRaw' => $interestTotalRaw,
        'concInterestNonTeamRaw' => $interestNonTeamRaw,
        'concTeamInterestPct' => $pct($teamInterestRaw, $interestTotalRaw),
    ];
}

/**
 * Graphe wallet 3D (noeud central + wallets connectés) sur la plage filtrée.
 *
 * @return array<string, mixed>
 */
function monitor_dashboard_collect_wallet_graph(PDO $pdo, array $f, string $nodeAddress, int $limit = 0): array
{
    $w = $f['w'];
    $params = $f['params'];
    $cpSql = $f['cpSql'];
    $excludeZeroPeerSql = $f['excludeZeroPeerSql'];
    $cpJoinLeftSql = $f['cpJoinLeftSql'];
    $limit = max(0, min(50000, $limit));

    $sql = "
SELECT
  LOWER(CASE WHEN rt.direction = 'in' THEN rt.from_addr ELSE rt.to_addr END) AS wallet,
  SUM(CASE WHEN rt.direction = 'in' THEN 1 ELSE 0 END) AS n_in,
  SUM(CASE WHEN rt.direction = 'out' THEN 1 ELSE 0 END) AS n_out,
  COUNT(*) AS n_total,
  SUM(CASE WHEN rt.direction = 'in' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_in_raw,
  SUM(CASE WHEN rt.direction = 'out' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_out_raw
FROM raw_transfers rt
{$cpJoinLeftSql}
WHERE $w
  $cpSql
  $excludeZeroPeerSql
GROUP BY LOWER(CASE WHEN rt.direction = 'in' THEN rt.from_addr ELSE rt.to_addr END)
ORDER BY n_total DESC
";
    if ($limit > 0) {
        $sql .= " LIMIT {$limit}";
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll() ?: [];

    $nodes = [[
        'id' => strtolower($nodeAddress),
        'label' => 'NODE',
        'type' => 'node',
        'val' => 18,
        'nTotal' => 0,
        'sumInRaw' => '0',
        'sumOutRaw' => '0',
    ]];
    $links = [];
    $totalTx = 0;
    foreach ($rows as $r) {
        $wallet = strtolower((string) ($r['wallet'] ?? ''));
        if ($wallet === '' || !preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
            continue;
        }
        $nTotal = (int) ($r['n_total'] ?? 0);
        $sumInRaw = normalize_amount_raw($r['sum_in_raw'] ?? '0');
        $sumOutRaw = normalize_amount_raw($r['sum_out_raw'] ?? '0');
        $walletApproxRaw = raw_sub($sumOutRaw, $sumInRaw);
        $walletApproxFloat = max(0.0, raw_wei_to_float_eur(ltrim($walletApproxRaw, '-')));
        $isTeamCandidate = (
            $nTotal >= 6
            && raw_wei_to_float_eur($sumOutRaw) > (raw_wei_to_float_eur($sumInRaw) * 1.2)
        );
        $totalTx += $nTotal;

        $nodes[] = [
            'id' => $wallet,
            'label' => substr($wallet, 0, 10) . '…',
            'type' => 'wallet',
            'val' => max(2, min(30, 2 + (int) floor(log(max(1.0, $walletApproxFloat + 1.0), 1.8)))),
            'nTotal' => $nTotal,
            'sumInRaw' => $sumInRaw,
            'sumOutRaw' => $sumOutRaw,
            'walletApproxRaw' => $walletApproxRaw,
            'teamCandidate' => $isTeamCandidate,
        ];

        $links[] = [
            'source' => strtolower($nodeAddress),
            'target' => $wallet,
            'n' => $nTotal,
            'value' => max(1, min(20, (int) ceil(log(max(1, $nTotal), 1.8)))),
            'sumInRaw' => $sumInRaw,
            'sumOutRaw' => $sumOutRaw,
        ];
    }

    return [
        'graphData' => [
            'nodes' => $nodes,
            'links' => $links,
        ],
        'graphMeta' => [
            'nodeAddress' => strtolower($nodeAddress),
            'walletCount' => count($nodes) - 1,
            'edgeCount' => count($links),
            'totalTx' => $totalTx,
        ],
    ];
}

/**
 * Charge toutes les données du dashboard (requêtes + séries pour graphiques).
 *
 * @return array<string, mixed>
 */
function monitor_dashboard_collect(PDO $pdo, string $dateFrom, string $dateTo, string $counterparty, array $cfg): array
{
    $f = monitor_dashboard_filter_parts($dateFrom, $dateTo, $counterparty);
    $shell = monitor_dashboard_collect_shell($pdo, $f, $cfg);
    $heavy = monitor_dashboard_collect_heavy($pdo, $f, $cfg, $shell['byType']);

    return array_merge($shell, $heavy);
}
