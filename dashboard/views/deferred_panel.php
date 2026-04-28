<?php

declare(strict_types=1);

/** @var array<string, mixed> $cfg */
/** @var string $dateFrom */
/** @var string $dateTo */
/** @var string $counterparty */
/** @var list<array<string, mixed>> $rows */
/** @var list<array<string, mixed>> $topCounterparties */
/** @var list<array<string, mixed>> $topWalletsByToken */
/** @var list<array<string, mixed>> $byType */
/** @var list<array{day: string, n: int}> $chartDaily */
/** @var bool $hasWeeklyPaymentChart */
/** @var string $vaultTargetEur */
/** @var list<array<string, mixed>> $vaultMatches */
/** @var string $chartPayloadJson */
/** @var int $recentTransfersLimit */
/** @var callable(string): string $cpDashboardHref */
?>
<?php
$amountSearchActive = $counterparty === '' && (($vaultTargetEur ?? '') !== '');
$interestTotalRaw = '0';
foreach ($byType as $rowType) {
    if ((string) ($rowType['event_type'] ?? '') === 'interest') {
        $interestTotalRaw = normalize_amount_raw($rowType['sum_raw'] ?? '0');
        break;
    }
}
if ($amountSearchActive) : ?>
  <details class="panel panel-details" open>
    <summary class="panel-details__summary">Wallets proches d’un montant cible (Vault v1)</summary>
    <p class="muted panel-details__intro">
      Cible : <strong><?= htmlspecialchars(fmt_eur((string) eur_to_raw_wei($vaultTargetEur))) ?></strong> ·
      tri par écart absolu (Top-up − Payment) sur la période / filtre, donne le montant actuel du compte.
    </p>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Wallet</th>
            <th>Vault v1</th>
            <th>Top-up</th>
            <th>Payment</th>
            <th>Ecart (≈ €)</th>
            <th># payment</th>
            <th># top_up</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($vaultMatches as $tr) : ?>
          <?php
            $cpAdr = (string) ($tr['cp'] ?? '');
            $vaultRaw = (string) ($tr['vault_approx_raw'] ?? '0');
            $sumTopRaw = (string) ($tr['sum_topup_raw'] ?? '0');
            $sumPayRaw = (string) ($tr['sum_payment_raw'] ?? '0');
            $absDiffRaw = (string) ($tr['abs_diff_raw'] ?? '0');
          ?>
          <tr>
            <td class="mono cp-cell" style="font-size:0.82rem">
              <a href="<?= htmlspecialchars($cpDashboardHref($cpAdr)) ?>" title="Filtrer le tableau de bord sur ce portefeuille"><?= htmlspecialchars(substr($cpAdr, 0, 12)) ?>…</a>
              <span class="cp-actions">
                <button type="button" class="btn-copy btn-copy--sm" data-copy="<?= htmlspecialchars($cpAdr) ?>" data-copy-label="Copier" title="Copier l’adresse complète">Copier</button>
                <a href="https://etherscan.io/address/<?= htmlspecialchars($cpAdr) ?>" target="_blank" rel="noopener" class="muted" style="font-size:0.75rem">Etherscan</a>
              </span>
            </td>
            <td><?= htmlspecialchars(fmt_eur_signed_raw($vaultRaw)) ?></td>
            <td><?= htmlspecialchars(fmt_eur($sumTopRaw)) ?></td>
            <td><?= htmlspecialchars(fmt_eur($sumPayRaw)) ?></td>
            <td class="muted"><?= htmlspecialchars(fmt_eur($absDiffRaw)) ?></td>
            <td><?= htmlspecialchars(fmt_int_fr((int) ($tr['n_payment'] ?? 0))) ?></td>
            <td><?= htmlspecialchars(fmt_int_fr((int) ($tr['n_topup'] ?? 0))) ?></td>
            <td class="muted" style="font-size:0.8rem"> </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$vaultMatches) : ?>
          <tr><td colspan="8" class="muted">Aucun wallet proche sur cette période / filtre.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </details>

  <?php return; ?>
