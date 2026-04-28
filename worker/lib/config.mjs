import dotenv from "dotenv";
import path from "path";
import { fileURLToPath } from "url";
import { getAddress, isAddress } from "viem";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
dotenv.config({ path: path.resolve(__dirname, "..", "..", ".env") });

function req(name, fallback = undefined) {
  const v = process.env[name];
  if (v !== undefined && v !== "") return v;
  if (fallback !== undefined) return fallback;
  throw new Error(`Variable d'environnement manquante: ${name}`);
}

function opt(name) {
  const v = process.env[name];
  if (v !== undefined && v !== "") return v;
  return undefined;
}

export const config = {
  rpcUrl: req("RPC_URL"),
  rpcUrlFallback: opt("RPC_URL_FALLBACK"),
  mysql: {
    host: req("MYSQL_HOST", "127.0.0.1"),
    port: Number(req("MYSQL_PORT", "3306")),
    user: req("MYSQL_USER", "root"),
    password: process.env.MYSQL_PASSWORD ?? "",
    database: req("MYSQL_DATABASE", "eurcv_monitor"),
  },
  tokenContract: getAddress(req("TOKEN_CONTRACT", "0xBEeF007ECFBfdF9B919d0050821A9B6DbD634fF0")),
  nodeAddress: getAddress(req("NODE_ADDRESS", "0x4d2fB5F8Ec243fde4DF1A9678b82238570c7E0E4")),
  startBlock: BigInt(req("START_BLOCK", "21000000")),
  blockChunk: BigInt(req("BLOCK_CHUNK", "2000")),
  /** Non utilisé par classify.mjs (v1 pair-only) ; conservé pour compat .env existants. */
  interestSmallMaxRaw: BigInt(req("INTEREST_SMALL_MAX_RAW", "1000000000000000000")),
  pairWindowSeconds: Number(req("PAIR_WINDOW_SECONDS", "300")),
  /** Si une jambe de la paire IN/OUT dépasse ce montant (wei), pas de paire « interest » → payment / top_up. */
  interestPairMaxRaw: BigInt(req("INTEREST_PAIR_MAX_RAW", "50000000000000000000")),
  /**
   * Garde-fou gros wallets :
   * - si approx wallet (top_up - payment) >= INTEREST_LARGE_WALLET_MIN_APPROX_RAW
   * - alors on utilise ce plafond par jambe (sinon interestPairMaxRaw standard).
   */
  interestPairMaxRawLarge: BigInt(req("INTEREST_PAIR_MAX_RAW_LARGE", req("INTEREST_PAIR_MAX_RAW", "50000000000000000000"))),
  interestLargeWalletMinApproxRaw: BigInt(req("INTEREST_LARGE_WALLET_MIN_APPROX_RAW", "100000000000000000000000")),
  /**
   * Optionnel : écart relatif max |a−b| / max(a,b) en bps (100 = 1 %). 0 = désactivé (défaut).
   * La règle fiable v1 reste A→noeud + noeud→A dans PAIR_WINDOW ; ce filtre resserre si tu veux des montants quasi égaux.
   */
  interestPairMaxFeeBps: Number(req("INTEREST_PAIR_MAX_FEE_BPS", "0")),
  ruleVersion: req("RULE_VERSION", "v1"),
  /**
   * true (défaut) : vide classified_events et recalcule tout (cohérent si tu changes seuils / règles).
   * false : ne traite que les raw_transfers sans ligne dans classified_events (plus rapide ; les paires ne se font qu’entre lignes encore non classées, pas avec l’historique déjà figé).
   */
  classifyFullRebuild: process.env.CLASSIFY_FULL_REBUILD !== "false",
};

export function assertConfig() {
  if (!config.rpcUrl.startsWith("http")) throw new Error("RPC_URL doit être une URL https");
  if (
    config.rpcUrlFallback &&
    !config.rpcUrlFallback.startsWith("http")
  ) {
    throw new Error("RPC_URL_FALLBACK doit être une URL https");
  }
  if (!isAddress(config.tokenContract)) throw new Error("TOKEN_CONTRACT invalide");
  if (!isAddress(config.nodeAddress)) throw new Error("NODE_ADDRESS invalide");
}
