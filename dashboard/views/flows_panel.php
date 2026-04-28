<?php

declare(strict_types=1);

/** @var string $inTotalRaw */
/** @var string $outTotalRaw */
/** @var string $paymentSumRaw */
/** @var string $topUpSumRaw */
/** @var string $vaultApproxBizRaw */
/** @var bool $hasCharts */
?>
<section class="cards cards--top">
  <div class="card">
    <h3>Flux brut chaîne</h3>
    <p class="metric-one-line"><span class="metric-inline-label">IN</span><br><strong><?= htmlspecialchars(fmt_eur($inTotalRaw)) ?></strong></p>
    <p class="metric-one-line"><span class="metric-inline-label">OUT</span><br><strong><?= htmlspecialchars(fmt_eur($outTotalRaw)) ?></strong></p>
  </div>
  <div class="card">
    <h3>Flux classifiés v1</h3>
    <p class="metric-one-line"><span class="metric-inline-label">Payment</span><br><strong><?= htmlspecialchars(fmt_eur($paymentSumRaw)) ?></strong></p>
    <p class="metric-one-line"><span class="metric-inline-label">Top-up</span><br><strong><?= htmlspecialchars(fmt_eur($topUpSumRaw)) ?></strong></p>
  </div>
  <div class="card">
    <h3>Net v1</h3>
    <p class="metric-one-line"><strong style="font-size:1.2rem"><?= htmlspecialchars(fmt_eur_signed_raw($vaultApproxBizRaw)) ?></strong></p>
    <p class="muted">Top-up − Payment (sur la plage filtrée).</p>
  </div>
</section>

<section class="panel chart-panel">
  <h2>Graphiques de flux</h2>
  <?php if (!$hasCharts) : ?>
    <p class="muted">Pas assez de données sur cette période.</p>
  <?php else : ?>
    <h3 class="chart-title">Flux net journalier (Top-up − Payment)</h3>
    <div class="chart-canvas-wrap chart-canvas-wrap--short">
      <canvas id="chartFluxNetDaily" aria-label="Flux net journalier top_up moins payment"></canvas>
    </div>

    <h3 class="chart-title">Payment / top-up volume + nombre</h3>
    <div class="chart-canvas-wrap chart-canvas-wrap--short">
      <canvas id="chartPaymentTopupCombined" aria-label="Payment et top_up volume et nombre par jour"></canvas>
    </div>

    <h3 class="chart-title">Vault v1 cumulé</h3>
    <div class="chart-canvas-wrap">
      <canvas id="chartVaultDaily" aria-label="Vault v1 cumulé"></canvas>
    </div>
  <?php endif; ?>
</section>
