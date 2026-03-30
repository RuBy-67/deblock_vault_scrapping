<?php

declare(strict_types=1);

/** @var string $vaultTargetEur */
/** @var string $vaultToleranceEur */
?>
<section class="cards cards--top" id="monitor-cards-mount">
  <div class="card">
    <h3>Recherche wallets par montant (Vault v1)</h3>
    <p class="muted" style="margin:0.35rem 0 0">
      Cible : <strong><?= htmlspecialchars($vaultTargetEur !== '' ? $vaultTargetEur : '—') ?> €</strong>
      <?php if ($vaultToleranceEur !== '') : ?>
        · marge ± <strong><?= htmlspecialchars($vaultToleranceEur) ?> €</strong>
      <?php endif; ?>
    </p>
  </div>
</section>
