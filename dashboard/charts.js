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
    if (!canvas) return;
    var inner = canvas.parentElement;
    if (!inner) return;

    var isMini = inner.classList && inner.classList.contains('vault-mini-chart');
    var pxPerLabel = options.pxPerLabel != null ? options.pxPerLabel : isMini ? 22 : 36;
    var maxW = options.maxWidth != null ? options.maxWidth : isMini ? 3200 : 9600;
    var minN = options.minLabels != null ? options.minLabels : 5;

    if (!labelCount || labelCount < minN) {
      inner.style.minWidth = '';
      return;
    }

    var scrollEl = inner.parentElement;
    if (!scrollEl || !scrollEl.classList || !scrollEl.classList.contains('chart-scroll')) {
      scrollEl = document.createElement('div');
      scrollEl.className = 'chart-scroll';
      inner.parentNode.insertBefore(scrollEl, inner);
      scrollEl.appendChild(inner);
    }

    var viewport = scrollEl.clientWidth || document.documentElement.clientWidth || 360;
    var targetW = Math.min(maxW, Math.max(viewport, labelCount * pxPerLabel));
    inner.style.minWidth = targetW + 'px';
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
    var weeklyMeta = payload.weeklyMeta || {};
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

    destroyChartsOnCanvases();

    if (daily.length) {
      var labels = daily.map(function (d) {
        return d.day;
      });

      var elAct = document.getElementById('chartActiviteJour');
      if (elAct) {
        var chAct = new Chart(elAct, {
          type: 'bar',
          data: {
            labels: labels,
            datasets: [
              {
                label: 'Transferts',
                data: daily.map(function (d) {
                  return d.n;
                }),
                backgroundColor: 'rgba(37, 99, 235, 0.55)',
                borderColor: 'rgba(37, 99, 235, 0.9)',
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
        var chNodeVol = new Chart(elNodeVol, {
          type: 'line',
          data: {
            labels: labels,
            datasets: [
              {
                label: 'Volume total (≈ € / jour)',
                data: nodeVolumeDaily.map(function (d) {
                  return d.volumeEur;
                }),
                borderColor: '#0d9488',
                backgroundColor: 'rgba(13, 148, 136, 0.15)',
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
              legend: { position: 'top' },
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
        ensureChartHorizontalScroll(chNodeVol.canvas, labels.length, {});
      }

      var elInt = document.getElementById('chartInterestJour');
      if (elInt) {
        var chInt = new Chart(elInt, {
          type: 'bar',
          data: {
            labels: labels,
            datasets: [
              {
                label: 'Interest v1 (≈ € / jour)',
                data: interestDaily.map(function (d) {
                  return d.interestEur;
                }),
                backgroundColor: 'rgba(34, 197, 94, 0.5)',
                borderColor: 'rgba(22, 163, 74, 0.95)',
                borderWidth: 1,
              },
            ],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: { position: 'top' },
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
        ensureChartHorizontalScroll(chInt.canvas, labels.length, {});
      }

      var elPayTopupCombined = document.getElementById('chartPaymentTopupCombined');
      if (elPayTopupCombined && dailyClass && dailyClass.length) {
        var chPayTop = new Chart(elPayTopupCombined, {
          type: 'bar',
          data: {
            labels: labels,
            datasets: [
              {
                type: 'line',
                yAxisID: 'yVolume',
                label: 'Payment (≈ €)',
                data: dailyClass.map(function (d) {
                  return d.payment;
                }),
                borderColor: '#7c3aed',
                backgroundColor: 'rgba(124, 58, 237, 0.12)',
                fill: true,
                tension: 0.2,
                pointRadius: 2,
              },
              {
                type: 'line',
                yAxisID: 'yVolume',
                label: 'Top up (≈ €)',
                data: dailyClass.map(function (d) {
                  return d.top_up;
                }),
                borderColor: '#ea580c',
                backgroundColor: 'rgba(234, 88, 12, 0.12)',
                fill: true,
                tension: 0.2,
                pointRadius: 2,
              },
              {
                type: 'bar',
                yAxisID: 'yCount',
                label: 'Payment (#)',
                data: dailyClass.map(function (d) {
                  return d.nPayment;
                }),
                backgroundColor: 'rgba(124, 58, 237, 0.55)',
                borderColor: 'rgba(124, 58, 237, 0.95)',
                borderWidth: 1,
              },
              {
                type: 'bar',
                yAxisID: 'yCount',
                label: 'Top up (#)',
                data: dailyClass.map(function (d) {
                  return d.nTopUp;
                }),
                backgroundColor: 'rgba(234, 88, 12, 0.55)',
                borderColor: 'rgba(234, 88, 12, 0.95)',
                borderWidth: 1,
              },
            ],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
              legend: { position: 'top' },
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
                ticks: { callback: function (val) { return fmtEurAxis(val); } },
              },
              yCount: {
                position: 'right',
                beginAtZero: true,
                grid: { drawOnChartArea: false },
                ticks: { callback: function (val) { return fmtN(val); } },
              },
            },
          },
        });
        ensureChartHorizontalScroll(chPayTop.canvas, labels.length, {});
      }

      // Petite courbe net (Top-up - Payment), cumulée dans le temps, dans la carte du haut.
      var elVault = document.getElementById('chartVaultDaily');
      if (elVault && vaultDaily && vaultDaily.length) {
        var vaultWeekly = weeklyFromCumulativeDaily(vaultDaily, 'vaultEur');
        var vaultTickIdx = pickTickIndices(vaultWeekly.length, 8);
        var chVault = new Chart(elVault, {
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
                borderColor: 'rgba(37, 99, 235, 0.95)',
                backgroundColor: 'rgba(37, 99, 235, 0.12)',
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
        var chVaultD = new Chart(elVaultDelta, {
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
                borderColor: 'rgba(234, 88, 12, 0.95)',
                backgroundColor: 'rgba(234, 88, 12, 0.10)',
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
        var chAvgPay = new Chart(elAvgPay, {
          type: 'line',
          data: {
            labels: labels,
            datasets: [
              {
                label: 'Ticket moyen payment (≈ €)',
                data: paymentAvgDaily.map(function (d) {
                  return d.avgTicketEur;
                }),
                borderColor: '#6366f1',
                backgroundColor: 'rgba(99, 102, 241, 0.14)',
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
              legend: { position: 'top' },
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
        ensureChartHorizontalScroll(chAvgPay.canvas, labels.length, {});
      }

      var elFluxNet = document.getElementById('chartFluxNetDaily');
      if (elFluxNet && vaultDeltaDaily && vaultDeltaDaily.length) {
        var chFluxNet = new Chart(elFluxNet, {
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
                backgroundColor: 'rgba(234, 88, 12, 0.45)',
                borderColor: 'rgba(234, 88, 12, 0.95)',
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
        var chPayCnt = new Chart(elPayTopupCount, {
          type: 'bar',
          data: {
            labels: labels,
            datasets: [
              {
                label: 'Payment',
                data: dailyClass.map(function (d) {
                  return d.nPayment;
                }),
                backgroundColor: 'rgba(124, 58, 237, 0.55)',
                borderColor: 'rgba(124, 58, 237, 0.95)',
                borderWidth: 1,
              },
              {
                label: 'Top up',
                data: dailyClass.map(function (d) {
                  return d.nTopUp;
                }),
                backgroundColor: 'rgba(234, 88, 12, 0.55)',
                borderColor: 'rgba(234, 88, 12, 0.95)',
                borderWidth: 1,
              },
            ],
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
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
        ensureChartHorizontalScroll(chPayCnt.canvas, labels.length, {});
      }
    }

    if (gasDaily && gasDaily.length) {
      var elGas = document.getElementById('chartGasDaily');
      if (elGas) {
        var gasWeekly = weeklyFromCumulativeDaily(gasDaily, 'gasEth');
        var gasTickIdx = pickTickIndices(gasWeekly.length, 8);
        var chGas = new Chart(elGas, {
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
                borderColor: '#0d9488',
                backgroundColor: 'rgba(13, 148, 136, 0.12)',
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
      var avgA = weeklyMeta.avgActiveEur;
      var avgC = weeklyMeta.avgCalWeekEur;
      var elW = document.getElementById('chartPaymentWeekly');
      if (elW) {
        var chWeekly = new Chart(elW, {
          type: 'bar',
          data: {
            labels: wlabels,
            datasets: [
              {
                type: 'bar',
                label: 'Volume payment (≈ €)',
                data: wdata,
                backgroundColor: 'rgba(124, 58, 237, 0.55)',
                borderColor: 'rgba(124, 58, 237, 0.95)',
                borderWidth: 1,
                order: 3,
              },
              {
                type: 'line',
                label: 'Moy. semaines avec payment',
                data: wlabels.map(function () {
                  return avgA;
                }),
                borderColor: '#0d9488',
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [6, 4],
                pointRadius: 0,
                tension: 0,
                order: 1,
              },
              {
                type: 'line',
                label: 'Moy. / sem. (plage dates)',
                data: wlabels.map(function () {
                  return avgC;
                }),
                borderColor: '#ca8a04',
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [2, 3],
                pointRadius: 0,
                tension: 0,
                order: 2,
              },
              {
                type: 'line',
                label: 'Moy. / compte distinct (cette semaine)',
                data: weeklyPay.map(function (w) {
                  return w.avgPerAccountEur;
                }),
                borderColor: '#4f46e5',
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
              legend: { position: 'top' },
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
                beginAtZero: true,
                ticks: { callback: function (val) { return fmtEurAxis(val); } },
              },
            },
          },
        });
        ensureChartHorizontalScroll(chWeekly.canvas, wlabels.length, { pxPerLabel: 40, maxWidth: 8000 });
      }
    }
  };

  if (document.getElementById('monitor-chart-payload')) {
    window.monitorInitCharts();
  }
})();
