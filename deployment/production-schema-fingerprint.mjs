import { createHash } from "node:crypto";
import { readFile } from "node:fs/promises";
import { resolve } from "node:path";
import { fileURLToPath } from "node:url";

// Offline metadata export only; never connects to SQL or automatically accepts
// the observed schema as the contract. The four arrays retain SQL ORDER BY.
export function schemaFingerprint(observation) {
  const keys = ["tables", "columns", "indexes", "foreign_keys"];
  if (!observation || Object.keys(observation).length !== 4 || keys.some((key) => !Array.isArray(observation[key]))) {
    throw new Error("Expected only the four documented schema metadata arrays.");
  }
  const normalized = Object.fromEntries(keys.map((key) => {
    const rows = observation[key];
    if (rows.length > (key === "tables" ? 256 : key === "foreign_keys" ? 512 : 4096)) throw new Error("Metadata export exceeds the inspection bound.");
    return [key, rows.map((row) => {
      if (!row || typeof row !== "object" || Array.isArray(row)) throw new Error("Invalid metadata row.");
      return Object.fromEntries(Object.keys(row).sort().map((field) => {
        const value = row[field];
        if (value !== null && !["string", "number"].includes(typeof value)) throw new Error("Invalid metadata value.");
        return [field, value === null ? null : String(value)];
      }));
    })];
  }));
  return createHash("sha256").update(JSON.stringify(normalized)).digest("hex");
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    if (process.argv.length !== 3) throw new Error("metadata file required");
    console.log(schemaFingerprint(JSON.parse((await readFile(process.argv[2], "utf8")).replace(/^\uFEFF/, ""))));
  } catch { console.error("Offline schema metadata is missing or invalid; content withheld."); process.exitCode = 1; }
}
