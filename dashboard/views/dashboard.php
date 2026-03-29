<?php

declare(strict_types=1);

/** @var array<string, mixed> $cfg */
/** @var string $dateFrom */
/** @var string $dateTo */
/** @var string $counterparty */
/** @var array<string, mixed> $flux */
/** @var array<string, mixed> $feeRow */
/** @var array<string, mixed> $gasRow */
/** @var array<string, mixed> $mintBurn */
/** @var int $recentTransfersLimit */
/** @var callable(string): string $cpDashboardHref */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Monitoring noeud EURCV</title>
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <header class="header">
    <h1>Monitoring noeud</h1>
    <p class="muted">
      <strong>Même périmètre tout le temps</strong> (worker + ce tableau) : adresse noeud et contrat définis dans <code>.env</code>.
      Pas d’autre token ni d’autre noeud dans ces chiffres.
    </p>
    <p class="muted">
      Noeud : <code><?= htmlspecialchars($cfg['node_address']) ?></code>
      — Contrat token : <code><?= htmlspecialchars($cfg['token_contract']) ?></code>
      — Montants jeton affichés comme <strong>équivalent euro</strong> (1 unité sur chaîne ≈ 1 €).
    </p>
  </header>

  <form class="filters" method="get">
    <label>Du <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"></label>
    <label>Au <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"></label>
    <label class="filters__cp">Contrepartie (0x…)
      <span class="filters__cp-row">
        <input id="counterparty-input" type="text" name="counterparty" value="<?= htmlspecialchars($counterparty) ?>" placeholder="0x…" autocomplete="off">
        <button type="button" class="btn-copy" data-copy-target="#counterparty-input" data-copy-label="Copier" title="Copier l’adresse saisie">Copier</button>
      </span>
    </label>
    <button type="submit">Filtrer</button>
  </form>
  <p class="muted" style="margin:-0.5rem 0 1rem; max-width:48rem">
    Graphiques journaliers : mêmes filtres <strong>dates / contrepartie</strong> (activité brute, <code>interest</code> v1, <code>payment</code> / <code>top_up</code>). Liste et top contreparties restent <strong>hors</strong> mint/burn <code>0x0</code> où indiqué.
  </p>

  <section class="cards cards--top">
    <div class="card">
      <h3>Sur le noeud (total chaîne)</h3>
      <dl class="metric-pair">
        <div>
          <dt>Reçu — IN</dt>
          <dd><strong><?= htmlspecialchars(fmt_eur($inTotalRaw)) ?></strong></dd>
          <?php if ($fluxCardShowOnchainSplit) : ?>
          <dd class="metric-note">dont <strong><?= htmlspecialchars(fmt_eur($inMintRaw)) ?></strong> mint (<code>0x0</code>) et <strong><?= htmlspecialchars(fmt_eur($inUserRaw)) ?></strong> depuis d’autres adresses.</dd>
          <?php else : ?>
          <dd class="metric-note">Filtré contrepartie (mint <code>0x0</code> non mélangé ici).</dd>
          <?php endif; ?>
        </div>
        <div>
          <dt>Envoyé — OUT</dt>
          <dd><strong><?= htmlspecialchars(fmt_eur($outTotalRaw)) ?></strong></dd>
          <?php if ($fluxCardShowOnchainSplit) : ?>
          <dd class="metric-note">dont <strong><?= htmlspecialchars(fmt_eur($outBurnRaw)) ?></strong> burn (<code>0x0</code>) et <strong><?= htmlspecialchars(fmt_eur($outUserRaw)) ?></strong> vers d’autres adresses.</dd>
          <?php else : ?>
          <dd class="metric-note">Filtré contrepartie.</dd>
          <?php endif; ?>
        </div>
      </dl>
      <p class="muted metric-foot"><?= htmlspecialchars(fmt_int_fr($nTxAllRaw)) ?> lignes Transfer sur la période<?= $fluxCardShowOnchainSplit ? ' (tout compris)' : '' ?></p>
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
      <h3>Vault — ordre de grandeur (v1)</h3>
      <p class="metric-one-line" style="margin-top:0.25rem">
        <strong style="font-size:1.15rem"><?= htmlspecialchars(fmt_eur_signed_raw($vaultApproxBizRaw)) ?></strong>
      </p>
      <p class="muted" style="font-size:0.85rem;margin:0.5rem 0 0;line-height:1.4">
        <strong>top_up</strong> (sorties noeud) <strong>−</strong> <strong>payment</strong> (entrées typées) sur la période / filtre :
        <?= htmlspecialchars(fmt_eur($topUpSumRaw)) ?> − <?= htmlspecialchars(fmt_eur($paymentSumRaw)) ?>.
      </p>
      <p class="card-help">Vue métier approximative : retraits classés moins dépôts classés. N’inclut pas <code>interest</code>, <code>unknown</code>, ni mint/burn <code>0x0</code> hors classification. Peut différer du solde on-chain.</p>
    </div>
    <div class="card">
      <h3>Coûts période</h3>
      <p class="metric-one-line"><span class="metric-inline-label">Deblock (estimation, frais prelevés sur le jeton)</span><br><strong><?= htmlspecialchars(fmt_eur((string) ($feeRow['fee_sum_raw'] ?? '0'))) ?></strong></p>
      <p class="metric-one-line"><span class="metric-inline-label">Gas (ETH)</span><br><strong><?= htmlspecialchars(fmt_eth((string) ($gasRow['gas_eth'] ?? '0'))) ?></strong></p>
    </div>
    <div class="card">
      <h3>Mint / burn <code>0x0</code></h3>
      <p class="metric-one-line"><span class="metric-inline-label">Mint (vers le noeud)</span><br><strong><?= htmlspecialchars(fmt_eur((string) ($mintBurn['sum_in_raw'] ?? '0'))) ?></strong></p>
      <p class="metric-one-line"><span class="metric-inline-label">Burn (depuis le noeud)</span><br><strong><?= htmlspecialchars(fmt_eur((string) ($mintBurn['sum_out_raw'] ?? '0'))) ?></strong></p>
      <p class="muted metric-foot"><?= htmlspecialchars(fmt_int_fr((int) ($mintBurn['n_tx'] ?? 0))) ?> tx · dates du formulaire uniquement</p>
    </div>
  </section>

  <div id="monitor-deferred-mount" class="monitor-deferred">
    <p class="muted monitor-deferred__status" id="monitor-deferred-status" aria-live="polite">
      <span class="monitor-deferred__spinner" aria-hidden="true"></span>
      Chargement des graphiques et des tableaux détaillés…
    </p>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js" crossorigin="anonymous"></script>
  <script src="charts.js"></script>
  <script>
