/**
 * Synchronise les logs Transfer ERC-20 du contrat token où le noeud est from ou to.
 * Remplit raw_transfers et tx_gas (coût ETH par transaction).
 */
import {
  createPublicClient,
  fallback,
  http,
  parseAbiItem,
  formatEther,
} from "viem";
import { mainnet } from "viem/chains";
import { config, assertConfig } from "./lib/config.mjs";
import { getPool, closePool } from "./lib/db.mjs";

const transferEvent = parseAbiItem(
  "event Transfer(address indexed from, address indexed to, uint256 value)"
);

assertConfig();

if (!config.rpcUrlFallback) {
  console.warn(
    "RPC_URL_FALLBACK non défini : en cas de 429 Infura, définis une 2e URL (ex. Alchemy) dans .env pour bascule automatique."
  );
}

function buildRpcTransport() {
  const primary = http(config.rpcUrl);
  if (!config.rpcUrlFallback) return primary;
  return fallback([primary, http(config.rpcUrlFallback)], {
    name: "RPC Infura/Alchemy fallback",
  });
}

const client = createPublicClient({
  chain: mainnet,
  transport: buildRpcTransport(),
});

const RPC_429_MAX_ATTEMPTS = Math.max(1, Number(process.env.RPC_429_MAX_ATTEMPTS ?? "6"));
const RPC_429_BASE_MS = Math.max(500, Number(process.env.RPC_429_BASE_MS ?? "2000"));

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

function isHttp429(err) {
  let e = err;
  while (e) {
    if (e.name === "HttpRequestError" && e.status === 429) return true;
    e = e.cause;
  }
  return false;
}

function isRateLimitedMessage(err) {
  const msg = String(err?.message ?? err?.details ?? "");
  return /too many requests|429|rate limit|throttl/i.test(msg);
}

/** @param {any} p getLogs parameters */
async function getLogsWith429Retry(p) {
  let delay = RPC_429_BASE_MS;
  for (let attempt = 1; attempt <= RPC_429_MAX_ATTEMPTS; attempt++) {
    try {
      return await client.getLogs(p);
    } catch (e) {
      const last = attempt === RPC_429_MAX_ATTEMPTS;
      if ((!isHttp429(e) && !isRateLimitedMessage(e)) || last) {
        throw e;
      }
      console.warn(
        `RPC limite (429 / throttling), attente ${delay}ms (essai ${attempt}/${RPC_429_MAX_ATTEMPTS})…`
      );
      await sleep(delay);
      delay = Math.min(Math.floor(delay * 1.7), 60_000);
    }
  }
  throw new Error("getLogsWith429Retry: unreachable");
}

function toMysqlDateTime(sec) {
  return new Date(Number(sec) * 1000).toISOString().slice(0, 19).replace("T", " ");
}

async function getSyncLast() {
  const pool = getPool();
  const [rows] = await pool.query(
    "SELECT last_block_scanned FROM sync_state WHERE key_name = 'main' LIMIT 1"
  );
  if (!rows.length) {
    await pool.query(
      "INSERT INTO sync_state (key_name, last_block_scanned, source) VALUES ('main', 0, 'rpc')"
    );
    return 0n;
  }
  return BigInt(rows[0].last_block_scanned);
}

async function setSyncLast(block, errMsg = null) {
  const pool = getPool();
  await pool.query(
    `UPDATE sync_state SET last_block_scanned = ?, last_error = ?, source = 'rpc', updated_at = CURRENT_TIMESTAMP WHERE key_name = 'main'`,
    [Number(block), errMsg]
  );
}

/** Infura / beaucoup de nœuds : max ~10k logs par eth_getLogs */
function isGetLogsResultLimitError(err) {
  let e = err;
  while (e) {
    if (e.name === "LimitExceededRpcError") return true;
    if (e.code === -32005) return true;
    e = e.cause;
  }
  return false;
}

/** @param {import('viem').Log[]} logs */
function dedupeAndSortLogs(logs) {
  const map = new Map();
  for (const log of logs) {
    const k = `${log.transactionHash}-${log.logIndex}`;
    if (!map.has(k)) map.set(k, log);
  }
  return [...map.values()].sort((a, b) => {
    if (a.blockNumber === b.blockNumber)
      return Number(a.logIndex) - Number(b.logIndex);
    return Number(a.blockNumber) - Number(b.blockNumber);
  });
}

