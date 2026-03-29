<?php

declare(strict_types=1);

/** @var array<string, mixed> $cfg */
/** @var string $dateFrom */
/** @var string $dateTo */
/** @var string $counterparty */
/** @var callable(string): string $cpDashboardHref */
/** @var string $dashboardOgBase */
/** @var string $dashboardOgPage */
/** @var string $dashboardOgImage */
$ogTitle = 'Monitoring noeud EURCV — Deblock';
$ogDescription = 'Tableau de bord interne : flux on-chain du noeud, volumes, classification v1 (payment / top_up / interest), graphiques et coûts (frais estimés, gas). Filtres par dates et par contrepartie.';
?>
<!DOCTYPE html>
<html lang="fr" prefix="og: https://ogp.me/ns#">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Monitoring noeud EURCV</title>
  <meta name="description" content="<?= htmlspecialchars($ogDescription) ?>">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Deblock — monitoring noeud">
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
      <h1>Monitoring noeud</h1>
    </div>
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
    Les chiffres des cartes et le détail (graphiques, tableaux) se chargent en arrière-plan après l’affichage de la page. Mêmes filtres <strong>dates / contrepartie</strong> partout.
  </p>

  <?php require __DIR__ . '/cards_pending.php'; ?>

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
          p.textContent = 'Indisponible — rechargez la page.';
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
