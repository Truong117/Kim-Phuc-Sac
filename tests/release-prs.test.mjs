import assert from "node:assert/strict";
import test from "node:test";
import { reconcile } from "../deployment/release-prs.mjs";

function fixture({ existing = false, hotfix = false } = {}) {
  const calls = [];
  const repo = { owner: "Truong117", repo: "Kim-Phuc-Sac" };
  const github = { rest: {
    repos: {
      compareCommitsWithBasehead: async ({ basehead }) => ({ data: {
        ahead_by: basehead.startsWith("main") || hotfix ? 1 : 0,
        files: basehead.startsWith("main") || hotfix ? [{ filename: "changed" }] : [],
        commits: [{ sha: "a".repeat(40) }],
      } }),
      getBranch: async () => ({ data: { commit: { sha: "b".repeat(40) } } }),
    },
    pulls: {
      list: async () => ({ data: existing ? [{ number: 74, base: { ref: "main" }, head: { ref: "staging", repo: { full_name: "Truong117/Kim-Phuc-Sac" } }, body: "Keep existing cutover checklist" }] : [] }),
      create: async (body) => { calls.push({ action: "create", ...body }); },
      update: async (body) => { calls.push({ action: "update", ...body }); },
    },
    git: {
      getRef: async () => { throw Object.assign(new Error("missing"), { status: 404 }); },
      createRef: async (body) => { calls.push({ action: "branch", ...body }); },
    },
  } };
  return { calls, github, repo };
}

test("updates existing release PR without replacing its operator checklist", async () => {
  const mock = fixture({ existing: true });
  await reconcile(mock.github, mock.repo, { info() {} });
  assert.equal(mock.calls.length, 1);
  assert.equal(mock.calls[0].action, "update");
  assert.match(mock.calls[0].body, /Keep existing cutover checklist/);
  assert.match(mock.calls[0].body, /Production automatic deployment is disabled/);
});

test("prepares Draft release and hotfix backport PRs without merging or resolving conflicts", async () => {
  const mock = fixture({ hotfix: true });
  await reconcile(mock.github, mock.repo, { info() {} });
  assert.deepEqual(mock.calls.map((call) => call.action), ["create", "branch", "create"]);
  assert.equal(mock.calls[0].base, "main");
  assert.equal(mock.calls[0].draft, true);
  assert.equal(mock.calls[2].base, "staging");
  assert.equal(mock.calls[2].draft, true);
  assert.match(mock.calls[2].head, /^hotfix\/backport-main-/);
  assert.equal(mock.calls[1].sha, "b".repeat(40));
});
