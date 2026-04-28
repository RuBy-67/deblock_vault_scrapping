/**
 * Règles v1 (rule_version en base) :
 * - Pas de « petit montant seul » en interest : un transfert isolé (ex. activation 4 %, < 10 €)
 *   est toujours payment (IN) ou top_up (OUT), même s’il est petit.
 * - Interest uniquement si aller-retour strict même portefeuille A : IN = A→noeud, OUT = noeud→A
 *   (même adresse A en from du IN et en to du OUT, noeud en to du IN et from du OUT),
 *   dans PAIR_WINDOW_SECONDS, avec plafond progressif par "taille wallet" (approx v1),
 *   et max 1 paire interest par wallet/jour,
 *   et si INTEREST_PAIR_MAX_FEE_BPS > 0 : |a−b|/max(a,b) <= ce ratio (sinon pas de limite sur l’écart)
 *   → interest + fee (confidence 85) pour les deux jambes.
 * - Sinon IN → payment ; OUT → top_up (confidence 60).
 *
 * Voir CLASSIFY_FULL_REBUILD dans .env (recalcule complet vs incrémental).
 *
 * Perf : index par Wallet + fenêtre temporelle (recherche dichotomique) O(n log n)
 * au lieu de comparer chaque ligne à toute la table.
 */
import { config, assertConfig } from "./lib/config.mjs";
import { getPool, closePool } from "./lib/db.mjs";

assertConfig();

const node = config.nodeAddress.toLowerCase();
const RULE = config.ruleVersion;
const WINDOW_MS = config.pairWindowSeconds * 1000;
const MAX_FEE_BPS =
  Number.isFinite(config.interestPairMaxFeeBps) && config.interestPairMaxFeeBps > 0
    ? BigInt(Math.floor(config.interestPairMaxFeeBps))
    : 0n;
const FULL_REBUILD = config.classifyFullRebuild;

/** Affiche une ligne de progression tous les N indices parcourus. */
const PROGRESS_EVERY = 10_000;
/** Taille de batch d'insertion SQL (évite les gros paquets MySQL). */
const INSERT_BATCH_SIZE = Math.max(
  500,
  Number(process.env.CLASSIFY_INSERT_BATCH_SIZE ?? "5000")
);

// Paliers approx wallet (top_up - payment) -> plafond de jambe interest.
const TIER_50K = 50000000000000000000000n;
const TIER_100K = 100000000000000000000000n;
const TIER_200K = 200000000000000000000000n;
const TIER_500K = 500000000000000000000000n;
const LEG_MAX_5 = 5000000000000000000n;
const LEG_MAX_10 = 10000000000000000000n;
const LEG_MAX_20 = 20000000000000000000n;
const LEG_MAX_30 = 30000000000000000000n;
const LEG_MAX_50 = 50000000000000000000n;

function absDiff(a, b) {
  const x = BigInt(a);
  const y = BigInt(b);
  return x >= y ? x - y : y - x;
}

function lcAddr(x) {
  return String(x).toLowerCase();
}

/** Portefeuille tiers (pas le noeud) pour ce transfert toujours en minuscules. */
function peerWallet(r) {
  const f = lcAddr(r.from_addr);
  const t = lcAddr(r.to_addr);
  return f === node ? t : f;
}

/**
 * Vérifie le schéma « échange » : une jambe reçue depuis A vers le noeud, l’autre renvoie du noeud vers A.
 * (Pas seulement « même clé de regroupement » : from/to doivent s’emboîter.)
 */
function isRoundTripSameWallet(r, rj) {
  const f = lcAddr(r.from_addr);
  const t = lcAddr(r.to_addr);
  const fj = lcAddr(rj.from_addr);
  const tj = lcAddr(rj.to_addr);
  if (r.direction === "in" && rj.direction === "out") {
    return t === node && fj === node && f === tj;
  }
  if (r.direction === "out" && rj.direction === "in") {
    return f === node && tj === node && t === fj;
  }
  return false;
}

function timeMs(r) {
  return new Date(r.block_time).getTime();
}

function dayKey(r) {
  return String(r.block_time || "").slice(0, 10);
}

/**
 * @param {{ idx: number; t: number }[]} entries triées par t croissant
 * @returns premier indice k tel que entries[k].t >= tMin
 */
function lowerBoundTime(entries, tMin) {
  let lo = 0;
  let hi = entries.length;
  while (lo < hi) {
    const mid = (lo + hi) >> 1;
    if (entries[mid].t < tMin) lo = mid + 1;
    else hi = mid;
  }
  return lo;
}

/**
 * @param {{ idx: number; t: number }[]} entries triées par t croissant
 * @returns premier indice k tel que entries[k].t > tMax
 */
function upperBoundExclusiveTime(entries, tMax) {
  let lo = 0;
  let hi = entries.length;
  while (lo < hi) {
    const mid = (lo + hi) >> 1;
    if (entries[mid].t <= tMax) lo = mid + 1;
    else hi = mid;
  }
  return lo;
}

