<?php

declare(strict_types=1);

/** @var array<string, mixed> $cfg */
/** @var string $dateFrom */
/** @var string $dateTo */
/** @var string $counterparty */
/** @var string $activePage */
/** @var string $dashboardUrl */
/** @var string $walletsUrl */
/** @var string $flowsUrl */
/** @var string $costsUrl */
/** @var string $qualityUrl */
/** @var string $concentrationUrl */
/** @var string $deferEndpoint */
/** @var bool $loadCharts */
/** @var string $deferredStatusText */
/** @var callable(string): string $cpDashboardHref */
/** @var string $dashboardOgBase */
/** @var string $dashboardOgPage */
/** @var string $dashboardOgImage */
$isOverview = ($activePage === 'dashboard');
$ogTitle = 'Monitoring noeud EURCV - SG -Techblock';
$ogDescription = 'Tableau de bord : flux on-chain du noeud, volumes, classification v1 (payment / top_up / interest), graphiques et coûts (frais estimés, gas). Filtres par dates et par contrepartie.';
?>
<!DOCTYPE html>
<html lang="fr" prefix="og: https://ogp.me/ns#">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Monitoring noeud EURCV</title>
  <meta name="description" content="<?= htmlspecialchars($ogDescription) ?>">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Deblock, monitoring noeud">
  <meta property="og:title" content="<?= htmlspecialchars($ogTitle) ?>">
  <meta property="og:description" content="<?= htmlspecialchars($ogDescription) ?>">
  <meta property="og:url" content="<?= htmlspecialchars($dashboardOgPage) ?>">
  <meta property="og:image" content="<?= htmlspecialchars($dashboardOgImage) ?>">
  <meta property="og:image:type" content="image/png">
  <meta property="og:locale" content="fr_FR">
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= htmlspecialchars($ogTitle) ?>">
  <meta name="twitter:description" content="<?= htmlspecialchars($ogDescription) ?>">
  <meta name="twitter:image" content="<?= htmlspecialchars($dashboardOgImage) ?>">
  <link rel="icon" href="lib/deblock.png" type="image/png">
  <link rel="apple-touch-icon" href="lib/deblock.png">
  <link rel="stylesheet" href="styles.css">
</head>
<body>
  <header class="header">
    <div class="header__brand">
      <img src="lib/deblock.png" alt="Deblock" class="header__logo" width="44" height="44" decoding="async">
      <h1>Monitoring noeud SG- Techblock</h1>
    </div>
    <p class="muted">
      Noeud : <code><?= htmlspecialchars($cfg['node_address']) ?></code>
      Contrat token : <code><?= htmlspecialchars($cfg['token_contract']) ?></code>
      Montants jeton affichés comme <strong>équivalent euro</strong> (1 unité sur chaîne ≈ 1 €), mis à jours toute les 30 minutes.
    </p>
  </header>

  <form class="filters" method="get">
    <label>Du <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"></label>
    <label>Au <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"></label>
    <label class="filters__cp">Wallet (0x…)
      <span class="filters__cp-row">
        <input id="counterparty-input" type="text" name="counterparty" value="<?= htmlspecialchars($counterparty) ?>" placeholder="0x…" autocomplete="off">
        <button type="button" class="btn-copy" data-copy-target="#counterparty-input" data-copy-label="Copier" title="Copier l’adresse saisie">Copier</button>
      </span>
    </label>
    <label class="filters__cp">Recherche par montant (Vault v1 ≈ €)
      <span class="filters__cp-row">
        <input type="number" name="vault_target_eur" value="<?= htmlspecialchars($vaultTargetEur) ?>" placeholder="ex: 1200" min="0" step="1">
        <input type="number" name="vault_tolerance_eur" value="<?= htmlspecialchars($vaultToleranceEur) ?>" placeholder="marge ± € (optionnel)" min="0" step="1">
      </span>
    </label>
    <button type="submit">Filtrer</button>
  </form>

  <nav class="view-switch" aria-label="Vues du dashboard">
    <a class="view-switch__link<?= $activePage === 'dashboard' ? ' is-active' : '' ?>" href="<?= htmlspecialchars($dashboardUrl) ?>">Dashboard</a>
    <a class="view-switch__link<?= $activePage === 'wallets' ? ' is-active' : '' ?>" href="<?= htmlspecialchars($walletsUrl) ?>">Wallets</a>
    <a class="view-switch__link<?= $activePage === 'flows' ? ' is-active' : '' ?>" href="<?= htmlspecialchars($flowsUrl) ?>">Flux</a>
    <a class="view-switch__link<?= $activePage === 'costs' ? ' is-active' : '' ?>" href="<?= htmlspecialchars($costsUrl) ?>">Coûts</a>
    <a class="view-switch__link<?= $activePage === 'quality' ? ' is-active' : '' ?>" href="<?= htmlspecialchars($qualityUrl) ?>">Qualité</a>
    <a class="view-switch__link<?= $activePage === 'concentration' ? ' is-active' : '' ?>" href="<?= htmlspecialchars($concentrationUrl) ?>">Concentration</a>
  </nav>

  <?php if ($isOverview) : ?>
    <?php require __DIR__ . '/cards_pending.php'; ?>
  <?php endif; ?>

  <div id="monitor-deferred-mount" class="monitor-deferred">
    <p class="muted monitor-deferred__status" id="monitor-deferred-status" aria-live="polite">
      <span class="monitor-deferred__spinner" aria-hidden="true"></span>
      <?= htmlspecialchars($deferredStatusText) ?>
    </p>
  </div>

  <?php if ($loadCharts) : ?>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js" crossorigin="anonymous"></script>
  <script src="charts.js"></script>
  <?php endif; ?>
  <script>
(function () {
  var mount = document.getElementById('monitor-deferred-mount');
  if (!mount) return;
  var statusEl = document.getElementById('monitor-deferred-status');
  var url = <?= json_encode($deferEndpoint, JSON_UNESCAPED_SLASHES) ?> + window.location.search;
  fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    })
    .then(function (data) {
      if (data.error) throw new Error(String(data.error));
      var cm = document.getElementById('monitor-cards-mount');
      if (cm && data.cardsHtml) {
        cm.outerHTML = data.cardsHtml;
      }
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
      var cm = document.getElementById('monitor-cards-mount');
      if (cm) {
        cm.querySelectorAll('.metric-pending').forEach(function (p) {
          p.textContent = 'Indisponible rechargez la page.';
          p.classList.add('metric-pending--error');
        });
        cm.removeAttribute('aria-busy');
      }
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
