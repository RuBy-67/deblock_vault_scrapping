<?php

declare(strict_types=1);

/** @var string $concTotalSupplyRaw */
/** @var string $concTop5Raw */
/** @var string $concTop10Raw */
/** @var string $concTop100Raw */
/** @var float $concTop5Pct */
/** @var float $concTop10Pct */
/** @var float $concTop100Pct */
/** @var float $concGini */
/** @var int $concHolderCount */
/** @var list<array{label:string,holding_raw:string,pct_supply:float}> $concCohorts */
/** @var list<array{label:string,holder_count:int,pct_holders:float,pct_supply:float}> $concTiers */
/** @var list<array{threshold:string,holders:int,pct_total:float}> $concDepth */
/** @var string $concTeamCutoffDate */
/** @var int $concTeamWalletCount */
/** @var string $concTeamHoldingRaw */
/** @var float $concTeamHoldingPct */
/** @var string $concTeamInterestRaw */
/** @var string $concInterestTotalRaw */
/** @var string $concInterestNonTeamRaw */
/** @var float $concTeamInterestPct */
$fmtRaw = static function (string $raw): string {
    if (function_exists('fmt_raw_eur')) {
        return fmt_raw_eur($raw);
    }
    return number_format(((float) $raw) / 1e18, 2, ',', ' ') . ' €';
};
?>
<section class="cards cards--top">
  <div class="card">
    <h3>Top 100 Concentration</h3>
    <p class="metric-one-line"><strong><?= htmlspecialchars(number_format((float) $concTop100Pct, 2, ',', ' ')) ?>%</strong></p>
    <p class="muted">Top 5: <?= htmlspecialchars(number_format((float) $concTop5Pct, 2, ',', ' ')) ?>% | Top 10: <?= htmlspecialchars(number_format((float) $concTop10Pct, 2, ',', ' ')) ?>%</p>
  </div>
  <div class="card">
    <h3>Gini Distribution Score</h3>
    <p class="metric-one-line"><strong><?= htmlspecialchars(number_format((float) $concGini, 4, ',', ' ')) ?></strong></p>
    <p class="muted">0 = distribution égale | 1 = concentration extrême</p>
  </div>
  <div class="card">
    <h3>Whale Concentration</h3>
    <p class="metric-one-line"><strong><?= htmlspecialchars(number_format((float) ($concTiers[0]['pct_supply'] ?? 0.0), 2, ',', ' ')) ?>%</strong></p>
    <p class="muted">Part de supply détenue par les wallets &ge; 100k</p>
  </div>
  <div class="card">
    <h3>Holders positifs</h3>
    <p class="metric-one-line"><strong><?= htmlspecialchars(fmt_int_fr((int) $concHolderCount)) ?></strong></p>
    <p class="muted">Supply positive approx: <?= htmlspecialchars($fmtRaw((string) $concTotalSupplyRaw)) ?></p>
  </div>
  <div class="card">
    <h3>Wallets team (règle date)</h3>
    <p class="metric-one-line"><strong><?= htmlspecialchars(fmt_int_fr((int) $concTeamWalletCount)) ?></strong></p>
    <p class="muted">first_seen &lt; <?= htmlspecialchars((string) $concTeamCutoffDate) ?> | supply: <?= htmlspecialchars($fmtRaw((string) $concTeamHoldingRaw)) ?> (<?= htmlspecialchars(number_format((float) $concTeamHoldingPct, 2, ',', ' ')) ?>%)</p>
  </div>
  <div class="card">
    <h3>Intérêts attribués Deblock</h3>
    <p class="metric-one-line"><strong><?= htmlspecialchars($fmtRaw((string) $concTeamInterestRaw)) ?></strong></p>
    <p class="muted">Part des intérêts: <?= htmlspecialchars(number_format((float) $concTeamInterestPct, 2, ',', ' ')) ?>% (calcul lifetime)</p>
  </div>
</section>

<p class="muted">Note: les métriques de cette page sont calculées sur tout l’historique (lifetime), indépendamment du filtre de dates.</p>

<section class="panel">
  <h2>Intérêts team vs non-team</h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Segment</th><th>Intérêts cumulés</th><th>% intérêts</th></tr>
      </thead>
      <tbody>
        <tr>
          <td>Team (&lt; 12/03/2026)</td>
          <td><?= htmlspecialchars($fmtRaw((string) $concTeamInterestRaw)) ?></td>
          <td><?= htmlspecialchars(number_format((float) $concTeamInterestPct, 2, ',', ' ')) ?>%</td>
        </tr>
        <tr>
          <td>Non-team</td>
          <td><?= htmlspecialchars($fmtRaw((string) $concInterestNonTeamRaw)) ?></td>
          <td><?= htmlspecialchars(number_format(max(0.0, 100.0 - (float) $concTeamInterestPct), 2, ',', ' ')) ?>%</td>
        </tr>
        <tr>
          <td>Total</td>
          <td><?= htmlspecialchars($fmtRaw((string) $concInterestTotalRaw)) ?></td>
          <td>100,00%</td>
        </tr>
      </tbody>
    </table>
  </div>
</section>

<section class="panel">
  <h2>Holder Concentration</h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Cohort</th><th>Holding Amount</th><th>% Supply</th></tr>
      </thead>
      <tbody>
        <?php foreach ($concCohorts as $r) : ?>
        <tr>
          <td><?= htmlspecialchars((string) ($r['label'] ?? '')) ?></td>
          <td><?= htmlspecialchars($fmtRaw((string) ($r['holding_raw'] ?? '0'))) ?></td>
          <td><?= htmlspecialchars(number_format((float) ($r['pct_supply'] ?? 0.0), 2, ',', ' ')) ?>%</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel">
  <h2>Tier Distribution</h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Tier</th><th>Holder Count</th><th>% Holders</th><th>% Supply</th></tr>
      </thead>
      <tbody>
        <?php foreach ($concTiers as $r) : ?>
        <tr>
          <td><?= htmlspecialchars((string) ($r['label'] ?? '')) ?></td>
          <td><?= htmlspecialchars(fmt_int_fr((int) ($r['holder_count'] ?? 0))) ?></td>
          <td><?= htmlspecialchars(number_format((float) ($r['pct_holders'] ?? 0.0), 2, ',', ' ')) ?>%</td>
          <td><?= htmlspecialchars(number_format((float) ($r['pct_supply'] ?? 0.0), 2, ',', ' ')) ?>%</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel">
  <h2>Wallet Depth by Threshold</h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Threshold</th><th>Holders</th><th>% of Total</th></tr>
      </thead>
      <tbody>
        <?php foreach ($concDepth as $r) : ?>
        <tr>
          <td>&gt; <?= htmlspecialchars(fmt_int_fr((int) ($r['threshold'] ?? '0'))) ?></td>
          <td><?= htmlspecialchars(fmt_int_fr((int) ($r['holders'] ?? 0))) ?></td>
          <td><?= htmlspecialchars(number_format((float) ($r['pct_total'] ?? 0.0), 2, ',', ' ')) ?>%</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
