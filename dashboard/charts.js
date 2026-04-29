/**
 * Graphiques Chart.js  données injectées via #monitor-chart-payload (JSON).
 * Ré-exécutable après chargement différé (destroy des instances existantes).
 */
(function () {
  var CANVAS_IDS = [
    'chartActiviteJour',
    'chartNodeVolumeJour',
    'chartInterestJour',
    'chartPaymentTopupVolume',
    'chartPaymentAvgDaily',
    'chartPaymentTopupCount',
    'chartPaymentWeekly',
    'chartPaymentWeeklyActivity',
    'chartPaymentTopupCombined',
    'chartFluxNetDaily',
    'chartVaultDaily',
    'chartVaultDeltaDaily',
    'chartGasDaily',
    'chartExpandModalCanvas',
  ];

  /** Aperçu : derniers points sans scroll ; clic → modal avec série complète. */
  var MONITOR_PREVIEW_POINTS = 6;
  var MONITOR_SCROLL_SUPPRESS_MIN = 2000;

  function destroyChartsOnCanvases() {
    if (typeof Chart === 'undefined' || !Chart.getChart) return;
    var dlg = document.getElementById('monitor-chart-expand-dialog');
    if (dlg && dlg.open) {
      dlg.close();
    }
    CANVAS_IDS.forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      var ch = Chart.getChart(el);
      if (ch) ch.destroy();
    });
  }

  function monitorEnsureExpandDialog() {
    var d = document.getElementById('monitor-chart-expand-dialog');
    if (d) return d;
    d = document.createElement('dialog');
    d.id = 'monitor-chart-expand-dialog';
    d.className = 'monitor-chart-expand';
    d.innerHTML =
      '<div class="monitor-chart-expand__shell">' +
      '<header class="monitor-chart-expand__header">' +
      '<h2 class="monitor-chart-expand__title">Graphique</h2>' +
      '<button type="button" class="monitor-chart-expand__close" aria-label="Fermer">×</button>' +
      '</header>' +
      '<div class="monitor-chart-expand__chart-zone">' +
      '<p class="monitor-chart-expand__loading" id="monitor-chart-expand-loading" hidden>Chargement des données complètes…</p>' +
      '<div class="chart-scroll monitor-chart-expand__scroll" id="monitor-chart-expand-scroll">' +
      '<div class="chart-canvas-wrap chart-modal-canvas-wrap"><canvas id="chartExpandModalCanvas"></canvas></div>' +
      '</div></div></div>';
    document.body.appendChild(d);
    d.querySelector('.monitor-chart-expand__close').addEventListener('click', function () {
      d.close();
    });
    d.addEventListener('close', function () {
      var ld = document.getElementById('monitor-chart-expand-loading');
      if (ld) {
        ld.hidden = true;
      }
      var mc = document.getElementById('chartExpandModalCanvas');
      if (mc && Chart.getChart) {
        var ch = Chart.getChart(mc);
        if (ch) ch.destroy();
      }
      var scroll = document.getElementById('monitor-chart-expand-scroll');
      if (scroll) {
        scroll.scrollLeft = 0;
        var inner = scroll.querySelector('.chart-canvas-wrap');
        if (inner) inner.style.minWidth = '';
        var leg = scroll.querySelector('.chart-legend-sticky');
        if (leg) leg.remove();
        scroll.classList.remove('chart-scroll--with-sticky-legend');
      }
    });
    return d;
  }

  /** Vrai tant qu’au moins une série encore tronquée (après fusion des patchs). */
  function monitorPayloadHasCompactSeries(p) {
    if (!p || !p._chartMeta || p._chartMeta.mode !== 'compact') return false;
    var m = p._chartMeta;
    if (m.originalDailyCount != null && Array.isArray(p.daily) && p.daily.length < m.originalDailyCount) {
      return true;
    }
    if (
      m.originalWeeklyCount != null &&
      Array.isArray(p.weeklyPay) &&
      p.weeklyPay.length < m.originalWeeklyCount
    ) {
      return true;
    }
    return false;
  }

  function monitorExpandNeedsFetch(expandGroup) {
    var s = document.getElementById('monitor-chart-payload');
    if (!s) return false;
    var payload;
    try {
      payload = JSON.parse(s.textContent);
    } catch (e) {
      return false;
    }
    var m = payload._chartMeta;
    if (!m || m.mode !== 'compact') return false;
    if (expandGroup === 'daily') {
      if (m.originalDailyCount != null && Array.isArray(payload.daily)) {
        return payload.daily.length < m.originalDailyCount;
      }
      return true;
    }
    if (expandGroup === 'weekly') {
      if (m.originalWeeklyCount != null && Array.isArray(payload.weeklyPay)) {
        return payload.weeklyPay.length < m.originalWeeklyCount;
      }
      return true;
    }
    return false;
  }

  var monitorChartPatchPromises = {};

  /** Charge uniquement le bloc séries nécessaire (daily = toutes séries jour alignées ; weekly = weeklyPay). */
  function monitorEnsureChartExpandPatch(expandGroup) {
    if (!monitorExpandNeedsFetch(expandGroup)) {
      return Promise.resolve();
    }
    if (monitorChartPatchPromises[expandGroup]) {
      return monitorChartPatchPromises[expandGroup];
    }
    var endpoint = window.monitorDeferEndpoint || 'defer_dashboard.php';
    var params = new URLSearchParams(window.location.search);
    params.set('chart_payload', 'full');
    params.set('defer_charts_only', '1');
    params.set('chart_expand', expandGroup);
    var url = endpoint + '?' + params.toString();
    monitorChartPatchPromises[expandGroup] = fetch(url, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        monitorChartPatchPromises[expandGroup] = null;
        if (data.error) throw new Error(String(data.error));
        var patch = data.chartPayloadPatch;
        if (!patch || typeof patch !== 'object') {
          throw new Error('Réponse agrandissement invalide');
        }
        var el = document.getElementById('monitor-chart-payload');
        if (!el) return;
        var payload = JSON.parse(el.textContent);
        Object.assign(payload, patch);
        el.textContent = JSON.stringify(payload);
        window.monitorChartPayloadCompact = monitorPayloadHasCompactSeries(payload);
        if (typeof window.monitorInitCharts === 'function') {
          window.monitorInitCharts();
        }
      })
      .catch(function (e) {
        monitorChartPatchPromises[expandGroup] = null;
        throw e;
      });
    return monitorChartPatchPromises[expandGroup];
  }

  function monitorOpenChartExpandModal(wrapEl) {
    var dlg = monitorEnsureExpandDialog();
    var loadingEl = document.getElementById('monitor-chart-expand-loading');

    function buildChartInModal(skipShowModal) {
      var st = wrapEl && wrapEl._expandState;
      if (!st || typeof st.make !== 'function') {
        if (loadingEl) loadingEl.hidden = true;
        return;
      }
      var th = dlg.querySelector('.monitor-chart-expand__title');
      if (th) th.textContent = st.title;
      var mc = document.getElementById('chartExpandModalCanvas');
      if (!mc || typeof Chart === 'undefined' || !Chart.getChart) return;
      var ex = Chart.getChart(mc);
      if (ex) ex.destroy();
      var scrollEl = document.getElementById('monitor-chart-expand-scroll');
      if (scrollEl) {
        var inner0 = scrollEl.querySelector('.chart-canvas-wrap');
        if (inner0) inner0.style.minWidth = '';
        scrollEl.scrollLeft = 0;
        var leg0 = scrollEl.querySelector('.chart-legend-sticky');
        if (leg0) leg0.remove();
        scrollEl.classList.remove('chart-scroll--with-sticky-legend');
      }
      var fullCfg = st.make(null);
      var chM = monitorNewChart(mc, fullCfg);
      var nLab = fullCfg.data && fullCfg.data.labels ? fullCfg.data.labels.length : 0;
      if (chM && nLab >= 5) {
        var scrollForChart = document.getElementById('monitor-chart-expand-scroll');
        var capW = scrollForChart
          ? Math.min(7200, Math.max(400, (scrollForChart.clientWidth || 400) * 3))
          : 7200;
        var s = ensureChartHorizontalScroll(chM.canvas, nLab, {
          pxPerLabel: 40,
          maxWidth: capW,
        });
        if (s) monitorPopulateStickyLegend(chM, s);
      }
      if (!skipShowModal && !dlg.open) {
        dlg.showModal();
      }
      if (loadingEl) loadingEl.hidden = true;
      if (chM) {
        requestAnimationFrame(function () {
          chM.resize();
        });
      }
    }

    var st0 = wrapEl && wrapEl._expandState;
    var expandGroup = (st0 && st0.expandGroup) || 'daily';

    if (monitorExpandNeedsFetch(expandGroup)) {
      if (loadingEl) {
        loadingEl.hidden = false;
        loadingEl.textContent = 'Chargement des données complètes…';
      }
      var th0 = dlg.querySelector('.monitor-chart-expand__title');
      if (th0) th0.textContent = 'Chargement…';
      if (!dlg.open) {
        dlg.showModal();
      }
      monitorEnsureChartExpandPatch(expandGroup)
        .then(function () {
          buildChartInModal(true);
        })
        .catch(function (err) {
          console.error(err);
          if (loadingEl) loadingEl.hidden = true;
          try {
            dlg.close();
          } catch (e2) {
            /* ignore */
          }
          if (typeof window.alert === 'function') {
            alert(
              'Impossible de charger les données complètes du graphique. Réessayez ou vérifiez la connexion.'
            );
          }
        });
      return;
    }
    buildChartInModal(false);
  }

  /**
   * @param {HTMLElement} wrapEl parent .chart-canvas-wrap du canvas
   * @param {string} title titre du modal
   * @param {function(number|null): object} makeCfg nLast = 6 (aperçu) ou null (plein)
   * @param {string} [expandGroup] « daily » (défaut) ou « weekly » — détermine le patch réseau à fusionner
   */
  function monitorBindChartExpand(wrapEl, title, makeCfg, expandGroup) {
    if (!wrapEl || typeof makeCfg !== 'function') return;
    wrapEl.classList.add('chart-canvas-wrap--expandable');
    wrapEl.setAttribute('role', 'button');
    wrapEl.setAttribute('tabindex', '0');
    wrapEl.setAttribute('aria-label', title + ' — aperçu. Cliquer ou Entrée pour agrandir.');
    wrapEl._expandState = {
      make: makeCfg,
      title: title,
      expandGroup: expandGroup || 'daily',
    };
    if (wrapEl._expandListenersBound) return;
    wrapEl._expandListenersBound = true;
    wrapEl.addEventListener('click', function () {
      monitorOpenChartExpandModal(wrapEl);
    });
    wrapEl.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        monitorOpenChartExpandModal(wrapEl);
      }
    });
  }

  /**
   * Défilement horizontal si beaucoup de points (surtout mobile) : largeur min du conteneur graphique.
   * Le parent direct du canvas doit être .chart-canvas-wrap ou .vault-mini-chart.
   */
  function ensureChartHorizontalScroll(canvas, labelCount, options) {
    options = options || {};
    if (!canvas) return null;
    var inner = canvas.parentElement;
    if (!inner) return null;

    var isMini = inner.classList && inner.classList.contains('vault-mini-chart');
    var pxPerLabel = options.pxPerLabel != null ? options.pxPerLabel : isMini ? 22 : 36;
    var maxW = options.maxWidth != null ? options.maxWidth : isMini ? 3200 : 9600;
    var minN = options.minLabels != null ? options.minLabels : 5;

    var scrollEl = inner.parentElement;
    if (!scrollEl || !scrollEl.classList || !scrollEl.classList.contains('chart-scroll')) {
      scrollEl = null;
    }

    if (!labelCount || labelCount < minN) {
      inner.style.minWidth = '';
      if (scrollEl) {
        var leg0 = scrollEl.querySelector('.chart-legend-sticky');
        if (leg0) leg0.innerHTML = '';
        scrollEl.classList.remove('chart-scroll--with-sticky-legend');
      }
      return null;
    }

    if (!scrollEl || !scrollEl.classList.contains('chart-scroll')) {
      scrollEl = document.createElement('div');
      scrollEl.className = 'chart-scroll';
      inner.parentNode.insertBefore(scrollEl, inner);
      scrollEl.appendChild(inner);
    }

    var viewport = scrollEl.clientWidth || document.documentElement.clientWidth || 360;
    var targetW = Math.min(maxW, Math.max(viewport, labelCount * pxPerLabel));
    inner.style.minWidth = targetW + 'px';

    if (targetW > viewport) {
      requestAnimationFrame(function () {
        scrollEl.scrollLeft = scrollEl.scrollWidth;
      });
    }
    return scrollEl;
  }

  function monitorDatasetSwatchColor(ds) {
    var c = ds.borderColor;
    if (Array.isArray(c)) c = c[0];
    if (c == null || c === '') c = ds.backgroundColor;
    if (Array.isArray(c)) c = c[0];
    return typeof c === 'string' && c ? c : '#888';
  }

  function monitorPopulateStickyLegend(chart, scrollEl) {
    if (!chart || !scrollEl) return;
    var nSeries = chart.data.datasets.filter(function (ds) {
      return ds.label != null && String(ds.label) !== '';
    }).length;
    if (nSeries <= 1) {
      var rm = scrollEl.querySelector('.chart-legend-sticky');
      if (rm) rm.remove();
      scrollEl.classList.remove('chart-scroll--with-sticky-legend');
      return;
    }
    var aside = scrollEl.querySelector('.chart-legend-sticky');
    if (!aside) {
      aside = document.createElement('aside');
      aside.className = 'chart-legend-sticky';
      aside.setAttribute('aria-label', 'Légende du graphique');
      scrollEl.insertBefore(aside, scrollEl.firstChild);
    }
    scrollEl.classList.add('chart-scroll--with-sticky-legend');
    aside.innerHTML = '';
    var rootType = chart.config && chart.config.type ? chart.config.type : '';
    chart.data.datasets.forEach(function (ds) {
      if (ds.label == null || ds.label === '') return;
      var row = document.createElement('div');
      row.className = 'chart-legend-sticky__item';
      var sw = document.createElement('span');
      var isLine =
        ds.type === 'line' || (ds.type !== 'bar' && rootType === 'line');
      var col = monitorDatasetSwatchColor(ds);
      var lineBorder = typeof ds.borderColor === 'string' ? ds.borderColor : col;
      if (isLine) {
        sw.className = 'chart-legend-sticky__sw chart-legend-sticky__sw--line';
        sw.style.border = '2px solid ' + lineBorder;
        if (Array.isArray(ds.borderDash) && ds.borderDash.length) {
          sw.style.borderStyle = 'dashed';
        }
      } else {
        sw.className = 'chart-legend-sticky__sw';
        sw.style.background = col;
      }
      var txt = document.createElement('span');
      txt.textContent = String(ds.label);
      row.appendChild(sw);
      row.appendChild(txt);
      aside.appendChild(row);
    });
  }

  /**
   * Regroupe les premiers points (historique) et garde les derniers points détaillés.
   * Objectif: rendre les points récents plus lisibles sur les séries cumulées longues.
   */
  function condenseSeriesKeepRecent(series, valueKey, keepLastCount, groupSize) {
    if (!Array.isArray(series)) return [];
    var keepN = keepLastCount != null ? keepLastCount : 90;
    var gSize = groupSize != null ? groupSize : 7;
    if (series.length <= keepN + gSize) {
      return series.map(function (d) {
        return {
          day: d.day,
          _bucketRange: false,
          _bucketN: 1,
          value: d[valueKey],
        };
      });
    }

    var cut = series.length - keepN;
    var out = [];
    for (var i = 0; i < cut; i += gSize) {
      var chunk = series.slice(i, Math.min(cut, i + gSize));
      if (!chunk.length) continue;
      var first = chunk[0];
      var last = chunk[chunk.length - 1];
      out.push({
        day: first.day + ' → ' + last.day,
        _bucketRange: true,
        _bucketN: chunk.length,
        value: last[valueKey], // série cumulée: on garde la valeur de fin de bucket
      });
    }
    for (var j = cut; j < series.length; j++) {
      out.push({
        day: series[j].day,
        _bucketRange: false,
        _bucketN: 1,
        value: series[j][valueKey],
      });
    }
    return out;
  }

  function pickTickIndices(total, wantedCount) {
    if (!total || total <= 0) return {};
    var target = Math.max(2, wantedCount || 7);
    if (total <= target) {
      var all = {};
      for (var i = 0; i < total; i++) all[i] = true;
      return all;
    }
    var out = {};
    for (var k = 0; k < target; k++) {
      var idx = Math.round((k * (total - 1)) / (target - 1));
      out[idx] = true;
    }
    return out;
  }

  function monitorChartThemeColors() {
    var st = getComputedStyle(document.documentElement);
    return {
      tick: st.getPropertyValue('--chart-tick').trim() || '#64748b',
      grid: st.getPropertyValue('--chart-grid').trim() || 'rgba(0,0,0,0.06)',
    };
  }

  /** Couleurs séries : jour = noir/gris ; nuit = teal Deblock (#01d4ba) + secondaires. */
  function monitorChartPalette() {
    var st = getComputedStyle(document.documentElement);
    function g(name, fb) {
      var v = st.getPropertyValue(name).trim();
      return v || fb;
    }
    return {
      c1Fill: g('--chart-c1-fill', 'rgba(26,26,26,0.55)'),
      c1Stroke: g('--chart-c1-stroke', '#1a1a1a'),
      c1Area: g('--chart-c1-area', 'rgba(26,26,26,0.12)'),
      c2Fill: g('--chart-c2-fill', 'rgba(75,85,99,0.45)'),
      c2Stroke: g('--chart-c2-stroke', '#4b5563'),
      c2Area: g('--chart-c2-area', 'rgba(75,85,99,0.12)'),
      c3Fill: g('--chart-c3-fill', 'rgba(107,114,128,0.4)'),
      c3Stroke: g('--chart-c3-stroke', '#6b7280'),
      c3Area: g('--chart-c3-area', 'rgba(107,114,128,0.1)'),
      c4Stroke: g('--chart-c4-stroke', '#374151'),
      c5Stroke: g('--chart-c5-stroke', '#52525b'),
      payLine: g('--chart-pay-line', '#111111'),
      payArea: g('--chart-pay-area', 'rgba(17,17,17,0.14)'),
      payBar: g('--chart-pay-bar', 'rgba(17,17,17,0.62)'),
      topupLine: g('--chart-topup-line', '#6b6b6b'),
      topupArea: g('--chart-topup-area', 'rgba(0,0,0,0)'),
      topupBar: g('--chart-topup-bar', 'rgba(107,107,107,0.5)'),
      payBarBorder: g('--chart-pay-bar-border', '#111111'),
      topupBarBorder: g('--chart-topup-bar-border', '#6b6b6b'),
      fluxNegFill: g('--chart-flux-negative-fill', 'rgba(220,38,38,0.52)'),
      fluxNegStroke: g('--chart-flux-negative-stroke', '#b91c1c'),
      weeklyBarFill: g('--chart-weekly-bar-fill', 'rgba(30,64,110,0.72)'),
      weeklyBarStroke: g('--chart-weekly-bar-stroke', '#1e3f73'),
      weeklyLineAccount: g('--chart-weekly-line-account', '#6d28d9'),
      weeklyActivityCp: g('--chart-weekly-line-activity-cp', '#ca8a04'),
      weeklyActivityTx: g('--chart-weekly-line-activity-tx', '#64748b'),
    };
  }

  function monitorChartApplyTheme(options) {
    if (!options || typeof Chart === 'undefined') return;
    var tc = monitorChartThemeColors();
    if (options.plugins && options.plugins.legend && options.plugins.legend.display !== false) {
      var labels = options.plugins.legend.labels;
      if (labels !== false) {
        options.plugins.legend.labels = Object.assign({}, labels || {});
        if (!options.plugins.legend.labels.color) {
          options.plugins.legend.labels.color = tc.tick;
        }
      }
    }
    if (options.scales) {
      Object.keys(options.scales).forEach(function (k) {
        var sc = options.scales[k];
        if (!sc || typeof sc !== 'object') return;
        var ticks = Object.assign({}, sc.ticks || {});
        ticks.color = tc.tick;
        sc.ticks = ticks;
        var grid = Object.assign({}, sc.grid || {});
        grid.color = tc.grid;
        sc.grid = grid;
        if (sc.title && typeof sc.title === 'object') {
          sc.title = Object.assign({}, sc.title, { color: tc.tick });
        }
      });
    }
  }

  function monitorNewChart(ctx, config) {
    if (config && config.options) monitorChartApplyTheme(config.options);
    return new Chart(ctx, config);
  }

  function isoWeekStartFromDay(dayStr) {
    var d = new Date(dayStr + 'T00:00:00Z');
    if (isNaN(d.getTime())) return dayStr;
    var wd = d.getUTCDay(); // 0=dimanche ... 6=samedi
    var delta = wd === 0 ? -6 : 1 - wd; // lundi
    d.setUTCDate(d.getUTCDate() + delta);
    return d.toISOString().slice(0, 10);
  }

  // Série cumulée journalière -> 1 point/semaine (valeur de fin de semaine).
  function weeklyFromCumulativeDaily(series, valueKey) {
    if (!Array.isArray(series) || !series.length) return [];
    var out = [];
    var currentWeek = '';
    var currentValue = null;
    for (var i = 0; i < series.length; i++) {
      var row = series[i] || {};
      var day = row.day;
      var v = row[valueKey];
      if (day == null || v == null) continue;
      var wk = isoWeekStartFromDay(day);
      if (wk !== currentWeek) {
        if (currentWeek !== '' && currentValue != null) {
          out.push({ day: currentWeek, value: currentValue });
        }
        currentWeek = wk;
      }
      currentValue = v; // cumul: dernier point de la semaine
    }
    if (currentWeek !== '' && currentValue != null) {
      out.push({ day: currentWeek, value: currentValue });
    }
    return out;
  }

  window.monitorInitCharts = function () {
    var payloadEl = document.getElementById('monitor-chart-payload');
    if (!payloadEl) return;
    var payload;
    try {
      payload = JSON.parse(payloadEl.textContent);
    } catch (e) {
      return;
    }

    window.monitorChartPayloadCompact = monitorPayloadHasCompactSeries(payload);

    var daily = payload.daily || [];
    var dailyClass = payload.dailyClass || [];
    var interestDaily = payload.interestDaily || [];
    var nodeVolumeDaily = payload.nodeVolumeDaily || [];
    var paymentAvgDaily = payload.paymentAvgDaily || [];
    var weeklyPay = payload.weeklyPay || [];
    var vaultDaily = payload.vaultDaily || [];
    var vaultDeltaDaily = payload.vaultDeltaDaily || [];
    var gasDaily = payload.gasDaily || [];

    var fmtEur = function (v) {
      return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0, minimumFractionDigits: 0 }).format(v) + '\u202F€';
    };
    var fmtEurAxis = function (v) {
      return new Intl.NumberFormat('fr-FR', { notation: 'compact', compactDisplay: 'short', maximumFractionDigits: 1 }).format(v) + '\u202F€';
    };
    var fmtN = function (v) {
      return new Intl.NumberFormat('fr-FR').format(v);
    };

    var fmtEth = function (v) {
      return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 6, minimumFractionDigits: 0 }).format(v) + '\u202FETH';
    };
    var fmtEthAxis = function (v) {
      return new Intl.NumberFormat('fr-FR', { notation: 'compact', compactDisplay: 'short', maximumFractionDigits: 2, minimumFractionDigits: 0 }).format(v) + '\u202FETH';
    };

    var pal = monitorChartPalette();

    destroyChartsOnCanvases();

    if (daily.length) {
      var labels = daily.map(function (d) {
        return d.day;
      });

      var elAct = document.getElementById('chartActiviteJour');
      if (elAct) {
        var wrapAct = elAct.parentElement;
        function makeActiviteCfg(nLast) {
          var start = nLast != null ? Math.max(0, labels.length - nLast) : 0;
          var L = labels.slice(start);
          var rows = daily.slice(start);
          return {
            type: 'bar',
            data: {
              labels: L,
              datasets: [
                {
                  label: 'Transferts',
                  data: rows.map(function (d) {
                    return d.n;
                  }),
                  backgroundColor: pal.c1Fill,
                  borderColor: pal.c1Stroke,
                  borderWidth: 1,
                },
              ],
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: { display: false },
                tooltip: {
                  callbacks: {
                    label: function (ctx) {
                      return fmtN(ctx.parsed.y) + ' transferts';
                    },
                  },
                },
              },
              scales: {
                x: { ticks: { maxRotation: 45, minRotation: 0 } },
                y: {
                  beginAtZero: true,
                  ticks: { callback: function (val) { return fmtN(val); } },
                },
              },
            },
          };
        }
        var chAct = monitorNewChart(elAct, makeActiviteCfg(MONITOR_PREVIEW_POINTS));
        ensureChartHorizontalScroll(chAct.canvas, labels.length, {
          minLabels: MONITOR_SCROLL_SUPPRESS_MIN,
        });
        if (wrapAct) {
          monitorBindChartExpand(wrapAct, 'Nombre de transferts par jour', makeActiviteCfg);
        }
      }

      var elNodeVol = document.getElementById('chartNodeVolumeJour');
      if (elNodeVol) {
        var wrapNodeVol = elNodeVol.parentElement;
        function makeNodeVolumeCfg(nLast) {
          var start = nLast != null ? Math.max(0, labels.length - nLast) : 0;
          var L = labels.slice(start);
          var rows = nodeVolumeDaily.slice(start);
          return {
            type: 'line',
            data: {
              labels: L,
              datasets: [
                {
                  label: 'Volume total (≈ € / jour)',
                  data: rows.map(function (d) {
                    return d.volumeEur;
                  }),
                  borderColor: pal.c1Stroke,
                  backgroundColor: pal.c1Area,
                  fill: true,
                  tension: 0.2,
                  pointRadius: 2,
                },
              ],
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              interaction: { mode: 'index', intersect: false },
              plugins: {
                legend: { display: false },
                tooltip: {
                  callbacks: {
                    afterLabel: function (ctx) {
                      var idx = ctx.dataIndex;
                      if (idx >= 0 && rows[idx] && rows[idx].nTx) {
                        return rows[idx].nTx + ' transfert(s) (hors 0x0)';
                      }
                      return '';
                    },
                    label: function (ctx) {
                      var v = ctx.parsed.y;
                      if (v == null) return ctx.dataset.label;
                      return ctx.dataset.label + ' : ' + fmtEur(v);
                    },
                  },
                },
              },
              scales: {
                x: { ticks: { maxRotation: 45, minRotation: 0 } },
                y: {
                  beginAtZero: true,
                  ticks: { callback: function (val) { return fmtEurAxis(val); } },
                },
              },
            },
          };
        }
        var chNodeVol = monitorNewChart(elNodeVol, makeNodeVolumeCfg(MONITOR_PREVIEW_POINTS));
        ensureChartHorizontalScroll(chNodeVol.canvas, labels.length, {
          minLabels: MONITOR_SCROLL_SUPPRESS_MIN,
        });
        if (wrapNodeVol) {
          monitorBindChartExpand(
            wrapNodeVol,
            'Volume total traité par le noeud (≈ € / jour)',
            makeNodeVolumeCfg
          );
        }
      }

      var elInt = document.getElementById('chartInterestJour');
      if (elInt) {
        var wrapInt = elInt.parentElement;
        function makeInterestCfg(nLast) {
          var start = nLast != null ? Math.max(0, labels.length - nLast) : 0;
          var L = labels.slice(start);
          var rows = interestDaily.slice(start);
          return {
            type: 'bar',
            data: {
              labels: L,
              datasets: [
                {
                  label: 'Interest v1 (≈ € / jour)',
                  data: rows.map(function (d) {
                    return d.interestEur;
                  }),
                  backgroundColor: pal.c1Fill,
                  borderColor: pal.c1Stroke,
                  borderWidth: 1,
                },
              ],
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: { display: false },
                tooltip: {
                  callbacks: {
                    afterLabel: function (ctx) {
                      var idx = ctx.dataIndex;
                      if (idx >= 0 && rows[idx]) {
                        var ni = rows[idx].nInterest;
                        return ni ? ni + ' ligne(s) interest ce jour' : '';
                      }
                      return '';
                    },
                    label: function (ctx) {
                      var v = ctx.parsed.y;
                      if (v == null) return ctx.dataset.label;
                      return ctx.dataset.label + ' : ' + fmtEur(v);
                    },
                  },
                },
              },
              scales: {
                x: { ticks: { maxRotation: 45, minRotation: 0 } },
                y: {
                  beginAtZero: true,
                  ticks: { callback: function (val) { return fmtEurAxis(val); } },
                },
              },
            },
          };
        }
        var chInt = monitorNewChart(elInt, makeInterestCfg(MONITOR_PREVIEW_POINTS));
        ensureChartHorizontalScroll(chInt.canvas, labels.length, {
          minLabels: MONITOR_SCROLL_SUPPRESS_MIN,
        });
        if (wrapInt) {
          monitorBindChartExpand(wrapInt, 'Intérêt « versé » par jour (classification v1)', makeInterestCfg);
        }
      }

      var elPayTopupCombined = document.getElementById('chartPaymentTopupCombined');
      if (elPayTopupCombined && dailyClass && dailyClass.length) {
        var wrapPayTop = elPayTopupCombined.parentElement;
        function makePayTopCombinedCfg(nLast) {
          var start = nLast != null ? Math.max(0, labels.length - nLast) : 0;
          var L = labels.slice(start);
          var dc = dailyClass.slice(start);
          return {
            type: 'bar',
            data: {
              labels: L,
              datasets: [
                {
                  type: 'bar',
                  yAxisID: 'yCount',
                  label: 'Payment (#)',
                  data: dc.map(function (d) {
                    return d.nPayment;
                  }),
                  backgroundColor: pal.payBar,
                  borderColor: pal.payBarBorder,
                  borderWidth: 1,
                  order: 1,
                },
                {
                  type: 'bar',
                  yAxisID: 'yCount',
                  label: 'Top up (#)',
                  data: dc.map(function (d) {
                    return d.nTopUp;
                  }),
                  backgroundColor: pal.topupBar,
                  borderColor: pal.topupBarBorder,
                  borderWidth: 1,
                  order: 2,
                },
                {
                  type: 'line',
                  yAxisID: 'yVolume',
                  label: 'Payment (≈ €)',
                  data: dc.map(function (d) {
                    return d.payment;
                  }),
                  borderColor: pal.payLine,
                  backgroundColor: pal.payArea,
                  borderWidth: 2,
                  fill: true,
                  tension: 0.2,
                  pointRadius: 2,
                  order: 3,
                },
                {
                  type: 'line',
                  yAxisID: 'yVolume',
                  label: 'Top up (≈ €)',
                  data: dc.map(function (d) {
                    return d.top_up;
                  }),
                  borderColor: pal.topupLine,
                  backgroundColor: pal.topupArea,
                  borderWidth: 2,
                  borderDash: [6, 4],
                  fill: false,
                  tension: 0.2,
                  pointRadius: 2,
                  order: 4,
                },
              ],
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              interaction: { mode: 'index', intersect: false },
              datasets: {
                bar: {
                  categoryPercentage: 0.72,
                  barPercentage: 0.85,
                  borderRadius: 3,
                },
              },
              plugins: {
                legend: { display: false },
                tooltip: {
                  callbacks: {
                    label: function (ctx) {
                      var v = ctx.parsed.y;
                      if (v == null) return ctx.dataset.label;
                      if (ctx.dataset.yAxisID === 'yVolume') {
                        return ctx.dataset.label + ' : ' + fmtEur(v);
                      }
                      return ctx.dataset.label + ' : ' + fmtN(v);
                    },
                  },
                },
              },
              scales: {
                x: { ticks: { maxRotation: 45, minRotation: 0 }, grid: { display: false } },
                yVolume: {
                  position: 'left',
                  beginAtZero: true,
                  title: {
                    display: true,
                    text: 'Volume (≈ € / jour)',
                  },
                  ticks: { callback: function (val) { return fmtEurAxis(val); } },
                },
                yCount: {
                  position: 'right',
                  beginAtZero: true,
                  title: {
                    display: true,
                    text: 'Nombre de lignes (tx / jour)',
                  },
                  grid: { drawOnChartArea: false },
                  ticks: { callback: function (val) { return fmtN(val); } },
                },
              },
            },
          };
        }
        var chPayTop = monitorNewChart(elPayTopupCombined, makePayTopCombinedCfg(MONITOR_PREVIEW_POINTS));
        ensureChartHorizontalScroll(chPayTop.canvas, labels.length, {
          minLabels: MONITOR_SCROLL_SUPPRESS_MIN,
        });
        if (wrapPayTop) {
          monitorBindChartExpand(
            wrapPayTop,
            'payment / top_up : volume + nombre (≈ € / jour + tx / jour)',
            makePayTopCombinedCfg
          );
        }
      }

      // Petite courbe net (Top-up - Payment), cumulée dans le temps, dans la carte du haut.
      var elVault = document.getElementById('chartVaultDaily');
      if (elVault && vaultDaily && vaultDaily.length) {
        var vaultWeekly = weeklyFromCumulativeDaily(vaultDaily, 'vaultEur');
        var vaultTickIdx = pickTickIndices(vaultWeekly.length, 2);
        var chVault = monitorNewChart(elVault, {
          type: 'line',
          data: {
            labels: vaultWeekly.map(function (d) {
              return d.day;
            }),
            datasets: [
              {
                label: 'Top-up − Payment (cumul v1) (≈ €)',
                data: vaultWeekly.map(function (d) {
                  return d.value;
                }),
                borderColor: pal.c1Stroke,
                backgroundColor: pal.c1Area,
                fill: true,
                tension: 0.25,
                pointRadius: 1.6,
                pointHoverRadius: 3,
              },
            ],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
              legend: { display: false },
              tooltip: {
                callbacks: {
                  title: function (items) {
                    if (!items || !items.length) return '';
                    return String(items[0].label || '');
                  },
                  label: function (ctx) {
                    var v = ctx.parsed.y;
                    if (v == null) return ctx.dataset.label;
                    return ctx.dataset.label + ' : ' + fmtEur(v);
                  },
                },
              },
            },
            scales: {
              x: {
                ticks: {
                  maxRotation: 0,
                  minRotation: 0,
                  autoSkip: false,
                  callback: function (val, idx) {
                    return vaultTickIdx[idx] ? this.getLabelForValue(val) : '';
                  },
                },
                grid: { display: false },
              },
              y: {
                beginAtZero: false,
                ticks: { callback: function (val) { return fmtEurAxis(val); } },
                grid: { color: 'rgba(0,0,0,0.06)' },
              },
            },
          },
        });
      }

      // Graphique "écart journalier" : top_up - payment (v1) pour chaque jour.
      var elVaultDelta = document.getElementById('chartVaultDeltaDaily');
      if (elVaultDelta && vaultDeltaDaily && vaultDeltaDaily.length) {
        var chVaultD = monitorNewChart(elVaultDelta, {
          type: 'line',
          data: {
            labels: vaultDeltaDaily.map(function (d) {
              return d.day;
            }),
            datasets: [
              {
                label: 'Top-up − Payment (delta/jour) (v1) (≈ €)',
                data: vaultDeltaDaily.map(function (d) {
                  return d.vaultDeltaEur;
                }),
                borderColor: pal.c2Stroke,
                backgroundColor: pal.c2Area,
                fill: true,
                tension: 0.25,
                pointRadius: 1.4,
                pointHoverRadius: 3,
              },
            ],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
              legend: { display: false },
              tooltip: {
                callbacks: {
                  label: function (ctx) {
                    var v = ctx.parsed.y;
                    if (v == null) return ctx.dataset.label;
                    return ctx.dataset.label + ' : ' + fmtEur(v);
                  },
                },
              },
            },
            scales: {
              x: {
                ticks: { maxRotation: 45, minRotation: 0 },
                grid: { display: false },
              },
              y: {
                beginAtZero: false,
                ticks: { callback: function (val) { return fmtEurAxis(val); } },
                grid: { color: 'rgba(0,0,0,0.06)' },
              },
            },
          },
        });
        ensureChartHorizontalScroll(chVaultD.canvas, vaultDeltaDaily.length, { pxPerLabel: 22, maxWidth: 3200 });
      }

      var elAvgPay = document.getElementById('chartPaymentAvgDaily');
      if (elAvgPay) {
        var wrapAvgPay = elAvgPay.parentElement;
        function makeAvgPayCfg(nLast) {
          var start = nLast != null ? Math.max(0, labels.length - nLast) : 0;
          var L = labels.slice(start);
          var rows = paymentAvgDaily.slice(start);
          return {
            type: 'line',
            data: {
              labels: L,
              datasets: [
                {
                  label: 'Ticket moyen payment (≈ €)',
                  data: rows.map(function (d) {
                    return d.avgTicketEur;
                  }),
                  borderColor: pal.c1Stroke,
                  backgroundColor: pal.c1Area,
                  fill: true,
                  tension: 0.25,
                  pointRadius: 2,
                },
              ],
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              interaction: { mode: 'index', intersect: false },
              plugins: {
                legend: { display: false },
                tooltip: {
                  callbacks: {
                    afterLabel: function (ctx) {
                      var idx = ctx.dataIndex;
                      if (idx >= 0 && rows[idx] && rows[idx].nPayment) {
                        return rows[idx].nPayment + ' ligne(s) payment ce jour';
                      }
                      if (idx >= 0 && rows[idx] && !rows[idx].nPayment) {
                        return 'aucun payment';
                      }
                      return '';
                    },
                    label: function (ctx) {
                      var v = ctx.parsed.y;
                      if (v == null) return ctx.dataset.label;
                      return ctx.dataset.label + ' : ' + fmtEur(v);
                    },
                  },
                },
              },
              scales: {
                x: { ticks: { maxRotation: 45, minRotation: 0 } },
                y: {
                  beginAtZero: true,
                  ticks: { callback: function (val) { return fmtEurAxis(val); } },
                },
              },
            },
          };
        }
        var chAvgPay = monitorNewChart(elAvgPay, makeAvgPayCfg(MONITOR_PREVIEW_POINTS));
        ensureChartHorizontalScroll(chAvgPay.canvas, labels.length, {
          minLabels: MONITOR_SCROLL_SUPPRESS_MIN,
        });
        if (wrapAvgPay) {
          monitorBindChartExpand(
            wrapAvgPay,
            'Paiement moyen par jour (ticket payment ≈ €)',
            makeAvgPayCfg
          );
        }
      }

      var elFluxNet = document.getElementById('chartFluxNetDaily');
      if (elFluxNet && vaultDeltaDaily && vaultDeltaDaily.length) {
        var wrapFlux = elFluxNet.parentElement;
        function makeFluxNetCfg(nLast) {
          var start = nLast != null ? Math.max(0, vaultDeltaDaily.length - nLast) : 0;
          var rows = vaultDeltaDaily.slice(start);
          var fluxBg = rows.map(function (d) {
            var v = d.vaultDeltaEur;
            return v < 0 ? pal.fluxNegFill : pal.c2Fill;
          });
          var fluxBd = rows.map(function (d) {
            var v = d.vaultDeltaEur;
            return v < 0 ? pal.fluxNegStroke : pal.c2Stroke;
          });
          return {
            type: 'bar',
            data: {
              labels: rows.map(function (d) {
                return d.day;
              }),
              datasets: [
                {
                  label: 'Top-up − Payment (delta/jour) (≈ €)',
                  data: rows.map(function (d) {
                    return d.vaultDeltaEur;
                  }),
                  backgroundColor: fluxBg,
                  borderColor: fluxBd,
                  borderWidth: 1,
                },
              ],
            },
            options: {
              responsive: true,
              maintainAspectRatio: false,
              plugins: {
                legend: { display: false },
                tooltip: {
                  callbacks: {
                    label: function (ctx) {
                      var v = ctx.parsed.y;
                      if (v == null) return ctx.dataset.label;
                      return ctx.dataset.label + ' : ' + fmtEur(v);
                    },
                  },
                },
              },
              scales: {
                x: { ticks: { maxRotation: 45, minRotation: 0 }, grid: { display: false } },
                y: {
                  beginAtZero: true,
                  ticks: { callback: function (val) { return fmtEurAxis(val); } },
                },
              },
            },
          };
        }
        var chFluxNet = monitorNewChart(elFluxNet, makeFluxNetCfg(MONITOR_PREVIEW_POINTS));
        ensureChartHorizontalScroll(chFluxNet.canvas, vaultDeltaDaily.length, {
          minLabels: MONITOR_SCROLL_SUPPRESS_MIN,
        });
        if (wrapFlux) {
          monitorBindChartExpand(
            wrapFlux,
            'Flux net journalier (Top-up − Payment)',
            makeFluxNetCfg
          );
        }
      }

      var elPayTopupCount = document.getElementById('chartPaymentTopupCount');
      if (elPayTopupCount) {
        var chPayCnt = monitorNewChart(elPayTopupCount, {
          type: 'bar',
          data: {
            labels: labels,
            datasets: [
              {
                label: 'Payment',
                data: dailyClass.map(function (d) {
                  return d.nPayment;
                }),
                backgroundColor: pal.c1Fill,
                borderColor: pal.c1Stroke,
                borderWidth: 1,
              },
              {
                label: 'Top up',
                data: dailyClass.map(function (d) {
                  return d.nTopUp;
                }),
                backgroundColor: pal.c2Fill,
                borderColor: pal.c2Stroke,
                borderWidth: 1,
              },
            ],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { display: false },
              tooltip: {
                callbacks: {
                  label: function (ctx) {
                    return ctx.dataset.label + ' : ' + fmtN(ctx.parsed.y);
                  },
                },
              },
            },
            scales: {
              x: { ticks: { maxRotation: 45, minRotation: 0 }, stacked: false },
              y: {
                beginAtZero: true,
                ticks: { callback: function (val) { return fmtN(val); } },
              },
            },
          },
        });
        var sCnt = ensureChartHorizontalScroll(chPayCnt.canvas, labels.length, {});
        if (sCnt) monitorPopulateStickyLegend(chPayCnt, sCnt);
      }
    }

    if (gasDaily && gasDaily.length) {
      var elGas = document.getElementById('chartGasDaily');
      if (elGas) {
        var gasWeekly = weeklyFromCumulativeDaily(gasDaily, 'gasEth');
        var gasTickIdx = pickTickIndices(gasWeekly.length, 2);
        var chGas = monitorNewChart(elGas, {
          type: 'line',
          data: {
            labels: gasWeekly.map(function (d) {
              return d.day;
            }),
            datasets: [
              {
                label: 'Gas (ETH) cumulé (progression)',
                data: gasWeekly.map(function (d) {
                  return d.value;
                }),
                borderColor: pal.c1Stroke,
                backgroundColor: pal.c1Area,
                fill: true,
                tension: 0.25,
                pointRadius: 1.4,
                pointHoverRadius: 3,
              },
            ],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
              legend: { display: false },
              tooltip: {
                callbacks: {
                  title: function (items) {
                    if (!items || !items.length) return '';
                    return String(items[0].label || '');
                  },
                  label: function (ctx) {
                    var v = ctx.parsed.y;
                    if (v == null) return ctx.dataset.label;
                    return ctx.dataset.label + ' : ' + fmtEth(v);
                  },
                },
              },
            },
            scales: {
              x: {
                ticks: {
                  maxRotation: 0,
                  minRotation: 0,
                  autoSkip: false,
                  callback: function (val, idx) {
                    return gasTickIdx[idx] ? this.getLabelForValue(val) : '';
                  },
                },
                grid: { display: false },
              },
              y: {
                beginAtZero: true,
                ticks: { callback: function (val) { return fmtEthAxis(val); } },
              },
            },
          },
        });
      }
    }

    if (weeklyPay.length) {
      var elW = document.getElementById('chartPaymentWeekly');
      var wrapW = elW && elW.parentElement;
      function makeWeeklyVolumeCfg(nLast) {
        var start = nLast != null ? Math.max(0, weeklyPay.length - nLast) : 0;
        var W = weeklyPay.slice(start);
        var L = W.map(function (w) {
          return w.weekStart;
        });
        var wdata = W.map(function (w) {
          return w.volumeEur;
        });
        return {
          type: 'bar',
          data: {
            labels: L,
            datasets: [
              {
                type: 'bar',
                label: 'Volume payment (≈ €)',
                data: wdata,
                yAxisID: 'y',
                backgroundColor: pal.weeklyBarFill,
                borderColor: pal.weeklyBarStroke,
                borderWidth: 1,
                order: 1,
              },
              {
                type: 'line',
                label: 'Moy. € / compte distinct (semaine)',
                data: W.map(function (w) {
                  return w.avgPerAccountEur;
                }),
                yAxisID: 'y1',
                borderColor: pal.weeklyLineAccount,
                backgroundColor: 'transparent',
                borderWidth: 2,
                pointRadius: 3,
                tension: 0.15,
                order: 4,
              },
            ],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
              legend: { display: false },
              tooltip: {
                callbacks: {
                  afterBody: function (items) {
                    if (!items.length) return '';
                    var idx = items[0].dataIndex;
                    if (idx < 0 || !W[idx]) return '';
                    var row = W[idx];
                    return row.nDistinctPayers != null
                      ? 'Semaine : ' + row.nPay + ' tx · ' + row.nDistinctPayers + ' comptes · moy. ' + fmtEur(row.avgPerAccountEur) + ' / compte'
                      : '';
                  },
                  label: function (ctx) {
                    var v = ctx.parsed.y;
                    if (v == null) return ctx.dataset.label;
                    return ctx.dataset.label + ' : ' + fmtEur(v);
                  },
                },
              },
            },
            scales: {
              x: { ticks: { maxRotation: 45, minRotation: 0 } },
              y: {
                position: 'left',
                beginAtZero: true,
                ticks: { callback: function (val) { return fmtEurAxis(val); } },
              },
              y1: {
                position: 'right',
                beginAtZero: true,
                grid: { drawOnChartArea: false },
                ticks: { callback: function (val) { return fmtEurAxis(val); } },
              },
            },
          },
        };
      }

      if (elW) {
        var chWeekly = monitorNewChart(elW, makeWeeklyVolumeCfg(MONITOR_PREVIEW_POINTS));
        ensureChartHorizontalScroll(chWeekly.canvas, weeklyPay.length, {
          minLabels: MONITOR_SCROLL_SUPPRESS_MIN,
        });
        if (wrapW) {
          monitorBindChartExpand(
            wrapW,
            'Paiements : volume par semaine + moy. € / compte',
            makeWeeklyVolumeCfg,
            'weekly'
          );
        }
      }

      var elActW = document.getElementById('chartPaymentWeeklyActivity');
      var wrapActW = elActW && elActW.parentElement;
      function makeWeeklyActivityCfg(nLast) {
        var start = nLast != null ? Math.max(0, weeklyPay.length - nLast) : 0;
        var W = weeklyPay.slice(start);
        var L = W.map(function (w) {
          return w.weekStart;
        });
        return {
          type: 'line',
          data: {
            labels: L,
            datasets: [
              {
                label: 'Comptes distincts (payment)',
                data: W.map(function (w) {
                  return w.nDistinctPayers != null ? w.nDistinctPayers : 0;
                }),
                borderColor: pal.weeklyActivityCp,
                backgroundColor: 'transparent',
                borderWidth: 2,
                pointRadius: 3,
                tension: 0.12,
                order: 2,
              },
              {
                label: 'Lignes payment (tx)',
                data: W.map(function (w) {
                  return w.nPay != null ? w.nPay : 0;
                }),
                borderColor: pal.weeklyActivityTx,
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [4, 3],
                pointRadius: 2,
                tension: 0.12,
                order: 1,
              },
            ],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
              legend: { display: false },
              tooltip: {
                callbacks: {
                  label: function (ctx) {
                    var v = ctx.parsed.y;
                    if (v == null) return ctx.dataset.label;
                    return ctx.dataset.label + ' : ' + fmtN(v);
                  },
                },
              },
            },
            scales: {
              x: { ticks: { maxRotation: 45, minRotation: 0 } },
              y: {
                beginAtZero: true,
                ticks: {
                  callback: function (val) {
                    return fmtN(val);
                  },
                },
              },
            },
          },
        };
      }

      if (elActW) {
        var chWAct = monitorNewChart(elActW, makeWeeklyActivityCfg(MONITOR_PREVIEW_POINTS));
        ensureChartHorizontalScroll(chWAct.canvas, weeklyPay.length, {
          minLabels: MONITOR_SCROLL_SUPPRESS_MIN,
        });
        if (wrapActW) {
          monitorBindChartExpand(
            wrapActW,
            'Paiements : activité par semaine (comptes & tx)',
            makeWeeklyActivityCfg,
            'weekly'
          );
        }
      }
    }
  };

  if (document.getElementById('monitor-chart-payload')) {
    window.monitorInitCharts();
  }
})();