(function () {
  var mount = document.getElementById('monitor-deferred-mount');
  if (!mount) return;
  var statusEl = document.getElementById('monitor-deferred-status');
  var url = 'defer.php' + window.location.search;
  fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    })
    .then(function (data) {
      if (data.error) throw new Error(String(data.error));
      mount.innerHTML = data.html || '';
      if (data.initCharts && data.chartPayloadJson) {
        var old = document.getElementById('monitor-chart-payload');
        if (old) old.remove();
        var s = document.createElement('script');
        s.type = 'application/json';
        s.id = 'monitor-chart-payload';
        s.textContent = data.chartPayloadJson;
        document.body.appendChild(s);
        if (typeof window.monitorInitCharts === 'function') {
          window.monitorInitCharts();
        }
      }
    })
    .catch(function (err) {
      if (statusEl) {
        statusEl.textContent =
          'Impossible de charger le détail (' + (err && err.message ? err.message : 'erreur') + '). Rechargez la page.';
        statusEl.classList.add('monitor-deferred__status--error');
      }
    });
})();
  </script>
  <script>
(function () {
  function restore(btn, label) {
    btn.textContent = label;
    btn.disabled = false;
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-copy-target], [data-copy]');
    if (!btn) return;
    var label = btn.getAttribute('data-copy-label') || btn.textContent || 'Copier';
    var text = '';
    if (btn.hasAttribute('data-copy')) {
      text = (btn.getAttribute('data-copy') || '').trim();
    } else {
      var sel = btn.getAttribute('data-copy-target');
      var el = sel ? document.querySelector(sel) : null;
      text = el ? String(el.value || '').trim() : '';
    }
    if (!text) {
      btn.disabled = true;
      btn.textContent = '—';
      setTimeout(function () { restore(btn, label); }, 900);
      return;
    }
    var ok = function () {
      btn.disabled = true;
      btn.textContent = 'Copié !';
      setTimeout(function () { restore(btn, label); }, 1400);
    };
    var fail = function () {
      btn.disabled = true;
      btn.textContent = 'Échec';
      setTimeout(function () { restore(btn, label); }, 1400);
    };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(ok).catch(fail);
    } else {
      fail();
    }
  });
})();
  </script>
</body>
</html>
