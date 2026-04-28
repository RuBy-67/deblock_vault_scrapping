<?php

declare(strict_types=1);

/** @var array<string, mixed> $qualityStats */
/** @var list<array<string, mixed>> $qualityByType */
/** @var list<array<string, mixed>> $qualityConfidenceBuckets */
?>
<section class="cards cards--top">
  <div class="card">
    <h3>Lignes classifiées</h3>
    <p class="metric-one-line"><strong><?= htmlspecialchars(fmt_int_fr((int) ($qualityStats['total_classified'] ?? 0))) ?></strong></p>
  </div>
  <div class="card">
    <h3>Interest</h3>
    <p class="metric-one-line"><strong><?= htmlspecialchars(fmt_int_fr((int) ($qualityStats['n_interest'] ?? 0))) ?></strong></p>
    <p class="muted">dont appariées: <?= htmlspecialchars(fmt_int_fr((int) ($qualityStats['n_interest_paired'] ?? 0))) ?></p>
  </div>
  <div class="card">
    <h3>Unknown</h3>
    <p class="metric-one-line"><strong><?= htmlspecialchars(fmt_int_fr((int) ($qualityStats['n_unknown'] ?? 0))) ?></strong></p>
  </div>
</section>

<section class="panel">
  <h2>Répartition par type</h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Type</th><th>Nombre</th><th>Dont paired_transfer_id</th></tr>
      </thead>
      <tbody>
        <?php foreach ($qualityByType as $r) : ?>
        <tr>
          <td><?= htmlspecialchars((string) ($r['event_type'] ?? '')) ?></td>
          <td><?= htmlspecialchars(fmt_int_fr((int) ($r['n'] ?? 0))) ?></td>
          <td><?= htmlspecialchars(fmt_int_fr((int) ($r['n_paired'] ?? 0))) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel">
  <h2>Distribution de confiance</h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Bucket</th><th>Nombre</th></tr>
      </thead>
      <tbody>
        <?php foreach ($qualityConfidenceBuckets as $r) : ?>
        <tr>
          <td><?= htmlspecialchars((string) ($r['bucket'] ?? '')) ?></td>
          <td><?= htmlspecialchars(fmt_int_fr((int) ($r['n'] ?? 0))) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
