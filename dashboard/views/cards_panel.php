<?php

declare(strict_types=1);

/** @var string $inTotalRaw */
/** @var string $outTotalRaw */
/** @var bool $fluxCardShowOnchainSplit */
/** @var string $inMintRaw */
/** @var string $inUserRaw */
/** @var string $outBurnRaw */
/** @var string $outUserRaw */
/** @var int $nTxAllRaw */
/** @var string $paymentSumRaw */
/** @var string $topUpSumRaw */
/** @var int $nPaymentClass */
/** @var int $nTopUpClass */
/** @var string $vaultApproxBizRaw */
/** @var string $netChainRaw */
/** @var string $reconciliationGapRaw */
/** @var array<string, mixed> $feeRow */
/** @var array<string, mixed> $gasRow */
/** @var string $counterparty */
?>
<?php
$gasEthTotalCard = (float) ($gasRow['gas_eth'] ?? 0);
$nTxCard = (int) ($nTxAllRaw ?? 0);
$avgGasEthByTxCard = $nTxCard > 0 ? ($gasEthTotalCard / $nTxCard) : 0.0;
?>
<section class="cards cards--top" id="monitor-cards-mount">
  <div class="card">
    <h3>Sur le noeud (total chaîne)</h3>
    <dl class="metric-pair">
      <div>
        <dt>Reçu IN</dt>
        <dd><strong><?= htmlspecialchars(fmt_eur($inTotalRaw)) ?></strong></dd>
        <dd class="metric-note">Transferts entrants hors mint/burn <code>0x0</code>.</dd>
      </div>
      <div>
        <dt>Envoyé OUT</dt>
        <dd><strong><?= htmlspecialchars(fmt_eur($outTotalRaw)) ?></strong></dd>
        <dd class="metric-note">Transferts sortants hors mint/burn <code>0x0</code>.</dd>
      </div>
    </dl>
    <p class="muted metric-foot"><?= htmlspecialchars(fmt_int_fr($nTxAllRaw)) ?> lignes Transfer sur la période</p>
  </div>
  <div class="card">
    <h3>À suivre côté « user » (v1)</h3>
    <dl class="metric-pair">
      <div>
        <dt>Flux entrants typés paiement</dt>
        <dd><strong><?= htmlspecialchars(fmt_eur($paymentSumRaw)) ?></strong></dd>
        <dd class="metric-note">Classé <code>payment</code> (<?= htmlspecialchars(fmt_int_fr($nPaymentClass)) ?> lignes). Souvent des users qui <strong>envoient au noeud</strong> (côté user : sortie depuis leur wallet).</dd>
      </div>
      <div>
        <dt>Flux sortants typés retrait</dt>
        <dd><strong><?= htmlspecialchars(fmt_eur($topUpSumRaw)) ?></strong></dd>
        <dd class="metric-note">Classé <code>top_up</code> (<?= htmlspecialchars(fmt_int_fr($nTopUpClass)) ?> lignes). Souvent du noeud <strong>vers un wallet</strong> (côté user : entrée).</dd>
      </div>
    </dl>
    <p class="card-help">Sous-ensemble des IN/OUT : règles v1 + lignes <code>unknown</code> non incluses. Croise avec le tableau « Par type » dessous.</p>
  </div>
  <div class="card">
    <h3>Vault ordre de grandeur (v1)</h3>
    <p class="metric-one-line" style="margin-top:0.25rem">
      <strong style="font-size:1.15rem"><?= htmlspecialchars(fmt_eur_signed_raw($vaultApproxBizRaw)) ?></strong>
    </p>
    <?php if (($counterparty ?? '') === '') : ?>
    <p class="metric-note">Valeur globale historique (hors 0x0), indépendante du filtre de dates pour conserver la vue long terme.</p>
    <?php endif; ?>
    <div class="vault-mini-chart" aria-label="Somme cumulée Top-up moins Payment (v1) par jour">
      <canvas id="chartVaultDaily" aria-label="Evolution du montant du vault (v1) par jour"></canvas>
    </div>
  </div>
  <div class="card">
    <h3>Coûts période</h3>
    <p class="metric-one-line"><span class="metric-inline-label">Deblock (estimation, frais prelevés sur le jeton)</span><br><strong><?= htmlspecialchars(fmt_eur((string) ($feeRow['fee_sum_raw'] ?? '0'))) ?></strong></p>
    <dl class="metric-pair metric-pair--2col" style="margin-top:0.5rem">
      <div>
        <dt>Gas (ETH)</dt>
        <dd><strong><?= htmlspecialchars(fmt_eth((string) ($gasRow['gas_eth'] ?? '0'))) ?></strong></dd>
      </div>
      <div>
        <dt>Coût moyen</dt>
        <dd><strong><?= $nTxCard > 0 ? htmlspecialchars(fmt_eth(number_format($avgGasEthByTxCard, 12, '.', ''))) : '—' ?></strong></dd>
        <?php if ($nTxCard > 0) : ?>
        <dd class="metric-note">Gas total ÷ <?= htmlspecialchars(fmt_int_fr($nTxCard)) ?> transferts (périmètre filtré).</dd>
        <?php else : ?>
        <dd class="metric-note">Aucun transfert sur la période.</dd>
        <?php endif; ?>
      </div>
    </dl>
    <h4 style="margin:0.65rem 0 0.35rem;font-size:0.95rem;font-weight:700;color:#1a1a1a">Gas (ETH) cumulé (progression)</h4>
    <div class="vault-mini-chart vault-mini-chart--delta" aria-label="Gas (ETH) cumulé (progression) par jour">
      <canvas id="chartGasDaily" aria-label="Gas (ETH) cumulé (progression) par jour"></canvas>
    </div>
  </div>
</section>
