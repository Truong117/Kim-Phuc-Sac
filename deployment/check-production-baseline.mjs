import { readFile } from "node:fs/promises";

const baseline = JSON.parse(await readFile(new URL("./production-baseline.json", import.meta.url)));
if (baseline.verified !== true || !/^[a-f0-9]{40}$/.test(baseline.backend_sha ?? "") ||
    !/^[a-f0-9]{40}$/.test(baseline.frontend_sha ?? "") ||
    !baseline.migration_ledger_digest || !baseline.restore_evidence ||
    !baseline.business_smoke_evidence || !baseline.verified_at) {
  console.error("Production automatic deployment is disabled: baseline and recovery evidence are incomplete.");
  process.exitCode = 1;
} else {
  console.log("Baseline recorded. Production deployment still requires the separate activation PR.");
}
