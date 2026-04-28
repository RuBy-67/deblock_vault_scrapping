<?php

declare(strict_types=1);

/** @var array<string, mixed> $feeRow */
/** @var array<string, mixed> $gasRow */
/** @var int $nTx */
/** @var float $avgGasEthByTx */
/** @var bool $hasGasChart */
?>
<section class="cards cards--top">
  <div class="card">
    <h3>Frais token estimés</h3>
    <p class="metric-one-line"><strong><?= htmlspecialchars(fmt_eur((string) ($feeRow['fee_sum_raw'] ?? '0'))) ?></strong></p>
  </div>
  <div class="card">
    <h3>Gas total</h3>
    <p class="metric-one-line"><strong><?= htmlspecialchars(fmt_eth((string) ($gasRow['gas_eth'] ?? '0'))) ?></strong></p>
    <p class="muted"><?= htmlspecialchars(fmt_int_fr($nTx)) ?> transferts dans le périmètre</p>
  </div>
  <div class="card">
    <h3>Gas moyen / transfert</h3>
    <p class="metric-one-line"><strong><?= htmlspecialchars(fmt_eth(number_format($avgGasEthByTx, 10, '.', ''))) ?></strong></p>
  </div>
</section>

<section class="panel chart-panel">
  <h2>Évolution des coûts</h2>
  <?php if (!$hasGasChart) : ?>
    <p class="muted">Aucune série gas disponible.</p>
  <?php else : ?>
    <h3 class="chart-title">Gas cumulé (ETH)</h3>
    <div class="chart-canvas-wrap">
      <canvas id="chartGasDaily" aria-label="Gas cumulé en ETH"></canvas>
    </div>
  <?php endif; ?>
</section>
