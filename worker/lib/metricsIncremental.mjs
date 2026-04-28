/**
 * Rebuild complet (TRUNCATE + tout réinsérer) ou incrémental :
 * supprime les jours >= fromDay puis réagrège uniquement ces jours depuis raw_transfers.
 *
 * METRICS_FULL_REBUILD=1 ou argv --full → rebuild complet.
 * METRICS_INCREMENTAL_OVERLAP_DAYS (défaut 3) → recalcule aussi N jours avant le dernier
 * jour déjà en base (données « en retard » sur les derniers jours).
 */

export function parseMetricsOptions() {
  const fullRebuild =
    process.argv.includes("--full") ||
    process.env.METRICS_FULL_REBUILD === "1" ||
    process.env.METRICS_FULL_REBUILD === "true";
  const overlapDays = Math.max(
    0,
    parseInt(process.env.METRICS_INCREMENTAL_OVERLAP_DAYS ?? "3", 10)
  );
  return { fullRebuild, overlapDays };
}

/** Accepte une chaîne `YYYY-MM-DD` ou un `Date` (ex. colonne DATE via mysql2). */
export function addCalendarDays(isoDateStr, deltaDays) {
  let y;
  let m;
  let d;
  if (isoDateStr instanceof Date) {
    y = isoDateStr.getUTCFullYear();
    m = isoDateStr.getUTCMonth() + 1;
    d = isoDateStr.getUTCDate();
  } else {
    const s = String(isoDateStr).slice(0, 10);
    const parts = s.split("-").map(Number);
    y = parts[0];
    m = parts[1];
    d = parts[2];
  }
  const dt = new Date(Date.UTC(y, m - 1, d));
  dt.setUTCDate(dt.getUTCDate() + deltaDays);
  const y2 = dt.getUTCFullYear();
  const m2 = String(dt.getUTCMonth() + 1).padStart(2, "0");
  const d2 = String(dt.getUTCDate()).padStart(2, "0");
  return `${y2}-${m2}-${d2}`;
}

/**
 * @param {import("mysql2/promise").PoolConnection} conn
 * @param {string} maxDaySql ex. "SELECT MAX(day) AS d FROM daily_metrics"
 */
export async function planDailyMetricsRange(conn, maxDaySql, fullRebuild, overlapDays) {
  if (fullRebuild) {
    return { fromDay: null, didTruncate: true };
  }
  const [[row]] = await conn.query(maxDaySql);
  const rawMax = row?.d;
  const maxDay =
    rawMax instanceof Date
      ? addCalendarDays(rawMax, 0)
      : rawMax != null
        ? String(rawMax).slice(0, 10)
        : null;
  if (!maxDay || !/^\d{4}-\d{2}-\d{2}$/.test(maxDay)) {
    return { fromDay: null, didTruncate: false };
  }
  const fromDay = addCalendarDays(maxDay, -overlapDays);
  return { fromDay, didTruncate: false };
}
