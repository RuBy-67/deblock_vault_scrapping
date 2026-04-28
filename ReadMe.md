# Deblock Vault — ingestion chaîne → MySQL & dashboard

Démo / prod interne : [dashboard](https://deblock-vault.rb-rubydev.fr/dashboard/)

## Schéma base de données

Un seul script d’initialisation : **`sql/schema.sql`**.

- Tables **opérationnelles** : `sync_state`, `raw_transfers`, `tx_gas`, `classified_events`, `wallet_estimates` (optionnel).
- Tables **tampon / métriques** (pré-calculées par des workers Node) :
  - **`daily_metrics`** — agrégats par jour (flux, types d’événements, gas).
  - **`classification_daily`** / **`classification_confidence_daily`** — volumes par type et par bucket de confiance (page **Quality**).
  - **`wallet_peer_daily`** — par jour et par « peer » (contrepartie brute in/out + sommes payment/top_up).
  - **`wallet_holding_daily`** — par jour et par wallet (`ce.counterparty`) pour payment/top_up.

Importer le script dans la base (phpMyAdmin ou `mysql`), puis configurer **`.env`** à partir de **`.env.example`**.

## Workers Node (`worker/`)

Les commandes lisent le **`.env` à la racine** du projet.

| Commande | Fichier | Rôle |
|----------|---------|------|
| `npm run sync` | `sync.mjs` | RPC : logs `Transfer` du **`TOKEN_CONTRACT`** où **`NODE_ADDRESS`** est `from` ou `to` → **`raw_transfers`** ; receipt → **`tx_gas`** (une ligne par `tx_hash`). |
| `npm run classify` | `classify.mjs` | Heuristique métier → **`classified_events`** (intérêts, paiements, top-up, etc.). Voir en-tête du fichier et variables `PAIR_*`, `INTEREST_*`, `CLASSIFY_FULL_REBUILD` dans `.env.example`. |
| `npm run pipeline` | — | Enchaîne `sync` puis `classify`. |
| `npm run daily-metrics` | `build_daily_metrics.mjs` | Met à jour **`daily_metrics`**, **`classification_daily`**, **`classification_confidence_daily`** (incrémental par défaut ; voir `.env.example`). |
| `npm run wallet-metrics` | `build_wallet_metrics.mjs` | Met à jour **`wallet_peer_daily`** et **`wallet_holding_daily`** (même logique incrémentale / `--full`). |

**Ordre recommandé après des nouvelles données brutes ou un gros `classify` :**

```bash
npm run daily-metrics && npm run wallet-metrics
```

- **Incrémental** : par défaut, seuls les jours récents sont supprimés puis réagrégés (fenêtre = dernier jour en table moins `METRICS_INCREMENTAL_OVERLAP_DAYS`, voir `.env.example`). Le cron reste court si la base grossit.
- **Rebuild complet** : `METRICS_FULL_REBUILD=1` ou argument `--full` sur les scripts Node (obligatoire après un `CLASSIFY_FULL_REBUILD` sur d’anciens blocs, sinon les agrégats d’histoire resteraient faux).

Les pages PHP utilisent ces tables quand elles existent et sont **à jour** (dernier jour indexé ≥ dernier jour des transferts) ; sinon elles retombent sur des requêtes directes sur `raw_transfers` (plus lentes).

## Pipeline résumé

1. **Sync** : vérité on-chain dans `raw_transfers` ; pas de doublon grâce à `UNIQUE (tx_hash, log_index)` et **`INSERT IGNORE`** (relancer le sync est idempotent).
2. **Classify** : une ligne `classified_events` par transfert classifié (règles versionnées, paires d’intérêts, etc.).
3. **Métriques** : reconstruction périodique des tables d’agrégats pour alléger le dashboard.

## Curseur de synchronisation (`sync_state`)

- Clé `main` : **`last_block_scanned`** ; le prochain run commence à `last_block + 1`.
- **`START_BLOCK`** : ne remonte le curseur que s’il est *en retard* par rapport à cette valeur (voir commentaires dans le README historique / `.env.example`).
- **Backfill** : `UPDATE sync_state SET last_block_scanned = …` puis relancer `npm run sync`.

## `amount_raw`

Montant du `Transfer` en **plus petite unité** du jeton (`uint256`), stocké en **chaîne** pour éviter toute perte de précision. L’affichage « ≈ € » du dashboard est une **conversion d’affichage**, pas une donnée on-chain supplémentaire.

## Gas ETH (`tx_gas`)

- **Une transaction = une ligne** : coût total de la tx (`gasUsed × effectiveGasPrice`, + blob si EIP-4844 côté worker).
- Le dashboard joint ce coût aux lignes `raw_transfers` par `tx_hash` ; les **totaux** agrègent `tx_gas` une seule fois par transaction présente dans le périmètre filtré.

## Dashboard PHP (`dashboard/`)

Interface découpée en **pages dédiées** (chargement différé des blocs lourds via endpoints `defer_*.php`) :

- **`index.php`** — vue principale, KPIs, graphiques (Chart.js).
- **`wallets.php`** — adresses actives, wallets team, gros wallets (accéléré par `wallet_*_daily` + `daily_metrics` si disponibles).
- **`transfers.php`** — derniers transferts seuls (requête lourde isolée ; pagination `pt`).
- **`quality.php`** — qualité de classification (résumés / buckets ; accéléré par `classification_*` si à jour).
- **`concentration.php`** — concentration des holders, métriques type Gini / cohortes (calculs lifetime côté SQL).

Les vues **flux** et **coûts** (graphiques + KPIs) sont regroupées sur **`index.php`** ; il n’y a plus de pages séparées pour cela.

Les filtres **dates** et **contrepartie** s’appliquent selon la page ; certaines métriques globales (ex. ordre de grandeur Vault) peuvent ignorer les dates pour refléter la vision « toute la période ingérée ».

## Variables d’environnement

Résumé dans **`.env.example`** : RPC, MySQL, `TOKEN_CONTRACT`, `NODE_ADDRESS`, `START_BLOCK`, `BLOCK_CHUNK`, classification (`PAIR_WINDOW_SECONDS`, plafonds intérêt par taille de wallet, `CLASSIFY_FULL_REBUILD`, `RULE_VERSION`, etc.).

## Jeu de données / export

Pour une installation neuve, partir de **`sql/schema.sql`** + pipeline workers ci-dessus.
