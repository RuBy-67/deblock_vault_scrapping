<?php

declare(strict_types=1);

/** @var array<string, mixed> $qualityStats */
/** @var list<array<string, mixed>> $qualityByType */
/** @var list<array<string, mixed>> $qualityConfidenceBuckets */
?>
<section class="cards cards--top">
  <div class="card">
    <h3>Lignes classifiées</h3>
    <p class="metric-one-line"><strong><?= htmlspecialchars(fmt_int_fr((int) ($qualityStats['total_classified'] ?? 0))) ?></strong></p>
  </div>
  <div class="card">
    <h3>Interest</h3>
    <p class="metric-one-line"><strong><?= htmlspecialchars(fmt_int_fr((int) ($qualityStats['n_interest'] ?? 0))) ?></strong></p>
    <p class="muted">dont appariées: <?= htmlspecialchars(fmt_int_fr((int) ($qualityStats['n_interest_paired'] ?? 0))) ?></p>
  </div>
  <div class="card">
    <h3>Unknown</h3>
    <p class="metric-one-line"><strong><?= htmlspecialchars(fmt_int_fr((int) ($qualityStats['n_unknown'] ?? 0))) ?></strong></p>
  </div>
</section>

<section class="panel">
  <h2>Répartition par type</h2>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Type</th><th>Nombre</th><th>Dont paired_transfer_id</th></tr>
      </thead>
      <tbody>
        <?php foreach ($qualityByType as $r) : ?>
        <tr>
          <td><?= htmlspecialchars((string) ($r['event_type'] ?? '')) ?></td>
          <td><?= htmlspecialchars(fmt_int_fr((int) ($r['n'] ?? 0))) ?></td>
          <td><?= htmlspecialchars(fmt_int_fr((int) ($r['n_paired'] ?? 0))) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel">
  <h2>Distribution de confiance</h2>
  <p class="muted panel-details__intro">
    Chaque ligne classifiée a un score <strong>0–100</strong> en base. Le tableau ci-dessous regroupe ces scores par <strong>tranches</strong> (90–100, 75–89, etc.) sur la période filtrée.
    Ce n’est <strong>pas</strong> un modèle ML : la règle actuelle (v1) n’utilise que <strong>deux valeurs fixes</strong>.
  </p>
  <details class="panel-details">
    <summary class="panel-details__summary">Comment ce score est-il choisi ?</summary>
    <div class="muted panel-details__intro">
      <p><strong>85</strong> -Transferts classés en <strong>interest</strong> avec une jambe jumelle : aller-retour strict même portefeuille (vers le nœud puis retour), dans la fenêtre de temps configurée, montants sous le plafond lié à la « taille » approximative du wallet, au plus <strong>une</strong> paire interest par wallet et par jour calendaire. Les deux jambes ont le même score.</p>
      <p><strong>60</strong> -<strong>payment</strong> (entrant) ou <strong>top_up</strong> (sortant) lorsqu’aucune paire interest n’a été retenue : classification par défaut à partir du sens du flux seul. Confiance plus basse car la règle est moins contraignante.</p>
      <p>Avec la v1, on s’attend surtout aux buckets <strong>75–89</strong> (85) et <strong>60–74</strong> (60) ; les autres tranches restent vides sauf données historiques ou évolution des règles.</p>
    </div>
  </details>
  <div class="table-wrap">
    <table>
      <thead>
        <tr><th>Tranche</th><th>Nombre</th></tr>
      </thead>
      <tbody>
        <?php foreach ($qualityConfidenceBuckets as $r) : ?>
        <tr>
          <td><?= htmlspecialchars((string) ($r['bucket'] ?? '')) ?></td>
          <td><?= htmlspecialchars(fmt_int_fr((int) ($r['n'] ?? 0))) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
