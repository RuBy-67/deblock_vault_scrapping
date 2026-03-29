<?php

declare(strict_types=1);

require_once __DIR__ . '/format.php';

/** Nombre de lignes pour le tableau « Derniers transferts ». */
const MONITOR_RECENT_TRANSFERS_LIMIT = 100;

/**
 * Charge toutes les données du dashboard (requêtes + séries pour graphiques).
 *
 * @return array<string, mixed>
 */
function monitor_dashboard_collect(PDO $pdo, string $dateFrom, string $dateTo, string $counterparty, array $cfg): array
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
    if ($counterparty !== '') {
        $cpSql = ' AND ce.counterparty = ? ';
        $params[] = $counterparty;
    }

    $zeroAddr = '0x0000000000000000000000000000000000000000';
    $excludeZeroPeerSql = " AND NOT (
  (rt.direction = 'in'  AND LOWER(rt.from_addr) = '$zeroAddr')
  OR (rt.direction = 'out' AND LOWER(rt.to_addr) = '$zeroAddr')
)";

    $w = implode(' AND ', $whereRt);

    $sqlFlux = "
SELECT
  SUM(CASE WHEN rt.direction = 'in'  THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_in_raw,
  SUM(CASE WHEN rt.direction = 'out' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_out_raw,
  COUNT(*) AS n_tx
FROM raw_transfers rt
LEFT JOIN classified_events ce ON ce.raw_transfer_id = rt.id
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
  LEFT JOIN classified_events ce ON ce.raw_transfer_id = rt.id
  WHERE rt.tx_hash = tg.tx_hash
    AND $w
    $cpSql
    $excludeZeroPeerSql
)
";
    $st4 = $pdo->prepare($sqlGas);
    $st4->execute($params);
    $gasRow = $st4->fetch() ?: [];

    $sqlDaily = "
SELECT
  DATE(rt.block_time) AS day,
  COUNT(*) AS n_tx
FROM raw_transfers rt
LEFT JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE $w
  $cpSql
GROUP BY DATE(rt.block_time)
ORDER BY day ASC
";
    $stDaily = $pdo->prepare($sqlDaily);
    $stDaily->execute($params);
    $dailyRows = $stDaily->fetchAll();

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
LEFT JOIN classified_events ce ON ce.raw_transfer_id = rt.id
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

    $sqlPaymentDistinctPayers = "
SELECT COUNT(DISTINCT LOWER(ce.counterparty)) AS n_distinct
FROM raw_transfers rt
JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE $w
  $cpSql
  $excludeZeroPeerSql
  AND ce.event_type = 'payment'
";
    $stPayDistinct = $pdo->prepare($sqlPaymentDistinctPayers);
    $stPayDistinct->execute($params);
    $rowPayDistinct = $stPayDistinct->fetch() ?: [];
    $nDistinctPayersPayment = (int) ($rowPayDistinct['n_distinct'] ?? 0);

    $lim = MONITOR_RECENT_TRANSFERS_LIMIT;
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
  LOWER(CASE WHEN rt.direction = 'in' THEN rt.from_addr ELSE rt.to_addr END) AS cp,
  SUM(CASE WHEN rt.direction = 'in' THEN 1 ELSE 0 END) AS n_in,
  SUM(CASE WHEN rt.direction = 'out' THEN 1 ELSE 0 END) AS n_out,
  SUM(CASE WHEN rt.direction = 'in' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_in_raw,
  SUM(CASE WHEN rt.direction = 'out' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_out_raw,
  MIN(rt.block_time) AS first_seen,
  MAX(rt.block_time) AS last_seen,
  COUNT(*) AS n_total
FROM raw_transfers rt
LEFT JOIN classified_events ce ON ce.raw_transfer_id = rt.id
WHERE $w
  $excludeZeroPeerSql
GROUP BY LOWER(CASE WHEN rt.direction = 'in' THEN rt.from_addr ELSE rt.to_addr END)
ORDER BY n_total DESC
LIMIT 50
";
        $stCp = $pdo->prepare($sqlTopCp);
        $stCp->execute($params);
        $topCounterparties = $stCp->fetchAll();
    } else {
        $topCounterparties = [];
    }

    $paramsMint = [];
    if ($dateFrom !== '') {
        $paramsMint[] = $dateFrom . ' 00:00:00';
    }
    if ($dateTo !== '') {
        $paramsMint[] = $dateTo . ' 23:59:59';
    }
    $sqlMintBurn = "
SELECT
  COUNT(*) AS n_tx,
  SUM(CASE WHEN rt.direction = 'in' THEN 1 ELSE 0 END) AS n_mint_in,
  SUM(CASE WHEN rt.direction = 'out' THEN 1 ELSE 0 END) AS n_burn_out,
  SUM(CASE WHEN rt.direction = 'in' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_in_raw,
  SUM(CASE WHEN rt.direction = 'out' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_out_raw,
  MIN(rt.block_time) AS first_seen,
  MAX(rt.block_time) AS last_seen
FROM raw_transfers rt
WHERE $w
  AND (
    (rt.direction = 'in' AND LOWER(rt.from_addr) = '$zeroAddr')
    OR (rt.direction = 'out' AND LOWER(rt.to_addr) = '$zeroAddr')
  )
";
    $stMint = $pdo->prepare($sqlMintBurn);
    $stMint->execute($paramsMint);
    $mintBurn = $stMint->fetch() ?: [];

    // --- Séries graphiques ---
    $chartDaily = [];
    foreach ($dailyRows as $dr) {
        $chartDaily[] = [
            'day' => (string) $dr['day'],
            'n' => (int) ($dr['n_tx'] ?? 0),
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
    $avgPayPerDistinctAccountRaw = ($nDistinctPayersPayment > 0 && function_exists('bcdiv'))
        ? bcdiv($totalPaymentPeriodRaw, (string) $nDistinctPayersPayment, 0)
        : '0';
    $avgPayPerDistinctAccountEurFloat = raw_wei_to_float_eur($avgPayPerDistinctAccountRaw);
    $sumWeeklyAvgPerAccountFloat = 0.0;
    foreach ($chartWeeklyPayment as $wpt) {
        $sumWeeklyAvgPerAccountFloat += (float) ($wpt['avgPerAccountEur'] ?? 0);
    }
    $avgOfWeeklyAvgPerAccountEur = $nWeeksPaymentActive > 0
        ? $sumWeeklyAvgPerAccountFloat / $nWeeksPaymentActive
        : 0.0;
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

    $inUserRaw = normalize_amount_raw($flux['sum_in_raw'] ?? '0');
    $outUserRaw = normalize_amount_raw($flux['sum_out_raw'] ?? '0');
    $inMintRaw = normalize_amount_raw($mintBurn['sum_in_raw'] ?? '0');
    $outBurnRaw = normalize_amount_raw($mintBurn['sum_out_raw'] ?? '0');
    $fluxCardShowOnchainSplit = ($counterparty === '');
    if ($fluxCardShowOnchainSplit) {
        $inTotalRaw = raw_add($inUserRaw, $inMintRaw);
        $outTotalRaw = raw_add($outUserRaw, $outBurnRaw);
        $nTxAllRaw = (int) ($flux['n_tx'] ?? 0) + (int) ($mintBurn['n_tx'] ?? 0);
    } else {
        $inTotalRaw = $inUserRaw;
        $outTotalRaw = $outUserRaw;
        $nTxAllRaw = (int) ($flux['n_tx'] ?? 0);
    }
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

    $chartPayload = [
        'daily' => $chartDaily,
        'dailyClass' => $chartClassDaily,
        'interestDaily' => $chartInterestDaily,
        'nodeVolumeDaily' => $chartNodeVolumeDaily,
        'paymentAvgDaily' => $chartPaymentAvgDaily,
        'weeklyPay' => $chartWeeklyPayment,
        'weeklyMeta' => [
            'avgActiveEur' => $avgPayActiveWeekEur,
            'avgCalWeekEur' => $avgPayCalWeekEur,
            'avgPerDistinctAccountPeriodEur' => $avgPayPerDistinctAccountEurFloat,
            'avgOfWeeklyAvgPerAccountEur' => $avgOfWeeklyAvgPerAccountEur,
        ],
    ];

    $chartPayloadJson = json_encode(
        $chartPayload,
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
    );

    return [
        'cfg' => $cfg,
        'dateFrom' => $dateFrom,
        'dateTo' => $dateTo,
        'counterparty' => $counterparty,
        'flux' => $flux,
        'byType' => $byType,
        'feeRow' => $feeRow,
        'gasRow' => $gasRow,
        'mintBurn' => $mintBurn,
        'rows' => $rows,
        'topCounterparties' => $topCounterparties,
        'chartDaily' => $chartDaily,
        'hasWeeklyPaymentChart' => $hasWeeklyPaymentChart,
        'chartPayloadJson' => $chartPayloadJson,
        'nDistinctPayersPayment' => $nDistinctPayersPayment,
        'totalPaymentPeriodRaw' => $totalPaymentPeriodRaw,
        'totalPaymentTxWeek' => $totalPaymentTxWeek,
        'bestWeekStart' => $bestWeekStart,
        'bestWeekRaw' => $bestWeekRaw,
        'nWeeksPaymentActive' => $nWeeksPaymentActive,
        'avgPayActiveWeekRaw' => $avgPayActiveWeekRaw,
        'weeksInFilterSpan' => $weeksInFilterSpan,
        'avgPayCalWeekEur' => $avgPayCalWeekEur,
        'avgTicketPaymentRaw' => $avgTicketPaymentRaw,
        'avgPayPerDistinctAccountRaw' => $avgPayPerDistinctAccountRaw,
        'avgOfWeeklyAvgPerAccountEur' => $avgOfWeeklyAvgPerAccountEur,
        'bestPayDay' => $bestPayDay,
        'bestPayDayRaw' => $bestPayDayRaw,
        'topUpInsightRaw' => $topUpInsightRaw,
        'nTopUpInsight' => $nTopUpInsight,
        'payVsPayPlusTopFloat' => $payVsPayPlusTopFloat,
        'tPayF' => $tPayF,
        'tTopF' => $tTopF,
        'inTotalRaw' => $inTotalRaw,
        'outTotalRaw' => $outTotalRaw,
        'nTxAllRaw' => $nTxAllRaw,
        'fluxCardShowOnchainSplit' => $fluxCardShowOnchainSplit,
        'inMintRaw' => $inMintRaw,
        'inUserRaw' => $inUserRaw,
        'outBurnRaw' => $outBurnRaw,
        'outUserRaw' => $outUserRaw,
        'paymentSumRaw' => $paymentSumRaw,
        'topUpSumRaw' => $topUpSumRaw,
        'nPaymentClass' => $nPaymentClass,
        'nTopUpClass' => $nTopUpClass,
        'vaultApproxBizRaw' => $vaultApproxBizRaw,
        'recentTransfersLimit' => MONITOR_RECENT_TRANSFERS_LIMIT,
    ];
}
