import assert from "node:assert/strict";
import { test } from "node:test";
import { readFile } from "node:fs/promises";
import { createHash } from "node:crypto";
import { diagnoseProductionNetwork } from "../deployment/production-network-diagnostics.mjs";
import { probeProductionNetwork, sslRequest } from "../deployment/production-network.mjs";

const pem = await readFile(new URL("../backend/tests/Fixtures/production-ci/ca.pem", import.meta.url), "utf8");
const env = { GITHUB_REF: "refs/heads/main", GITHUB_REPOSITORY: "Truong117/Kim-Phuc-Sac", GITHUB_SHA: "a".repeat(40),
  PRODUCTION_DB_HOST: "fixture.aws.tidbcloud.com", PRODUCTION_DB_PORT: "4000", PRODUCTION_DB_CA_PEM: pem,
  PRODUCTION_DB_CA_SHA256: createHash("sha256").update(pem).digest("hex") };
const network = { dns: "PASS", tcp: "PASS", tls: "PASS", ca_verified: true, hostname_verified: true };

test("network-only diagnostic needs no SQL users, schema policy or activation flags and never claims SQL/release proof", async () => {
  let calls = 0;
  const result = await diagnoseProductionNetwork({ ...env, PRODUCTION_RO_PASSWORD: "NEVER-PASS", PRODUCTION_CD_ENABLED: "false" }, {
    probe: async (child, ca) => {
      calls++;
      assert.deepEqual(child, { PRODUCTION_DB_HOST: env.PRODUCTION_DB_HOST, PRODUCTION_DB_PORT: env.PRODUCTION_DB_PORT });
      assert.equal(ca, pem);
      return network;
    },
  });
  assert.equal(calls, 1);
  assert.equal(result.status, "PASS");
  for (const key of ["sql_authenticated", "identity_verified", "ledger_verified", "schema_verified", "writes_executed", "release_authorized"]) assert.equal(result[key], false);
  assert.ok(!JSON.stringify(result).includes("NEVER-PASS"));
});

test("wrong ref/repository, missing or wrong CA, IP/URL endpoint are denied before networking", async () => {
  for (const change of [{ GITHUB_REF: "refs/heads/staging" }, { GITHUB_REPOSITORY: "fork/repo" }, { GITHUB_SHA: "bad" },
    { PRODUCTION_DB_CA_PEM: "" }, { PRODUCTION_DB_CA_SHA256: "bad" }, { PRODUCTION_DB_HOST: "127.0.0.1" }, { PRODUCTION_DB_HOST: "https://fixture.tidbcloud.com" }]) {
    const result = await diagnoseProductionNetwork({ ...env, ...change }, { probe: () => { assert.fail("must not connect"); } });
    assert.equal(result.status, "FAIL");
    assert.equal(result.release_authorized, false);
  }
});

test("DNS/TCP/TLS failures are redacted and an incomplete probe cannot pass", async () => {
  for (const category of ["DNS", "TCP", "TLS"]) {
    const result = await diagnoseProductionNetwork(env, { probe: () => { throw new Error(category + " CREDENTIAL-SECRET employee fixture.aws.tidbcloud.com"); } });
    assert.equal(result.status, "FAIL");
    assert.equal(result.failed_check, category.toLowerCase());
    assert.ok(!/SECRET|employee|tidbcloud/.test(JSON.stringify(result)));
  }
  assert.equal((await diagnoseProductionNetwork(env, { probe: async () => ({ tls: "PASS" }) })).status, "FAIL");
});

test("stalled DNS is bounded and never starts TCP; malformed MySQL packets cannot trigger TLS", async () => {
  await assert.rejects(probeProductionNetwork(env, pem, {
    timeoutMs: 10, resolveDns: () => new Promise(() => {}), connect: () => { assert.fail("must not connect"); },
  }), /DNS/);
  for (const data of [Buffer.alloc(0), Buffer.alloc(3), Buffer.from([64, 0, 0, 0, 10])]) assert.throws(() => sslRequest(data));
});