/**
 * Liste par portefeuille tiers A (peerWallet), ordre chronologique = ordre des indices
 * car raw_transfers est trié par block_time.
 * @param {any[]} rows
 * @returns {Map<string, { idx: number; t: number }[]>}
 */
function buildPeerTimeline(rows) {
  const m = new Map();
  for (let i = 0; i < rows.length; i++) {
    const r = rows[i];
    const key = peerWallet(r);
    if (!m.has(key)) m.set(key, []);
    m.get(key).push({ idx: i, t: timeMs(r) });
  }
  return m;
}

/**
 * Charge l'approx v1 par wallet depuis raw_transfers (top_up - payment).
 * @returns {Promise<Map<string, bigint>>}
 */
async function loadWalletApproxMap() {
  const pool = getPool();
  const [rows] = await pool.query(
    `SELECT
       LOWER(CASE WHEN direction = 'in' THEN from_addr ELSE to_addr END) AS cp,
       COALESCE(SUM(CASE WHEN direction = 'out' THEN CAST(amount_raw AS DECIMAL(65,0)) ELSE 0 END), 0) AS sum_out_raw,
       COALESCE(SUM(CASE WHEN direction = 'in' THEN CAST(amount_raw AS DECIMAL(65,0)) ELSE 0 END), 0) AS sum_in_raw
     FROM raw_transfers
     GROUP BY LOWER(CASE WHEN direction = 'in' THEN from_addr ELSE to_addr END)`
  );

  const m = new Map();
  for (const r of rows) {
    const cp = String(r.cp || "").toLowerCase();
    if (!cp) continue;
    const outRaw = BigInt(String(r.sum_out_raw ?? "0"));
    const inRaw = BigInt(String(r.sum_in_raw ?? "0"));
    const approxRaw = outRaw - inRaw;
    m.set(cp, approxRaw);
  }
  return m;
}

function pairMaxForApprox(approxRaw) {
  if (approxRaw >= TIER_500K) return LEG_MAX_50;
  if (approxRaw >= TIER_200K) return LEG_MAX_30;
  if (approxRaw >= TIER_100K) return LEG_MAX_20;
  if (approxRaw >= TIER_50K) return LEG_MAX_10;
  return LEG_MAX_5;
}

/**
 * Parmi les j non utilisés : même portefeuille A, direction opposée, schéma A↔noeud,
 * |t_j-t_i|<=WINDOW, minimise dt ; en cas d’égalité, plus petit indice j.
 */
function findBestPairIndex(i, rows, used, timeline) {
  const r = rows[i];
  const key = peerWallet(r);
  const entries = timeline.get(key);
  if (!entries?.length) return -1;

  const tI = timeMs(r);
  const lo = lowerBoundTime(entries, tI - WINDOW_MS);
  const hi = upperBoundExclusiveTime(entries, tI + WINDOW_MS);

  let bestJ = -1;
  let bestDt = Infinity;
  for (let k = lo; k < hi; k++) {
    const e = entries[k];
    if (e.idx === i || used.has(e.idx)) continue;
    const rj = rows[e.idx];
    if (rj.direction === r.direction) continue;
    if (!isRoundTripSameWallet(r, rj)) continue;
    const dt = Math.abs(e.t - tI);
    if (dt < bestDt || (dt === bestDt && (bestJ < 0 || e.idx < bestJ))) {
      bestDt = dt;
      bestJ = e.idx;
    }
  }
  return bestJ;
}

async function loadTransfers() {
  const pool = getPool();
  const sql = FULL_REBUILD
    ? `SELECT id, block_time, from_addr, to_addr, amount_raw, direction
       FROM raw_transfers
       ORDER BY block_time ASC, id ASC`
    : `SELECT rt.id, rt.block_time, rt.from_addr, rt.to_addr, rt.amount_raw, rt.direction
       FROM raw_transfers rt
       LEFT JOIN classified_events ce ON ce.raw_transfer_id = rt.id
       WHERE ce.raw_transfer_id IS NULL
       ORDER BY rt.block_time ASC, rt.id ASC`;
  const [rows] = await pool.query(sql);
  return rows;
}

