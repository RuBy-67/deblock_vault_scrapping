import { getPool, closePool } from "./lib/db.mjs";

async function run() {
  const pool = getPool();
  const conn = await pool.getConnection();
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

    await conn.query("TRUNCATE TABLE daily_metrics");

    await conn.query(`
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
      WHERE NOT (
        (rt.direction = 'in'  AND rt.from_addr = '0x0000000000000000000000000000000000000000')
        OR (rt.direction = 'out' AND rt.to_addr = '0x0000000000000000000000000000000000000000')
      )
      GROUP BY DATE(rt.block_time)
    `);

    await conn.query(`
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
          WHERE NOT (
            (rt.direction = 'in'  AND rt.from_addr = '0x0000000000000000000000000000000000000000')
            OR (rt.direction = 'out' AND rt.to_addr = '0x0000000000000000000000000000000000000000')
          )
          GROUP BY tg.tx_hash
        ) t
        GROUP BY DATE(t.block_time)
      ) g ON g.day = d.day
      SET d.gas_eth = g.gas_eth
    `);

    await conn.commit();

    const [[row]] = await conn.query("SELECT COUNT(*) AS n FROM daily_metrics");
    console.log("daily_metrics rebuild OK", { days: Number(row.n || 0) });
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