/** @param {bigint} fromB @param {bigint} toB */
async function fetchLogsChunk(fromB, toB) {
  try {
    // Séquentiel : moins de rafale sur le plan gratuit ; retries 429 avant d’abandonner.
    const fromNode = await getLogsWith429Retry({
      address: config.tokenContract,
      event: transferEvent,
      args: { from: config.nodeAddress },
      fromBlock: fromB,
      toBlock: toB,
    });
    const toNode = await getLogsWith429Retry({
      address: config.tokenContract,
      event: transferEvent,
      args: { to: config.nodeAddress },
      fromBlock: fromB,
      toBlock: toB,
    });
    return dedupeAndSortLogs([...fromNode, ...toNode]);
  } catch (e) {
    if (!isGetLogsResultLimitError(e) || fromB >= toB) {
      throw e;
    }
    const mid = fromB + (toB - fromB) / 2n;
    console.warn("getLogs > limite fournisseur (10k logs), découpage", {
      fromBlock: fromB.toString(),
      mid: mid.toString(),
      toBlock: toB.toString(),
    });
    const left = await fetchLogsChunk(fromB, mid);
    const right = await fetchLogsChunk(mid + 1n, toB);
    return dedupeAndSortLogs([...left, ...right]);
  }
}

const blockTimeCache = new Map();

async function getBlockTimestamp(blockNumber) {
  const k = Number(blockNumber);
  if (blockTimeCache.has(k)) return blockTimeCache.get(k);
  const block = await client.getBlock({ blockNumber });
  const ts = block.timestamp;
  blockTimeCache.set(k, ts);
  return ts;
}

async function ensureTxGas(txHash, blockNumber) {
  const pool = getPool();
  const [existing] = await pool.query("SELECT 1 FROM tx_gas WHERE tx_hash = ? LIMIT 1", [
    txHash,
  ]);
  if (existing.length) return;

  const receipt = await client.getTransactionReceipt({ hash: txHash });
  const gasUsed = receipt.gasUsed;
  const effective =
    receipt.effectiveGasPrice ?? receipt.gasPrice ?? 0n;
  let costWei = gasUsed * effective;
  // EIP-4844 : coût blob en plus du gas d’exécution (sinon sous-estimation sur txs avec blobs)
  const blobUsed = receipt.blobGasUsed;
  const blobPrice = receipt.blobGasPrice;
  if (blobUsed != null && blobPrice != null) {
    costWei += blobUsed * blobPrice;
  }
  const costEth = formatEther(costWei);

  await pool.query(
    `INSERT IGNORE INTO tx_gas (tx_hash, block_number, gas_used, effective_gas_price_wei, cost_eth)
     VALUES (?, ?, ?, ?, ?)`,
    [
      txHash,
      Number(blockNumber),
      String(gasUsed),
      String(effective),
      costEth,
    ]
  );
}

async function insertTransfer(log, blockTs) {
  const pool = getPool();
  const from = log.args.from.toLowerCase();
  const to = log.args.to.toLowerCase();
  const node = config.nodeAddress.toLowerCase();
  const direction = to === node ? "in" : "out";
  const amountRaw = String(log.args.value);

  await pool.query(
    `INSERT IGNORE INTO raw_transfers
     (tx_hash, log_index, block_number, block_time, token_contract, from_addr, to_addr, amount_raw, direction)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)`,
    [
      log.transactionHash,
      Number(log.logIndex),
      Number(log.blockNumber),
      toMysqlDateTime(blockTs),
      config.tokenContract.toLowerCase(),
      from,
      to,
      amountRaw,
      direction,
    ]
  );
}

async function runSyncOnce() {
  let last = await getSyncLast();
  if (last < config.startBlock - 1n) {
    await setSyncLast(config.startBlock - 1n, null);
    last = config.startBlock - 1n;
  }

  const latest = await client.getBlockNumber();
  let fromBlock = last + 1n;
  if (fromBlock > latest) {
    console.log("Déjà à jour.", { last: last.toString(), latest: latest.toString() });
    return;
  }

  let chunk = config.blockChunk;
  let toBlock = fromBlock + chunk - 1n;
  if (toBlock > latest) toBlock = latest;

  console.log("Scan", { fromBlock: fromBlock.toString(), toBlock: toBlock.toString() });

  let logs;
  try {
    logs = await fetchLogsChunk(fromBlock, toBlock);
  } catch (e) {
    console.error("getLogs erreur:", e.message);
    await setSyncLast(last, String(e.message));
    throw e;
  }

  const blockNums = [...new Set(logs.map((l) => Number(l.blockNumber)))];
  await Promise.all(blockNums.map((bn) => getBlockTimestamp(BigInt(bn))));

  const txSet = new Set();
  for (const log of logs) {
    txSet.add(log.transactionHash);
    const bts = await getBlockTimestamp(log.blockNumber);
    await insertTransfer(log, bts);
  }

  for (const h of txSet) {
    const first = logs.find((l) => l.transactionHash === h);
    await ensureTxGas(h, first.blockNumber);
  }

  await setSyncLast(toBlock, null);
  console.log("OK", { transfers: logs.length, txs: txSet.size, toBlock: toBlock.toString() });
}

try {
  await runSyncOnce();
} finally {
  await closePool();
}