async function replaceClassifications(rows, walletApproxMap = new Map()) {
  const pool = getPool();
  const conn = await pool.getConnection();
  try {
    await conn.beginTransaction();

    if (!FULL_REBUILD) {
      const pending = rows.length;
      if (pending === 0) {
        await conn.rollback();
        console.log(
          "Classify: CLASSIFY_FULL_REBUILD=false tout est déjà classé, rien à insérer."
        );
        return;
      }
      console.log(
        "Classify: mode incrémental,",
        pending,
        "transfert(s) sans classification (les paires ne lient que des lignes encore libres)."
      );
    }

    if (FULL_REBUILD) {
      await conn.query("DELETE FROM classified_events");
    }

    const used = new Set();
    const interestDayUsed = new Set();

    /** @type {any[][]} */
    const inserts = [];

    const timeline = buildPeerTimeline(rows);

    const classifyLoopStart = Date.now();
    console.log(
      "Classify: analyse des lignes… (progression tous les",
      PROGRESS_EVERY.toLocaleString("fr-FR"),
      "indices)"
    );

    for (let i = 0; i < rows.length; i++) {
      if (i > 0 && i % PROGRESS_EVERY === 0) {
        const elapsed = ((Date.now() - classifyLoopStart) / 1000).toFixed(0);
        const pct = ((100 * i) / rows.length).toFixed(1);
        console.log(
          `Classify: ${i.toLocaleString("fr-FR")}/${rows.length.toLocaleString("fr-FR")} indices (${pct}%) ${elapsed}s`
        );
      }
      if (used.has(i)) continue;
      const r = rows[i];
      const counterparty = peerWallet(r);
      const amt = BigInt(r.amount_raw);
      const approxRaw = walletApproxMap.get(counterparty) ?? 0n;
      const pairMaxForWallet = pairMaxForApprox(approxRaw);

      let bestJ = findBestPairIndex(i, rows, used, timeline);

      if (bestJ >= 0) {
        const rj = rows[bestJ];
        const otherAmt = BigInt(rj.amount_raw);
        const maxLeg = amt > otherAmt ? amt : otherAmt;
        if (maxLeg > pairMaxForWallet) {
          bestJ = -1;
        } else if (dayKey(r) === "" || dayKey(r) !== dayKey(rj)) {
          bestJ = -1;
        } else if (interestDayUsed.has(`${counterparty}|${dayKey(r)}`)) {
          bestJ = -1;
        } else if (MAX_FEE_BPS > 0n && maxLeg > 0n) {
          const diff = absDiff(r.amount_raw, rj.amount_raw);
          if (diff * 10000n > maxLeg * MAX_FEE_BPS) {
            bestJ = -1;
          }
        }
      }

      if (bestJ >= 0) {
        const rj = rows[bestJ];
        const fee = String(absDiff(r.amount_raw, rj.amount_raw));
        inserts.push([r.id, counterparty, "interest", rj.id, fee, RULE, 85]);
        inserts.push([rj.id, counterparty, "interest", r.id, fee, RULE, 85]);
        interestDayUsed.add(`${counterparty}|${dayKey(r)}`);
        used.add(i);
        used.add(bestJ);
      } else {
        const typ = r.direction === "in" ? "payment" : "top_up";
        inserts.push([r.id, counterparty, typ, null, null, RULE, 60]);
        used.add(i);
      }
    }

    console.log(
      "Classify: boucle terminée —",
      rows.length.toLocaleString("fr-FR"),
      "indices en",
      ((Date.now() - classifyLoopStart) / 1000).toFixed(0),
      "s"
    );

    if (inserts.length) {
      console.log(
        "Classify: insertion",
        inserts.length.toLocaleString("fr-FR"),
        "ligne(s) dans classified_events… (batch",
        INSERT_BATCH_SIZE.toLocaleString("fr-FR"),
        ")"
      );
      const totalBatches = Math.ceil(inserts.length / INSERT_BATCH_SIZE);
      for (let b = 0; b < totalBatches; b++) {
        const start = b * INSERT_BATCH_SIZE;
        const end = Math.min(inserts.length, start + INSERT_BATCH_SIZE);
        const chunk = inserts.slice(start, end);
        await conn.query(
          `INSERT INTO classified_events
           (raw_transfer_id, counterparty, event_type, paired_transfer_id, fee_token_raw, rule_version, confidence)
           VALUES ?`,
          [chunk]
        );
        if ((b + 1) % 20 === 0 || b + 1 === totalBatches) {
          console.log(
            `Classify: insert batch ${String(b + 1).padStart(4, " ")}/${totalBatches} (${end.toLocaleString("fr-FR")} lignes)`
          );
        }
      }
    }

    await conn.commit();
  } catch (e) {
    try {
      await conn.rollback();
    } catch {
      // Connexion possiblement déjà reset côté MySQL.
    }
    throw e;
  } finally {
    conn.release();
  }
}

try {
  const rows = await loadTransfers();
  const walletApproxMap = await loadWalletApproxMap();
  console.log("Classify", rows.length, FULL_REBUILD ? "transferts en base" : "transferts non classés", {
    fullRebuild: FULL_REBUILD,
    rule: RULE,
    interestTiersRaw: {
      lt50k: LEG_MAX_5.toString(),
      gte50k: LEG_MAX_10.toString(),
      gte100k: LEG_MAX_20.toString(),
      gte200k: LEG_MAX_30.toString(),
      gte500k: LEG_MAX_50.toString(),
    },
    walletCount: walletApproxMap.size,
  });
  await replaceClassifications(rows, walletApproxMap);
  console.log("Classification terminée", { rule: RULE, fullRebuild: FULL_REBUILD });
} finally {
  await closePool();
}
