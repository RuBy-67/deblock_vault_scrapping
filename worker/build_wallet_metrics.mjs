import { getPool, closePool } from "./lib/db.mjs";
import { parseMetricsOptions, planDailyMetricsRange } from "./lib/metricsIncremental.mjs";

const ZERO = "0x0000000000000000000000000000000000000000";
const EXCLUDE_ZERO = `NOT (
  (rt.direction = 'in'  AND rt.from_addr = '${ZERO}')
  OR (rt.direction = 'out' AND rt.to_addr = '${ZERO}')
)`;

async function run() {
  const pool = getPool();
  const conn = await pool.getConnection();
  const { fullRebuild, overlapDays } = parseMetricsOptions();
  try {
    await conn.beginTransaction();

    await conn.query(`
      CREATE TABLE IF NOT EXISTS wallet_peer_daily (
        day DATE NOT NULL,
        peer_addr CHAR(42) NOT NULL,
        n_in BIGINT UNSIGNED NOT NULL DEFAULT 0,
        n_out BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sum_in_raw DECIMAL(65,0) NOT NULL DEFAULT 0,
        sum_out_raw DECIMAL(65,0) NOT NULL DEFAULT 0,
        n_total BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sum_payment_raw DECIMAL(65,0) NOT NULL DEFAULT 0,
        sum_topup_raw DECIMAL(65,0) NOT NULL DEFAULT 0,
        first_ts DATETIME NOT NULL,
        last_ts DATETIME NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (day, peer_addr),
        KEY idx_wpd_day (day)
      ) ENGINE=InnoDB
    `);

    await conn.query(`
      CREATE TABLE IF NOT EXISTS wallet_holding_daily (
        day DATE NOT NULL,
        wallet CHAR(42) NOT NULL,
        sum_payment_raw DECIMAL(65,0) NOT NULL DEFAULT 0,
        sum_topup_raw DECIMAL(65,0) NOT NULL DEFAULT 0,
        n_payment BIGINT UNSIGNED NOT NULL DEFAULT 0,
        n_topup BIGINT UNSIGNED NOT NULL DEFAULT 0,
        first_ts DATETIME DEFAULT NULL,
        last_ts DATETIME DEFAULT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (day, wallet),
        KEY idx_whd_day (day)
      ) ENGINE=InnoDB
    `);

    const plan = await planDailyMetricsRange(
      conn,
      "SELECT MAX(day) AS d FROM wallet_peer_daily",
      fullRebuild,
      overlapDays
    );

    if (plan.didTruncate) {
      await conn.query("TRUNCATE TABLE wallet_peer_daily");
      await conn.query("TRUNCATE TABLE wallet_holding_daily");
    } else if (plan.fromDay) {
      await conn.query("DELETE FROM wallet_peer_daily WHERE day >= ?", [plan.fromDay]);
      await conn.query("DELETE FROM wallet_holding_daily WHERE day >= ?", [plan.fromDay]);
    }

    const insertDateFilter =
      plan.fromDay && !plan.didTruncate ? "AND DATE(rt.block_time) >= ?" : "";
    const insertParams = plan.fromDay && !plan.didTruncate ? [plan.fromDay] : [];

    await conn.query(
      `
      INSERT INTO wallet_peer_daily (
        day, peer_addr, n_in, n_out, sum_in_raw, sum_out_raw, n_total,
        sum_payment_raw, sum_topup_raw, first_ts, last_ts
      )
      SELECT
        DATE(rt.block_time) AS day,
        LOWER(CASE WHEN rt.direction = 'in' THEN rt.from_addr ELSE rt.to_addr END) AS peer_addr,
        SUM(CASE WHEN rt.direction = 'in' THEN 1 ELSE 0 END) AS n_in,
        SUM(CASE WHEN rt.direction = 'out' THEN 1 ELSE 0 END) AS n_out,
        SUM(CASE WHEN rt.direction = 'in' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_in_raw,
        SUM(CASE WHEN rt.direction = 'out' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_out_raw,
        COUNT(*) AS n_total,
        SUM(CASE WHEN ce.event_type = 'payment' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_payment_raw,
        SUM(CASE WHEN ce.event_type = 'top_up' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_topup_raw,
        MIN(rt.block_time) AS first_ts,
        MAX(rt.block_time) AS last_ts
      FROM raw_transfers rt
      LEFT JOIN classified_events ce ON ce.raw_transfer_id = rt.id
      WHERE ${EXCLUDE_ZERO}
      ${insertDateFilter}
      GROUP BY DATE(rt.block_time), LOWER(CASE WHEN rt.direction = 'in' THEN rt.from_addr ELSE rt.to_addr END)
    `,
      insertParams
    );

    await conn.query(
      `
      INSERT INTO wallet_holding_daily (
        day, wallet, sum_payment_raw, sum_topup_raw, n_payment, n_topup, first_ts, last_ts
      )
      SELECT
        DATE(rt.block_time) AS day,
        LOWER(ce.counterparty) AS wallet,
        SUM(CASE WHEN ce.event_type = 'payment' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_payment_raw,
        SUM(CASE WHEN ce.event_type = 'top_up' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_topup_raw,
        SUM(CASE WHEN ce.event_type = 'payment' THEN 1 ELSE 0 END) AS n_payment,
        SUM(CASE WHEN ce.event_type = 'top_up' THEN 1 ELSE 0 END) AS n_topup,
        MIN(rt.block_time) AS first_ts,
        MAX(rt.block_time) AS last_ts
      FROM raw_transfers rt
      JOIN classified_events ce ON ce.raw_transfer_id = rt.id
      WHERE ${EXCLUDE_ZERO}
        AND ce.event_type IN ('payment', 'top_up')
        ${insertDateFilter}
      GROUP BY DATE(rt.block_time), LOWER(ce.counterparty)
    `,
      insertParams
    );

    await conn.commit();

    const [[peerN]] = await conn.query("SELECT COUNT(*) AS n FROM wallet_peer_daily");
    const [[holdN]] = await conn.query("SELECT COUNT(*) AS n FROM wallet_holding_daily");
    console.log("wallet_metrics OK", {
      mode: plan.didTruncate ? "full" : plan.fromDay ? "incremental" : "full_backfill",
      fromDay: plan.fromDay ?? null,
      overlapDays,
      wallet_peer_daily_rows: Number(peerN.n || 0),
      wallet_holding_daily_rows: Number(holdN.n || 0),
    });
  } catch (e) {
    await conn.rollback();
    throw e;
  } finally {
    conn.release();
  }
}

try {
  await run();
} finally {
  await closePool();
}
