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
    'chartPaymentTopupCombined',
    'chartFluxNetDaily',
    'chartVaultDaily',
    'chartVaultDeltaDaily',
    'chartGasDaily',
  ];

  function destroyChartsOnCanvases() {
    if (typeof Chart === 'undefined' || !Chart.getChart) return;
    CANVAS_IDS.forEach(function (id) {
      var el = document.getElementById(id);
      if (!el) return;
      var ch = Chart.getChart(el);
      if (ch) ch.destroy();
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
        var chAct = monitorNewChart(elAct, {
          type: 'bar',
          data: {
            labels: labels,
            datasets: [
              {
                label: 'Transferts',
                data: daily.map(function (d) {
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
        });
        ensureChartHorizontalScroll(chAct.canvas, labels.length, {});
      }

      var elNodeVol = document.getElementById('chartNodeVolumeJour');
      if (elNodeVol) {
        var chNodeVol = monitorNewChart(elNodeVol, {
          type: 'line',
          data: {
            labels: labels,
            datasets: [
              {
                label: 'Volume total (≈ € / jour)',
                data: nodeVolumeDaily.map(function (d) {
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
                    if (idx >= 0 && nodeVolumeDaily[idx] && nodeVolumeDaily[idx].nTx) {
                      return nodeVolumeDaily[idx].nTx + ' transfert(s) (hors 0x0)';
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
        });
        var sNode = ensureChartHorizontalScroll(chNodeVol.canvas, labels.length, {});
        if (sNode) monitorPopulateStickyLegend(chNodeVol, sNode);
      }

      var elInt = document.getElementById('chartInterestJour');
      if (elInt) {
        var chInt = monitorNewChart(elInt, {
          type: 'bar',
          data: {
            labels: labels,
            datasets: [
              {
                label: 'Interest v1 (≈ € / jour)',
                data: interestDaily.map(function (d) {
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
                    if (idx >= 0 && interestDaily[idx]) {
                      var ni = interestDaily[idx].nInterest;
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
        });
        var sInt = ensureChartHorizontalScroll(chInt.canvas, labels.length, {});
        if (sInt) monitorPopulateStickyLegend(chInt, sInt);
      }

      var elPayTopupCombined = document.getElementById('chartPaymentTopupCombined');
      if (elPayTopupCombined && dailyClass && dailyClass.length) {
        var chPayTop = monitorNewChart(elPayTopupCombined, {
          type: 'bar',
          data: {
            labels: labels,
            datasets: [
              {
                type: 'bar',
                yAxisID: 'yCount',
                label: 'Payment (#)',
                data: dailyClass.map(function (d) {
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
                data: dailyClass.map(function (d) {
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
                data: dailyClass.map(function (d) {
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
                data: dailyClass.map(function (d) {
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
        });
        var sPayTop = ensureChartHorizontalScroll(chPayTop.canvas, labels.length, {});
        if (sPayTop) monitorPopulateStickyLegend(chPayTop, sPayTop);
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
        var chAvgPay = monitorNewChart(elAvgPay, {
          type: 'line',
          data: {
            labels: labels,
            datasets: [
              {
                label: 'Ticket moyen payment (≈ €)',
                data: paymentAvgDaily.map(function (d) {
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
                    if (idx >= 0 && paymentAvgDaily[idx] && paymentAvgDaily[idx].nPayment) {
                      return paymentAvgDaily[idx].nPayment + ' ligne(s) payment ce jour';
                    }
                    if (idx >= 0 && paymentAvgDaily[idx] && !paymentAvgDaily[idx].nPayment) {
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
        });
        var sAvg = ensureChartHorizontalScroll(chAvgPay.canvas, labels.length, {});
        if (sAvg) monitorPopulateStickyLegend(chAvgPay, sAvg);
      }

      var elFluxNet = document.getElementById('chartFluxNetDaily');
      if (elFluxNet && vaultDeltaDaily && vaultDeltaDaily.length) {
        var fluxBg = vaultDeltaDaily.map(function (d) {
          var v = d.vaultDeltaEur;
          return v < 0 ? pal.fluxNegFill : pal.c2Fill;
        });
        var fluxBd = vaultDeltaDaily.map(function (d) {
          var v = d.vaultDeltaEur;
          return v < 0 ? pal.fluxNegStroke : pal.c2Stroke;
        });
        var chFluxNet = monitorNewChart(elFluxNet, {
          type: 'bar',
          data: {
            labels: vaultDeltaDaily.map(function (d) {
              return d.day;
            }),
            datasets: [
              {
                label: 'Top-up − Payment (delta/jour) (≈ €)',
                data: vaultDeltaDaily.map(function (d) {
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
        });
        ensureChartHorizontalScroll(chFluxNet.canvas, vaultDeltaDaily.length, {});
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
      var wlabels = weeklyPay.map(function (w) {
        return w.weekStart;
      });
      var wdata = weeklyPay.map(function (w) {
        return w.volumeEur;
      });
      var elW = document.getElementById('chartPaymentWeekly');
      if (elW) {
        var chWeekly = monitorNewChart(elW, {
          type: 'bar',
          data: {
            labels: wlabels,
            datasets: [
              {
                type: 'bar',
                label: 'Volume payment (≈ €)',
                data: wdata,
                yAxisID: 'y',
                backgroundColor: pal.weeklyBarFill,
                borderColor: pal.weeklyBarStroke,
                borderWidth: 1,
                order: 2,
              },
              {
                type: 'line',
                label: 'Moy. € / compte distinct (semaine)',
                data: weeklyPay.map(function (w) {
                  return w.avgPerAccountEur;
                }),
                yAxisID: 'y1',
                borderColor: pal.weeklyLineAccount,
                backgroundColor: 'transparent',
                borderWidth: 2,
                pointRadius: 3,
                tension: 0.15,
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
                  afterBody: function (items) {
                    if (!items.length) return '';
                    var idx = items[0].dataIndex;
                    if (idx < 0 || !weeklyPay[idx]) return '';
                    var w = weeklyPay[idx];
                    return w.nDistinctPayers != null
                      ? 'Semaine : ' + w.nPay + ' tx · ' + w.nDistinctPayers + ' comptes · moy. ' + fmtEur(w.avgPerAccountEur) + ' / compte'
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
        });
        var sWeek = ensureChartHorizontalScroll(chWeekly.canvas, wlabels.length, {
          pxPerLabel: 40,
          maxWidth: 8000,
        });
        if (sWeek) monitorPopulateStickyLegend(chWeekly, sWeek);
      }
    }
  };

  if (document.getElementById('monitor-chart-payload')) {
    window.monitorInitCharts();
  }
})();
