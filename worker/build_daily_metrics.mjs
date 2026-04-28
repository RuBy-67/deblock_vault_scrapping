import { getPool, closePool } from "./lib/db.mjs";
import { parseMetricsOptions, planDailyMetricsRange } from "./lib/metricsIncremental.mjs";

const EXCLUDE_ZERO = `NOT (
  (rt.direction = 'in'  AND rt.from_addr = '0x0000000000000000000000000000000000000000')
  OR (rt.direction = 'out' AND rt.to_addr = '0x0000000000000000000000000000000000000000')
)`;

const CONFIDENCE_CASE = `
  CASE
    WHEN ce.confidence >= 90 THEN '90-100'
    WHEN ce.confidence >= 75 THEN '75-89'
    WHEN ce.confidence >= 60 THEN '60-74'
    WHEN ce.confidence >= 40 THEN '40-59'
    ELSE '0-39'
  END
`;

async function run() {
  const pool = getPool();
  const conn = await pool.getConnection();
  const { fullRebuild, overlapDays } = parseMetricsOptions();
  try {
    await conn.beginTransaction();

    await conn.query(`
      CREATE TABLE IF NOT EXISTS daily_metrics (
        day DATE NOT NULL,
        n_tx BIGINT UNSIGNED NOT NULL DEFAULT 0,
        n_payment BIGINT UNSIGNED NOT NULL DEFAULT 0,
        n_topup BIGINT UNSIGNED NOT NULL DEFAULT 0,
        n_interest BIGINT UNSIGNED NOT NULL DEFAULT 0,
        n_unknown BIGINT UNSIGNED NOT NULL DEFAULT 0,
        sum_in_raw DECIMAL(65,0) NOT NULL DEFAULT 0,
        sum_out_raw DECIMAL(65,0) NOT NULL DEFAULT 0,
        sum_payment_raw DECIMAL(65,0) NOT NULL DEFAULT 0,
        sum_topup_raw DECIMAL(65,0) NOT NULL DEFAULT 0,
        sum_interest_raw DECIMAL(65,0) NOT NULL DEFAULT 0,
        gas_eth DECIMAL(38,18) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (day)
      ) ENGINE=InnoDB
    `);

    await conn.query(`
      CREATE TABLE IF NOT EXISTS classification_daily (
        day DATE NOT NULL,
        event_type ENUM('interest', 'payment', 'top_up', 'unknown') NOT NULL,
        n BIGINT UNSIGNED NOT NULL DEFAULT 0,
        n_paired BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (day, event_type),
        KEY idx_cd_day (day)
      ) ENGINE=InnoDB
    `);

    await conn.query(`
      CREATE TABLE IF NOT EXISTS classification_confidence_daily (
        day DATE NOT NULL,
        bucket VARCHAR(16) NOT NULL,
        n BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (day, bucket),
        KEY idx_ccd_day (day)
      ) ENGINE=InnoDB
    `);

    const plan = await planDailyMetricsRange(
      conn,
      "SELECT MAX(day) AS d FROM daily_metrics",
      fullRebuild,
      overlapDays
    );

    if (plan.didTruncate) {
      await conn.query("TRUNCATE TABLE daily_metrics");
      await conn.query("TRUNCATE TABLE classification_daily");
      await conn.query("TRUNCATE TABLE classification_confidence_daily");
    } else if (plan.fromDay) {
      await conn.query("DELETE FROM daily_metrics WHERE day >= ?", [plan.fromDay]);
      await conn.query("DELETE FROM classification_daily WHERE day >= ?", [plan.fromDay]);
      await conn.query("DELETE FROM classification_confidence_daily WHERE day >= ?", [
        plan.fromDay,
      ]);
    }

    const insertDateFilter =
      plan.fromDay && !plan.didTruncate ? "AND DATE(rt.block_time) >= ?" : "";
    const insertParams = plan.fromDay && !plan.didTruncate ? [plan.fromDay] : [];

    await conn.query(
      `
      INSERT INTO daily_metrics (
        day, n_tx, n_payment, n_topup, n_interest, n_unknown,
        sum_in_raw, sum_out_raw, sum_payment_raw, sum_topup_raw, sum_interest_raw, gas_eth
      )
      SELECT
        DATE(rt.block_time) AS day,
        COUNT(*) AS n_tx,
        SUM(CASE WHEN ce.event_type = 'payment' THEN 1 ELSE 0 END) AS n_payment,
        SUM(CASE WHEN ce.event_type = 'top_up' THEN 1 ELSE 0 END) AS n_topup,
        SUM(CASE WHEN ce.event_type = 'interest' THEN 1 ELSE 0 END) AS n_interest,
        SUM(CASE WHEN ce.event_type = 'unknown' OR ce.event_type IS NULL THEN 1 ELSE 0 END) AS n_unknown,
        SUM(CASE WHEN rt.direction = 'in' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_in_raw,
        SUM(CASE WHEN rt.direction = 'out' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_out_raw,
        SUM(CASE WHEN ce.event_type = 'payment' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_payment_raw,
        SUM(CASE WHEN ce.event_type = 'top_up' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_topup_raw,
        SUM(CASE WHEN ce.event_type = 'interest' THEN CAST(rt.amount_raw AS DECIMAL(65,0)) ELSE 0 END) AS sum_interest_raw,
        0
      FROM raw_transfers rt
      LEFT JOIN classified_events ce ON ce.raw_transfer_id = rt.id
      WHERE ${EXCLUDE_ZERO}
      ${insertDateFilter}
      GROUP BY DATE(rt.block_time)
    `,
      insertParams
    );

    await conn.query(
      `
      INSERT INTO classification_daily (day, event_type, n, n_paired)
      SELECT
        DATE(rt.block_time) AS day,
        ce.event_type,
        COUNT(*) AS n,
        SUM(CASE WHEN ce.paired_transfer_id IS NOT NULL THEN 1 ELSE 0 END) AS n_paired
      FROM raw_transfers rt
      JOIN classified_events ce ON ce.raw_transfer_id = rt.id
      WHERE ${EXCLUDE_ZERO}
      ${insertDateFilter}
      GROUP BY DATE(rt.block_time), ce.event_type
    `,
      insertParams
    );

    await conn.query(
      `
      INSERT INTO classification_confidence_daily (day, bucket, n)
      SELECT
        DATE(rt.block_time) AS day,
        ${CONFIDENCE_CASE} AS bucket,
        COUNT(*) AS n
      FROM raw_transfers rt
      JOIN classified_events ce ON ce.raw_transfer_id = rt.id
      WHERE ${EXCLUDE_ZERO}
      ${insertDateFilter}
      GROUP BY DATE(rt.block_time), ${CONFIDENCE_CASE}
    `,
      insertParams
    );

    const gasDateFilter =
      plan.fromDay && !plan.didTruncate ? `AND DATE(rt.block_time) >= ?` : "";
    const gasParams = plan.fromDay && !plan.didTruncate ? [plan.fromDay] : [];

    await conn.query(
      `
      UPDATE daily_metrics d
      JOIN (
        SELECT
          DATE(t.block_time) AS day,
          SUM(t.cost_eth) AS gas_eth
        FROM (
          SELECT
            tg.tx_hash,
            MIN(rt.block_time) AS block_time,
            tg.cost_eth
          FROM tx_gas tg
          JOIN raw_transfers rt ON rt.tx_hash = tg.tx_hash
          WHERE ${EXCLUDE_ZERO}
          ${gasDateFilter}
          GROUP BY tg.tx_hash
        ) t
        GROUP BY DATE(t.block_time)
      ) g ON g.day = d.day
      SET d.gas_eth = g.gas_eth
    `,
      gasParams
    );

    await conn.commit();

    const [[row]] = await conn.query("SELECT COUNT(*) AS n FROM daily_metrics");
    const [[crow]] = await conn.query("SELECT COUNT(*) AS n FROM classification_daily");
    console.log("daily_metrics OK", {
      mode: plan.didTruncate ? "full" : plan.fromDay ? "incremental" : "full_backfill",
      fromDay: plan.fromDay ?? null,
      overlapDays,
      days: Number(row.n || 0),
      classification_daily_rows: Number(crow.n || 0),
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
