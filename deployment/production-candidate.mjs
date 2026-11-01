import { fileURLToPath } from "node:url";
import { resolve } from "node:path";
import { assertApprovedMainMerge } from "./production-release.mjs";

export async function checkProductionCandidate(env, fetcher = fetch) {
  if (env.GITHUB_REF !== "refs/heads/main" || env.GITHUB_REPOSITORY !== "Truong117/Kim-Phuc-Sac" ||
      !/^[a-f0-9]{40}$/.test(env.GITHUB_SHA ?? "")) throw new Error("Production candidate is not trusted main.");
  const read = async (path) => {
    const response = await fetcher("https://api.github.com/repos/Truong117/Kim-Phuc-Sac/" + path,
      { headers: { Authorization: "Bearer " + env.GH_TOKEN, Accept: "application/json" }, redirect: "error", signal: AbortSignal.timeout(30_000) });
    if (!response.ok) throw new Error("GitHub candidate provenance check failed.");
    return response.json();
  };
  if ((await read("branches/main")).commit.sha !== env.GITHUB_SHA) throw new Error("Production candidate is stale.");
  assertApprovedMainMerge(await read("commits/" + env.GITHUB_SHA + "/pulls"), env.GITHUB_SHA);
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  await checkProductionCandidate(process.env).catch(() => { console.error("Production candidate denied: requires current main SHA from a merged same-repository staging → main PR."); process.exitCode = 1; });
}
