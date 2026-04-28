<?php

declare(strict_types=1);

/** @var string $counterparty */
/** @var list<array<string, mixed>> $topCounterparties */
/** @var list<array<string, mixed>> $teamWalletActivity */
/** @var array<string, bool> $teamWalletMap */
/** @var list<array<string, mixed>> $topWalletsByToken */
/** @var array<string, mixed> $paging */
/** @var string $dateFrom */
/** @var string $dateTo */
/** @var string $counterparty */
/** @var callable(string): string $cpDashboardHref */
?>
<?php
$dateFrom = isset($dateFrom) ? (string) $dateFrom : '';
$dateTo = isset($dateTo) ? (string) $dateTo : '';
$counterparty = isset($counterparty) ? (string) $counterparty : '';
$teamWalletMap = isset($teamWalletMap) && is_array($teamWalletMap) ? $teamWalletMap : [];
$isTeamWallet = static function (string $addr) use ($teamWalletMap): bool {
    $a = strtolower(trim($addr));
    return $a !== '' && isset($teamWalletMap[$a]);
};

$walletsPageLink = static function (int $pa, int $pw) use ($dateFrom, $dateTo, $counterparty): string {
    return 'wallets.php?' . http_build_query([
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'counterparty' => $counterparty,
        'pa' => max(1, $pa),
        'pw' => max(1, $pw),
    ]);
};
$transfersPageHref = 'transfers.php?' . http_build_query([
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'counterparty' => $counterparty,
]);
$pa = (int) (($paging['activity']['page'] ?? 1));
$pw = (int) (($paging['wallets']['page'] ?? 1));
$tpa = (int) (($paging['activity']['total'] ?? 0));
$tpw = (int) (($paging['wallets']['total'] ?? 0));
$pp = (int) (($paging['activity']['perPage'] ?? 50));
?>
<?php if ($counterparty === '') : ?>
<details class="panel panel-details" open>
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
            $isTeam = $isTeamWallet($cpAdr);
        ?>
        <tr>
          <td class="mono cp-cell" style="font-size:0.82rem">
            <a href="<?= htmlspecialchars($cpDashboardHref($cpAdr)) ?>" title="Filtrer le tableau de bord sur ce portefeuille" style="<?= $isTeam ? 'color:#b91c1c;font-weight:700;' : '' ?>"><?= htmlspecialchars(substr($cpAdr, 0, 12)) ?>…</a>
            <span class="cp-actions">
              <button type="button" class="btn-copy btn-copy--sm" data-copy="<?= htmlspecialchars($cpAdr) ?>" data-copy-label="Copier" title="Copier l’adresse complète">Copier</button>
              <a href="https://etherscan.io/address/<?= htmlspecialchars($cpAdr) ?>" target="_blank" rel="noopener" class="muted" style="font-size:0.75rem">Etherscan</a>
            </span>
            <?php if ($isTeam) : ?><span class="muted" style="display:block;color:#b91c1c;font-size:0.75rem">TEAM</span><?php endif; ?>
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
  <?php if ($tpa > $pp) : ?>
  <p class="muted">Page <?= htmlspecialchars(fmt_int_fr($pa)) ?> / <?= htmlspecialchars(fmt_int_fr((int) ceil($tpa / $pp))) ?> · <?= htmlspecialchars(fmt_int_fr($tpa)) ?> lignes</p>
  <p>
    <?php if ($pa > 1) : ?><a href="<?= htmlspecialchars($walletsPageLink($pa - 1, $pw)) ?>">← Précédent</a><?php endif; ?>
    <?php if ($pa > 1 && ($pa * $pp) < $tpa) : ?> · <?php endif; ?>
    <?php if (($pa * $pp) < $tpa) : ?><a href="<?= htmlspecialchars($walletsPageLink($pa + 1, $pw)) ?>">Suivant →</a><?php endif; ?>
  </p>
  <?php endif; ?>
</details>

<details class="panel panel-details" open>
  <summary class="panel-details__summary">Wallet team - activité (first_seen &lt; 11/03/2026)</summary>
  <p class="muted panel-details__intro">Tri décroissant par <strong>approx token</strong> (top_up − payment) sur la période.</p>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Wallet</th>
          <th># IN</th>
          <th># OUT</th>
          <th>Total</th>
          <th>Vol. IN (≈ €)</th>
          <th>Vol. OUT (≈ €)</th>
          <th>Approx token (≈ €)</th>
          <th>Premier / dernier</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($teamWalletActivity as $tr) : ?>
        <?php
            $cpAdr = (string) $tr['cp'];
            $ni = (int) $tr['n_in'];
            $no = (int) $tr['n_out'];
            $hint = monitor_cp_row_hint($ni, $no);
            $isTeam = $isTeamWallet($cpAdr);
        ?>
        <tr>
          <td class="mono cp-cell" style="font-size:0.82rem">
            <a href="<?= htmlspecialchars($cpDashboardHref($cpAdr)) ?>" title="Filtrer le tableau de bord sur ce portefeuille" style="<?= $isTeam ? 'color:#b91c1c;font-weight:700;' : '' ?>"><?= htmlspecialchars(substr($cpAdr, 0, 12)) ?>…</a>
            <span class="cp-actions">
              <button type="button" class="btn-copy btn-copy--sm" data-copy="<?= htmlspecialchars($cpAdr) ?>" data-copy-label="Copier" title="Copier l’adresse complète">Copier</button>
              <a href="https://etherscan.io/address/<?= htmlspecialchars($cpAdr) ?>" target="_blank" rel="noopener" class="muted" style="font-size:0.75rem">Etherscan</a>
            </span>
            <?php if ($isTeam) : ?><span class="muted" style="display:block;color:#b91c1c;font-size:0.75rem">TEAM</span><?php endif; ?>
          </td>
          <td><?= htmlspecialchars(fmt_int_fr($ni)) ?></td>
          <td><?= htmlspecialchars(fmt_int_fr($no)) ?></td>
          <td><?= htmlspecialchars(fmt_int_fr((int) $tr['n_total'])) ?></td>
          <td><?= htmlspecialchars(fmt_eur((string) ($tr['sum_in_raw'] ?? '0'))) ?></td>
          <td><?= htmlspecialchars(fmt_eur((string) ($tr['sum_out_raw'] ?? '0'))) ?></td>
          <td><?= htmlspecialchars(fmt_eur_signed_raw((string) ($tr['wallet_tokens_raw'] ?? '0'))) ?></td>
          <td class="muted" style="font-size:0.82rem;white-space:nowrap">
            <?= htmlspecialchars(substr((string) $tr['first_seen'], 0, 10)) ?>
            → <?= htmlspecialchars(substr((string) $tr['last_seen'], 0, 10)) ?>
          </td>
          <td class="muted" style="font-size:0.8rem"><?= $hint !== '' ? htmlspecialchars($hint) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$teamWalletActivity) : ?>
        <tr><td colspan="9" class="muted">Aucun wallet team détecté sur cette période / filtre.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</details>

