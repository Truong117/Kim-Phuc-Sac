import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";

const source = await readFile(
  new URL("../src/utils/workspace.ts", import.meta.url),
  "utf8",
);
const compiled = ts.transpileModule(source, {
  compilerOptions: {
    module: ts.ModuleKind.ESNext,
    target: ts.ScriptTarget.ES2022,
  },
}).outputText;
const {
  defaultWorkspacePath,
  canOpenWorkspacePath,
  parseWorkspaces,
  workspaceForPath,
  workspacePreferenceKey,
} = await import(
  `data:text/javascript;base64,${Buffer.from(compiled).toString("base64")}`
);
const internal = { id: "internal", home: "/", sections: [] };
const technician = {
  id: "spa",
  home: "/spa/technician",
  sections: ["technician"],
};

test("workspace preference is scoped per user and never grants missing access", () => {
  assert.notEqual(workspacePreferenceKey("1"), workspacePreferenceKey("2"));
  assert.equal(defaultWorkspacePath([internal, technician], null), "/");
  assert.equal(
    defaultWorkspacePath([internal, technician], "spa"),
    "/spa/technician",
  );
  assert.equal(defaultWorkspacePath([internal], "spa"), "/");
  assert.equal(
    defaultWorkspacePath([technician], "internal"),
    "/spa/technician",
  );
  assert.equal(defaultWorkspacePath([], "internal"), null);
});
test("deep links retain query and hash while spa section access is constrained", () => {
  assert.equal(
    canOpenWorkspacePath([technician], "/spa/technician?day=today#task"),
    true,
  );
  assert.equal(canOpenWorkspacePath([technician], "/spa/account"), true);
  assert.equal(canOpenWorkspacePath([technician], "/spa/reports"), false);
  assert.equal(canOpenWorkspacePath([technician], "/spa"), false);
  assert.equal(canOpenWorkspacePath([technician], "/employees"), false);
  assert.equal(canOpenWorkspacePath([internal], "/spa/technician"), false);
  assert.equal(workspaceForPath("/spaghetti"), "internal");
});
test("entry never accepts external redirects or recursive entry routes", () => {
  for (const path of [
    "//evil.test",
    "https://evil.test",
    "/\\evil.test",
    "/\n/evil.test",
    "/login",
    "/workspace",
  ])
    assert.equal(
      canOpenWorkspacePath([internal, technician], path),
      false,
      path,
    );
});
test("untrusted workspace payloads cannot add external home paths", () => {
  assert.deepEqual(
    parseWorkspaces({ workspaces: [internal, technician, technician] }),
    [internal, technician],
  );
  for (const payload of [
    null,
    {},
    { workspaces: "spa" },
    { workspaces: [{ ...technician, home: "https://evil.test" }] },
    { workspaces: [{ ...technician, sections: [] }] },
  ])
    assert.deepEqual(parseWorkspaces(payload), []);
});
