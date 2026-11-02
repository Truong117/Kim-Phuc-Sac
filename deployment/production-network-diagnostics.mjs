import { writeFile } from "node:fs/promises";
import { resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { validateNetworkTrust, probeProductionNetwork } from "./production-network.mjs";

export async function diagnoseProductionNetwork(env, { probe = probeProductionNetwork } = {}) {
  const evidence = { status: "FAIL", scope: "network_tls_only", candidate_sha: env.GITHUB_SHA ?? null,
    observed_at: new Date().toISOString(),
    sql_authenticated: false, identity_verified: false, ledger_verified: false, schema_verified: false,
    writes_executed: false, release_authorized: false };
  try {
    if (env.GITHUB_REF !== "refs/heads/main" || env.GITHUB_REPOSITORY !== "Truong117/Kim-Phuc-Sac" || !/^[a-f0-9]{40}$/.test(env.GITHUB_SHA ?? "")) {
      throw new Error("Main-only diagnostic configuration required.");
    }
    const pem = env.PRODUCTION_DB_CA_PEM;
    evidence.ca_sha256 = validateNetworkTrust(env, pem);
    evidence.network = await probe({ PRODUCTION_DB_HOST: env.PRODUCTION_DB_HOST, PRODUCTION_DB_PORT: env.PRODUCTION_DB_PORT }, pem);
    if (["dns", "tcp", "tls"].some((key) => evidence.network?.[key] !== "PASS") ||
        evidence.network?.ca_verified !== true || evidence.network?.hostname_verified !== true) {
      throw new Error("TLS probe result is incomplete.");
    }
    evidence.status = "PASS";
  } catch (error) {
    // Only bounded categories, never OS errors, hostnames or certificate data.
    evidence.failed_check = /DNS/.test(error.message) ? "dns" : /TCP/.test(error.message) ? "tcp"
      : /TLS|CA|certificate/.test(error.message) ? "tls" : "configuration";
  }
  return evidence;
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const evidence = await diagnoseProductionNetwork(process.env);
  await writeFile(new URL("../production-network-evidence.json", import.meta.url), JSON.stringify(evidence, null, 2) + "\n");
  if (evidence.status !== "PASS") process.exitCode = 1;
}
