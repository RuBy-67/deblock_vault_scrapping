<?php

declare(strict_types=1);

/** @var array<string, mixed> $cfg */
/** @var string $dateFrom */
/** @var string $dateTo */
/** @var string $counterparty */
/** @var list<array<string, mixed>> $rows */
/** @var list<array<string, mixed>> $topCounterparties */
/** @var list<array<string, mixed>> $byType */
/** @var list<array{day: string, n: int}> $chartDaily */
/** @var bool $hasWeeklyPaymentChart */
/** @var string $chartPayloadJson */
/** @var int $recentTransfersLimit */
/** @var callable(string): string $cpDashboardHref */
?>
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
      <p class="muted chart-caption">Somme des montants classés <code>interest</code> par jour, <strong>une jambe par paire</strong> (même règle que le tableau « Par type »). Hors mint/burn <code>0x0</code>. Heuristique v1, pas un libellé on-chain.</p>
      <div class="chart-canvas-wrap">
        <canvas id="chartInterestJour" aria-label="Volume interest classé par jour"></canvas>
      </div>
      <p class="muted" style="margin-top:1rem; max-width:52rem">
        <strong>Paiements / top up</strong> : seules les lignes <code>payment</code> et <code>top_up</code> (v1).
      </p>
      <h3 class="chart-title">payment / top_up : volume + nombre (≈ € / jour + tx / jour)</h3>
      <div class="chart-canvas-wrap chart-canvas-wrap--short">
        <canvas id="chartPaymentTopupCombined" aria-label="payment et top_up : volume (≈ €) et nombre de lignes par jour"></canvas>
      </div>
      <h3 class="chart-title">Paiement moyen par jour (<code>payment</code> ticket moyen ≈ €)</h3>
      <p class="muted chart-caption">Pour chaque jour : somme des montants <code>payment</code> ÷ nombre de lignes <code>payment</code> ce jour-là. Jours sans payment : 0.</p>
      <div class="chart-canvas-wrap chart-canvas-wrap--short">
        <canvas id="chartPaymentAvgDaily" aria-label="Ticket moyen payment par jour"></canvas>
      </div>
      <!-- Graphiques payment/top_up combinés (volume + nombre) au-dessus -->
    <?php endif; ?>

    <h3 class="chart-title" style="margin-top:1.75rem">Paiements (<code>payment</code>)  volume par semaine</h3>
    <p class="muted chart-caption">Volume classé <code>payment</code> (hors mint <code>0x0</code>), semaine type ISO (<strong>lundi → dimanche</strong>). Côté user, envois <strong>vers le noeud</strong>.</p>
    <?php if ($hasWeeklyPaymentChart) : ?>
      <p class="muted" style="margin-bottom:0.75rem;max-width:52rem">
        <strong>Moyenne</strong> sur semaines où il y a eu au moins un payment : <strong><?= htmlspecialchars(fmt_eur($avgPayActiveWeekRaw)) ?></strong> / sem.
        <strong>Étalée sur la plage filtrée</strong> (≈ <?= htmlspecialchars(number_format($weeksInFilterSpan, 1, ',', "\u{202F}")) ?> sem.) : <strong><?= htmlspecialchars(number_format($avgPayCalWeekEur, 2, ',', "\u{202F}")) ?>&nbsp;€</strong> / sem.
        <?php if ($nDistinctPayersPayment > 0) : ?>
          <br><strong>Moyenne par compte distinct</strong> (toute la période) : <strong><?= htmlspecialchars(fmt_eur($avgPayPerDistinctAccountRaw)) ?></strong>
          sur <strong><?= htmlspecialchars(fmt_int_fr($nDistinctPayersPayment)) ?></strong> adresses ayant au moins un <code>payment</code>
          (= total ÷ comptes ; pas la médiane ; une même adresse sur plusieurs semaines est comptée une fois sur la période).
          <br><strong>Moyenne des moyennes hebdo</strong> (pour chaque semaine : volume ÷ comptes actifs cette semaine, puis moyenne arithmétique des semaines) : <strong><?= htmlspecialchars(number_format($avgOfWeeklyAvgPerAccountEur, 2, ',', "\u{202F}")) ?>&nbsp;€</strong>.
        <?php endif; ?>
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
        <div class="insight-label">Moyenne par compte <strong>toute la période</strong> (payment)</div>
        <div class="insight-value"><?= $nDistinctPayersPayment > 0 ? htmlspecialchars(fmt_eur($avgPayPerDistinctAccountRaw)) : '—' ?></div>
        <div class="insight-detail"><?= $nDistinctPayersPayment > 0 ? htmlspecialchars(fmt_int_fr($nDistinctPayersPayment)) . ' comptes distincts (contrepartie) · <strong>pas</strong> une moyenne par semaine : c’est total « payment » sur la plage ÷ nb de comptes (chaque adresse comptée 1×). Sur le graphique hebdo : <strong>indigo</strong> = moyenne <strong>par semaine</strong> ; <strong>ligne pointillée</strong> = cette valeur (réf. sur toute la période).' : 'Aucun compte' ?></div>
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

  <?php if ($counterparty === '') : ?>
  <details class="panel panel-details">
    <summary class="panel-details__summary">Adresses les plus actives, exclus mint/burn <code>0x0</code></summary>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Contrepartie</th>
            <th># IN</th>
            <th># OUT</th>
            <th>Total</th>
            <th>Vol. IN (≈ €)</th>
            <th>Vol. OUT (≈ €)</th>
            <th>Premier / dernier</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($topCounterparties as $tr) : ?>
          <?php
                $cpAdr = (string) $tr['cp'];
                $ni = (int) $tr['n_in'];
                $no = (int) $tr['n_out'];
                $hint = monitor_cp_row_hint($ni, $no);
              ?>
          <tr>
            <td class="mono cp-cell" style="font-size:0.82rem">
              <a href="<?= htmlspecialchars($cpDashboardHref($cpAdr)) ?>" title="Filtrer le tableau de bord sur ce portefeuille"><?= htmlspecialchars(substr($cpAdr, 0, 12)) ?>…</a>
              <span class="cp-actions">
                <button type="button" class="btn-copy btn-copy--sm" data-copy="<?= htmlspecialchars($cpAdr) ?>" data-copy-label="Copier" title="Copier l’adresse complète">Copier</button>
                <a href="https://etherscan.io/address/<?= htmlspecialchars($cpAdr) ?>" target="_blank" rel="noopener" class="muted" style="font-size:0.75rem">Etherscan</a>
              </span>
            </td>
            <td><?= htmlspecialchars(fmt_int_fr($ni)) ?></td>
            <td><?= htmlspecialchars(fmt_int_fr($no)) ?></td>
            <td><?= htmlspecialchars(fmt_int_fr((int) $tr['n_total'])) ?></td>
            <td><?= htmlspecialchars(fmt_eur((string) ($tr['sum_in_raw'] ?? '0'))) ?></td>
            <td><?= htmlspecialchars(fmt_eur((string) ($tr['sum_out_raw'] ?? '0'))) ?></td>
            <td class="muted" style="font-size:0.82rem;white-space:nowrap">
              <?= htmlspecialchars(substr((string) $tr['first_seen'], 0, 10)) ?>
              → <?= htmlspecialchars(substr((string) $tr['last_seen'], 0, 10)) ?>
            </td>
            <td class="muted" style="font-size:0.8rem"><?= $hint !== '' ? htmlspecialchars($hint) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$topCounterparties) : ?>
          <tr><td colspan="8" class="muted">Aucune donnée sur cette période / filtre.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </details>
  <?php endif; ?>

  <details class="panel panel-details">
    <summary class="panel-details__summary">Derniers transferts (<?= (int) $recentTransfersLimit ?> max)</summary>
    <p class="muted panel-details__intro">
      Même périmètre que les totaux : <strong>sans</strong> lignes mint/burn <code>0x0</code>. Colonne <strong>Gas</strong> : coût total en <strong>ETH</strong> ; répété sur chaque ligne partageant le même lien « voir » si une tx contient plusieurs Transfers.
    </p>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Temps</th>
            <th>Dir</th>
            <th>Type</th>
            <th>Contrepartie</th>
            <th>Montant (≈ €)</th>
            <th>Frais Deblock est. (≈ €)</th>
            <th>Gas (ETH)</th>
            <th>Tx</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r) : ?>
          <?php
              $rowCp = strtolower(trim((string) ($r['counterparty'] ?? '')));
              $rowCpOk = $rowCp !== '' && preg_match('/^0x[a-f0-9]{40}$/', $rowCp);
            ?>
          <tr>
            <td><?= htmlspecialchars((string) $r['block_time']) ?></td>
            <td><?= htmlspecialchars((string) $r['direction']) ?></td>
            <td><?= htmlspecialchars((string) ($r['event_type'] ?? '')) ?></td>
            <td class="mono cp-cell" title="<?= htmlspecialchars($rowCp) ?>">
              <?php if ($rowCpOk) : ?>
              <a href="<?= htmlspecialchars($cpDashboardHref($rowCp)) ?>" title="Filtrer sur ce portefeuille"><?= htmlspecialchars(substr($rowCp, 0, 10)) ?>…</a>
              <button type="button" class="btn-copy btn-copy--sm" data-copy="<?= htmlspecialchars($rowCp) ?>" data-copy-label="Copier" title="Copier l’adresse">Copier</button>
              <?php else : ?>
              —
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars(fmt_eur((string) $r['amount_raw'])) ?></td>
            <td><?= $r['fee_token_raw'] ? htmlspecialchars(fmt_eur((string) $r['fee_token_raw'])) : '—' ?></td>
            <td><?= htmlspecialchars(fmt_eth($r['cost_eth'] ?? null)) ?></td>
            <td class="mono"><a href="https://etherscan.io/tx/<?= htmlspecialchars((string) $r['tx_hash']) ?>" target="_blank" rel="noopener">voir</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </details>