<details class="panel panel-details" open>
  <summary class="panel-details__summary">Plus gros wallets (approx token, top_up − payment)</summary>
  <p class="muted panel-details__intro">
    Classement décroissant de <strong>Top-up − Payment</strong> (heuristique v1), sur la période filtrée.
  </p>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Wallet</th>
          <th>Approx token (≈ €)</th>
          <th>Top-up</th>
          <th>Payment</th>
          <th># payment</th>
          <th># top_up</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($topWalletsByToken as $tr) : ?>
        <?php
            $cpAdr = (string) ($tr['cp'] ?? '');
            $walletRaw = (string) ($tr['wallet_tokens_raw'] ?? '0');
            $isTeam = $isTeamWallet($cpAdr);
        ?>
        <tr>
          <td class="mono cp-cell" style="font-size:0.82rem">
            <a href="<?= htmlspecialchars($cpDashboardHref($cpAdr)) ?>" title="Filtrer le tableau de bord sur ce portefeuille" style="<?= $isTeam ? 'color:#b91c1c;font-weight:700;' : '' ?>"><?= htmlspecialchars(substr($cpAdr, 0, 12)) ?>…</a>
            <span class="cp-actions">
              <button type="button" class="btn-copy btn-copy--sm" data-copy="<?= htmlspecialchars($cpAdr) ?>" data-copy-label="Copier" title="Copier l’adresse complète">Copier</button>
              <a href="https://etherscan.io/address/<?= htmlspecialchars($cpAdr) ?>" target="_blank" rel="noopener" class="muted" style="font-size:0.75rem">Etherscan</a>
            </span>
            <?php if ($isTeam) : ?><span class="muted" style="display:block;color:#b91c1c;font-size:0.75rem">TEAM</span><?php endif; ?>
          </td>
          <td><?= htmlspecialchars(fmt_eur_signed_raw($walletRaw)) ?></td>
          <td><?= htmlspecialchars(fmt_eur((string) ($tr['sum_topup_raw'] ?? '0'))) ?></td>
          <td><?= htmlspecialchars(fmt_eur((string) ($tr['sum_payment_raw'] ?? '0'))) ?></td>
          <td><?= htmlspecialchars(fmt_int_fr((int) ($tr['n_payment'] ?? 0))) ?></td>
          <td><?= htmlspecialchars(fmt_int_fr((int) ($tr['n_topup'] ?? 0))) ?></td>
          <td class="muted" style="font-size:0.8rem"> </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$topWalletsByToken) : ?>
        <tr><td colspan="7" class="muted">Aucune donnée sur cette période / filtre.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($tpw > $pp) : ?>
  <p class="muted">Page <?= htmlspecialchars(fmt_int_fr($pw)) ?> / <?= htmlspecialchars(fmt_int_fr((int) ceil($tpw / $pp))) ?> · <?= htmlspecialchars(fmt_int_fr($tpw)) ?> lignes</p>
  <p>
    <?php if ($pw > 1) : ?><a href="<?= htmlspecialchars($walletsPageLink($pa, $pw - 1)) ?>">← Précédent</a><?php endif; ?>
    <?php if ($pw > 1 && ($pw * $pp) < $tpw) : ?> · <?php endif; ?>
    <?php if (($pw * $pp) < $tpw) : ?><a href="<?= htmlspecialchars($walletsPageLink($pa, $pw + 1)) ?>">Suivant →</a><?php endif; ?>
  </p>
  <?php endif; ?>
</details>
<?php endif; ?>

<details class="panel panel-details">
  <summary class="panel-details__summary">Derniers transferts</summary>
  <p class="muted panel-details__intro">
    La liste chronologique est sur une <strong>page dédiée</strong> : la requête est lourde sur de gros volumes ; les autres tableaux ci-dessus se chargent plus vite.
  </p>
  <p style="margin:0">
    <a class="view-switch__link" href="<?= htmlspecialchars($transfersPageHref) ?>" style="display:inline-block;margin-top:0.35rem">Ouvrir les derniers transferts →</a>
  </p>
</details>
