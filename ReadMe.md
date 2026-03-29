# Ingestion des transferts (chaîne → MySQL)

## Pipeline

1. **`npm run sync`** (`worker/sync.mjs`) — lit le `.env` à la **racine** du projet.
   - Requête les logs `Transfer` du **`TOKEN_CONTRACT`** où **`NODE_ADDRESS`** est `from` ou `to`.
   - Écrit **`raw_transfers`** (`tx_hash`, `log_index`, `block_number`, `block_time`, `from_addr`, `to_addr`, **`amount_raw`**, `direction` in/out).
   - Enregistre le coût gas par tx dans **`tx_gas`** (réutilisation si plusieurs logs dans la même tx).
2. **`npm run classify`** (`worker/classify.mjs`) — heuristique métier v1 → **`classified_events`** (voir commentaires en tête de `classify.mjs`).
3. **`npm run pipeline`** — enchaîne sync puis classify.

## `amount_raw`

Valeur du `Transfer` en **plus petite unité** du jeton (`uint256` on-chain), stockée en **chaîne** en base pour éviter toute perte de précision. Avec **18 décimales**, `1` token = `1e18` dans ce champ. L’affichage « ≈ € » du dashboard est une **conversion d’affichage** (ex. jeton indexé euro), pas une donnée on-chain supplémentaire.

## Variables d’environnement (résumé)

| Variable | Rôle |
|----------|------|
| `RPC_URL` | URL HTTPS du nœud (obligatoire). |
| `RPC_URL_FALLBACK` | Optionnel : bascule si 429 / limite. |
| `RPC_429_MAX_ATTEMPTS`, `RPC_429_BASE_MS` | Optionnel : retries sur throttling (`sync.mjs`). |
| `TOKEN_CONTRACT` | Contrat ERC-20 surveillé. |
| `NODE_ADDRESS` | Adresse du « noeud » (from/to des transferts à ingérer). |
| `START_BLOCK` | Premier bloc **souhaité** pour une nouvelle installation (voir ci-dessous). |
| `BLOCK_CHUNK` | Taille max de plage de blocs par appel `getLogs` (réduire si « too many results »). |
| `CLASSIFY_FULL_REBUILD` | `true` : vide et recalcule toute la classification ; `false` : seulement les `raw_transfers` sans ligne `classified_events`. |
| `PAIR_WINDOW_SECONDS`, `INTEREST_PAIR_MAX_RAW`, `INTEREST_PAIR_MAX_FEE_BPS` | Paramètres **classification** (pas l’ingestion RPC elle-même). |

Liste détaillée et valeurs d’exemple : **`.env.example`**.

## Curseur de sync (`sync_state`)

La progression est stockée dans **`sync_state.last_block_scanned`** (clé `main`). Le prochain run scanne à partir de **`last_block_scanned + 1`**.

- **`START_BLOCK`** ne sert qu’à **relever** le curseur si celui-ci est **en retard** : si `last < START_BLOCK - 1`, il est positionné sur `START_BLOCK - 1`.  
- **Baisser `START_BLOCK` ne refait pas scanner l’ancien historique** si `last_block_scanned` est déjà plus grand.

### Rattraper des blocs manquants (backfill)

1. Ajuster **`START_BLOCK`** si besoin dans le `.env`.
2. Repositionner le curseur **manuellement** avant la zone à rescanner, par exemple :

   ```sql
   UPDATE sync_state SET last_block_scanned = 21000000 WHERE key_name = 'main';
   ```

   (utiliser le bloc **juste avant** la plage à ingérer, souvent `START_BLOCK - 1`.)

3. Relancer **`npm run sync`** jusqu’à être à jour.

### Anti-doublons

Contrainte unique **`(tx_hash, log_index)`** sur `raw_transfers`. Les insertions utilisent **`INSERT IGNORE`** : relancer le sync est **idempotent** pour les mêmes logs.

Après import de nouvelles lignes brutes, lancer **`npm run classify`** (incrémental ou `CLASSIFY_FULL_REBUILD=true` selon le besoin).

Il y a peut-être une erreur dans le dashboard, car l'heuristique qui cherche les intérêts prélevés par Deblock sur le Vault ne semble pas tout à fait exacte

est il y à encore un écart entre le vault (montant) detecter et le vault réel

La bdd couvre du 08/03 au 29/03 environ (bloc 24615802 au bloc 24765709)

BDD Lien 
=> https://drive.google.com/file/d/1Gse3a6k1mUyLb_g-FL0UDYIrC_iYXku5/view?usp=sharing