<?php endif; ?>

  <section class="panel chart-panel">
    <h2>Graphiques activité par jour</h2>
    <p class="muted" style="margin-top:-0.35rem">
      Basé sur <code>block_time</code> (UTC). Filtres <strong>dates / contrepartie</strong> comme le formulaire.
    </p>
    <?php if (!$chartDaily) : ?>
      <p class="muted">Pas encore assez de données pour un graphique sur cette période.</p>
    <?php else : ?>
      <h3 class="chart-title">Nombre de transferts par jour</h3>
      <p class="muted chart-caption">Toutes les lignes <code>raw_transfers</code> du noeud sur ce token (brut chaîne, mint/burn <code>0x0</code> inclus si présents dans l’import).</p>
      <div class="chart-canvas-wrap chart-canvas-wrap--short">
        <canvas id="chartActiviteJour" aria-label="Nombre de transferts par jour"></canvas>
      </div>
      <h3 class="chart-title">Volume total traité par le noeud (≈ € / jour)</h3>
      <p class="muted chart-caption">Somme des montants de toutes les lignes <code>raw_transfers</code> ce jour (chaque transfert compté une fois, IN et OUT inclus). Hors mint/burn <code>0x0</code>. Mêmes filtres dates / Wallet que le formulaire.</p>
      <div class="chart-canvas-wrap">
        <canvas id="chartNodeVolumeJour" aria-label="Volume total jeton traité par le noeud par jour"></canvas>
      </div>
      <h3 class="chart-title">Intérêt « versé » par jour (classification v1)</h3>
      <p class="muted chart-caption">
        Somme des montants classés <code>interest</code> par jour, <strong>une jambe par paire</strong> (même règle que le tableau « Par type »). Hors mint/burn <code>0x0</code>. Heuristique v1, pas un libellé on-chain.
        <br>Total cumulé sur la plage affichée (depuis le J1 de la plage) : <strong><?= htmlspecialchars(fmt_eur($interestTotalRaw)) ?></strong>.
      </p>
      <div class="chart-canvas-wrap">
        <canvas id="chartInterestJour" aria-label="Volume interest classé par jour"></canvas>
      </div>
      <p class="muted" style="margin-top:1rem; max-width:52rem">
        <strong>Paiements / top up</strong> : seules les lignes <code>payment</code> et <code>top_up</code> (v1).
      </p>
      <h3 class="chart-title">payment / top_up : volume + nombre (≈ € / jour + tx / jour)</h3>
      <p class="muted chart-caption">
        <strong>Deux échelles</strong> : axe de gauche = volumes en euros (courbes). Axe de droite = nombre de lignes classées par jour (barres).
        <strong>Payment</strong> = trait plein + barres «&nbsp;#&nbsp;» ; <strong>top up</strong> = trait <em>pointillé</em> + barres «&nbsp;#&nbsp;». Quatre couleurs distinctes (légende à gauche si le graphique défile). Survolez un jour pour le détail.
      </p>
      <div class="chart-canvas-wrap chart-canvas-wrap--short">
        <canvas id="chartPaymentTopupCombined" aria-label="payment et top_up : volume (≈ €) et nombre de lignes par jour"></canvas>
      </div>
      <h3 class="chart-title">Paiement moyen par jour (<code>payment</code> ticket moyen ≈ €)</h3>
      <p class="muted chart-caption">Pour chaque jour : somme des montants <code>payment</code> ÷ nombre de lignes <code>payment</code> ce jour-là. Jours sans payment : 0.</p>
      <div class="chart-canvas-wrap chart-canvas-wrap--short">
        <canvas id="chartPaymentAvgDaily" aria-label="Ticket moyen payment par jour"></canvas>
      </div>
      <h3 class="chart-title">Flux net journalier (Top-up − Payment)</h3>
      <p class="muted chart-caption">Delta quotidien en v1. Positif = sorties noeud (top_up) supérieures aux entrées noeud (payment).</p>
      <div class="chart-canvas-wrap chart-canvas-wrap--short">
        <canvas id="chartFluxNetDaily" aria-label="Flux net journalier top_up moins payment"></canvas>
      </div>
      <!-- Graphiques payment/top_up combinés (volume + nombre) au-dessus -->
    <?php endif; ?>

    <h3 class="chart-title" style="margin-top:1.75rem">Paiements (<code>payment</code>)  volume par semaine</h3>
    <p class="muted chart-caption">Volume classé <code>payment</code> (hors mint <code>0x0</code>), semaine type ISO (<strong>lundi → dimanche</strong>). Côté user, envois <strong>vers le noeud</strong>.</p>
    <?php if ($hasWeeklyPaymentChart) : ?>
      <p class="muted chart-weekly-averages" style="margin-bottom:0.75rem;max-width:52rem">
        Moyenne sur semaines où il y a eu au moins un payment : <span class="chart-accent-value"><?= htmlspecialchars(fmt_eur($avgPayActiveWeekRaw)) ?></span> / sem.
        · Étalée sur la plage filtrée (≈ <?= htmlspecialchars(number_format($weeksInFilterSpan, 1, ',', "\u{202F}")) ?> sem.) : <span class="chart-accent-value"><?= htmlspecialchars(number_format($avgPayCalWeekEur, 2, ',', "\u{202F}")) ?>&nbsp;€</span> / sem.
      </p>
      <div class="chart-canvas-wrap">
        <canvas id="chartPaymentWeekly" aria-label="Volume payment agrégé par semaine"></canvas>
      </div>
    <?php else : ?>
      <p class="muted">Aucun <code>payment</code> sur la période / filtre.</p>
    <?php endif; ?>

    <h3 class="chart-title" style="margin-top:1.5rem">Agrégats utiles</h3>
    <p class="muted chart-caption">Classification v1, hors <code>0x0</code>. Les montants jeton ≈ €.</p>
    <div class="insight-grid">
      <div class="insight-card insight-card--wide">
        <h4 class="insight-type-heading">Par type (classification)</h4>
        <p class="muted insight-type-intro">
          Sur les transferts <strong>hors mint/burn 0x0</strong> (aligné avec le reste du tableau). Règle <strong>v1</strong> : pas des libellés on-chain. <strong>interest</strong> = une <strong>paire</strong> avec le <strong>même portefeuille A</strong> : <code>A → noeud</code> puis <code>noeud → A</code> dans <code>PAIR_WINDOW_SECONDS</code> (vérif. <code>from</code>/<code>to</code>), les deux jambes ≤ <code>INTEREST_PAIR_MAX_RAW</code>. L’écart des montants n’a pas à être faible ; option <code>INTEREST_PAIR_MAX_FEE_BPS</code> &gt; 0 pour imposer un ratio max |a−b|/max(a,b). Un transfert <strong>seul</strong> reste <strong>payment</strong> ou <strong>top_up</strong>. Colonne <code>fee</code> ≈ |IN−OUT| sur la paire. Paramètres dans <code>.env</code> ; après changement de règles, <code>npm run classify</code> avec <code>CLASSIFY_FULL_REBUILD=true</code>.
        </p>
        <div class="table-wrap">
          <table>
            <thead>
              <tr><th>Type</th><th>Nombre</th><th>Somme (≈ €)</th></tr>
            </thead>
            <tbody>
              <?php foreach ($byType as $r) : ?>
              <tr>
                <td><?= htmlspecialchars((string) $r['event_type']) ?></td>
                <td><?= htmlspecialchars(fmt_int_fr((int) $r['n'])) ?></td>
                <td><?= htmlspecialchars(fmt_eur((string) ($r['sum_raw'] ?? '0'))) ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$byType) : ?>
              <tr><td colspan="3" class="muted">Aucune donnée (importez le schéma et lancez le worker).</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="insight-card">
        <div class="insight-label">Volume <code>payment</code> total</div>
        <div class="insight-value"><?= htmlspecialchars(fmt_eur($totalPaymentPeriodRaw)) ?></div>
        <div class="insight-detail"><?= htmlspecialchars(fmt_int_fr($totalPaymentTxWeek)) ?> tx · ticket moyen (par tx) <?= htmlspecialchars(fmt_eur($avgTicketPaymentRaw)) ?></div>
      </div>
      <div class="insight-card">
        <div class="insight-label">Dépense moyenne par compte (<strong>mois courant</strong>)</div>
        <div class="insight-value"><?= $nDistinctPayersPaymentMonth > 0 ? htmlspecialchars(fmt_eur($avgPayPerDistinctAccountMonthRaw)) : '—' ?></div>
        <div class="insight-detail"><?= $nDistinctPayersPaymentMonth > 0 ? htmlspecialchars(fmt_int_fr($nDistinctPayersPaymentMonth)) . ' comptes distincts sur le mois courant (total payment du mois ÷ comptes).' : 'Aucun compte actif ce mois' ?></div>
      </div>
      <div class="insight-card">
        <div class="insight-label">Semaines « actives » (≥ 1 payment)</div>
        <div class="insight-value"><?= htmlspecialchars(fmt_int_fr($nWeeksPaymentActive)) ?></div>
        <div class="insight-detail">Semaine record<?php if ($bestWeekStart) : ?> : <span class="mono"><?= htmlspecialchars(substr((string) $bestWeekStart, 0, 10)) ?></span> · <?= htmlspecialchars(fmt_eur($bestWeekRaw)) ?><?php else : ?> : —<?php endif; ?></div>
      </div>
      <div class="insight-card">
        <div class="insight-label">Jour le plus fort en <code>payment</code></div>
        <div class="insight-value"><?= $bestPayDay ? htmlspecialchars($bestPayDay) : '—' ?></div>
        <div class="insight-detail"><?= $bestPayDay ? htmlspecialchars(fmt_eur($bestPayDayRaw)) : 'Pas de donnée' ?></div>
      </div>
      <div class="insight-card">
        <div class="insight-label">Volume <code>top_up</code> (sorties noeud)</div>
        <div class="insight-value"><?= htmlspecialchars(fmt_eur($topUpInsightRaw)) ?></div>
        <div class="insight-detail"><?= htmlspecialchars(fmt_int_fr($nTopUpInsight)) ?> tx
          <?php if (($tPayF + $tTopF) > 0) : ?>
            · part payment dans payment+top_up : <strong><?= htmlspecialchars(number_format($payVsPayPlusTopFloat, 1, ',', "\u{202F}")) ?> %</strong>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
