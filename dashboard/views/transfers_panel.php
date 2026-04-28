<?php

declare(strict_types=1);

/** @var list<array<string, mixed>> $rows */
/** @var array<string, bool> $teamWalletMap */
/** @var int $recentTransfersLimit */
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

$transfersPageLink = static function (int $pt) use ($dateFrom, $dateTo, $counterparty): string {
    return 'transfers.php?' . http_build_query([
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'counterparty' => $counterparty,
        'pt' => max(1, $pt),
    ]);
};
$walletsBackUrl = 'wallets.php?' . http_build_query([
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'counterparty' => $counterparty,
]);
$pt = (int) (($paging['transfers']['page'] ?? 1));
$tpt = (int) (($paging['transfers']['total'] ?? 0));
$pp = (int) (($paging['transfers']['perPage'] ?? 50));
?>
<details class="panel panel-details" open>
  <summary class="panel-details__summary">Derniers transferts</summary>
  <p class="muted panel-details__intro">
    Même périmètre que les totaux : <strong>sans</strong> lignes mint/burn <code>0x0</code>.
    Page dédiée : la requête peut prendre du temps sur de gros volumes.
  </p>
  <p class="muted" style="margin-top:0">
    <a href="<?= htmlspecialchars($walletsBackUrl) ?>">← Retour aux wallets</a>
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
            $isTeam = $rowCpOk ? $isTeamWallet($rowCp) : false;
        ?>
        <tr>
          <td><?= htmlspecialchars((string) $r['block_time']) ?></td>
          <td><?= htmlspecialchars((string) $r['direction']) ?></td>
          <td><?= htmlspecialchars((string) ($r['event_type'] ?? '')) ?></td>
          <td class="mono cp-cell" title="<?= htmlspecialchars($rowCp) ?>">
            <?php if ($rowCpOk) : ?>
            <a href="<?= htmlspecialchars($cpDashboardHref($rowCp)) ?>" title="Filtrer sur ce portefeuille" style="<?= $isTeam ? 'color:#b91c1c;font-weight:700;' : '' ?>"><?= htmlspecialchars(substr($rowCp, 0, 10)) ?>…</a>
            <button type="button" class="btn-copy btn-copy--sm" data-copy="<?= htmlspecialchars($rowCp) ?>" data-copy-label="Copier" title="Copier l’adresse">Copier</button>
            <?php if ($isTeam) : ?><span class="muted" style="display:block;color:#b91c1c;font-size:0.75rem">TEAM</span><?php endif; ?>
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
        <?php if (!$rows) : ?>
        <tr><td colspan="8" class="muted">Aucun transfert sur cette période / filtre.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($tpt > $pp) : ?>
  <p class="muted">Page <?= htmlspecialchars(fmt_int_fr($pt)) ?> / <?= htmlspecialchars(fmt_int_fr((int) ceil($tpt / $pp))) ?> · <?= htmlspecialchars(fmt_int_fr($tpt)) ?> lignes</p>
  <p>
    <?php if ($pt > 1) : ?><a href="<?= htmlspecialchars($transfersPageLink($pt - 1)) ?>">← Précédent</a><?php endif; ?>
    <?php if ($pt > 1 && ($pt * $pp) < $tpt) : ?> · <?php endif; ?>
    <?php if (($pt * $pp) < $tpt) : ?><a href="<?= htmlspecialchars($transfersPageLink($pt + 1)) ?>">Suivant →</a><?php endif; ?>
  </p>
  <?php endif; ?>
</details>
