/**
 * Graphiques Chart.js — données injectées via #monitor-chart-payload (JSON).
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

    var fmtEur = function (v) {
      return new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0, minimumFractionDigits: 0 }).format(v) + '\u202F€';
    };
    var fmtEurAxis = function (v) {
      return new Intl.NumberFormat('fr-FR', { notation: 'compact', compactDisplay: 'short', maximumFractionDigits: 1 }).format(v) + '\u202F€';
    };
    var fmtN = function (v) {
      return new Intl.NumberFormat('fr-FR').format(v);
    };

    destroyChartsOnCanvases();

    if (daily.length) {
      var labels = daily.map(function (d) {
        return d.day;
      });

      new Chart(document.getElementById('chartActiviteJour'), {
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

      new Chart(document.getElementById('chartNodeVolumeJour'), {
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

      new Chart(document.getElementById('chartInterestJour'), {
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

      new Chart(document.getElementById('chartPaymentTopupVolume'), {
        type: 'line',
        data: {
          labels: labels,
          datasets: [
            {
              label: 'Payment',
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
              label: 'Top up',
              data: dailyClass.map(function (d) {
                return d.top_up;
              }),
              borderColor: '#ea580c',
              backgroundColor: 'rgba(234, 88, 12, 0.12)',
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
              ticks: {
                callback: function (val) {
                  return fmtEurAxis(val);
                },
              },
            },
          },
        },
      });

      new Chart(document.getElementById('chartPaymentAvgDaily'), {
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

      new Chart(document.getElementById('chartPaymentTopupCount'), {
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
      var avgDistinctPeriod = weeklyMeta.avgPerDistinctAccountPeriodEur;
      var elW = document.getElementById('chartPaymentWeekly');
      if (elW) {
        new Chart(elW, {
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
              {
                type: 'line',
                label: 'Réf. moy. / compte (toute la période)',
                data: wlabels.map(function () {
                  return avgDistinctPeriod;
                }),
                borderColor: '#c026d3',
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [1, 4],
                pointRadius: 0,
                tension: 0,
                order: 5,
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
      }
    }
  };

  if (document.getElementById('monitor-chart-payload')) {
    window.monitorInitCharts();
  }
})();
