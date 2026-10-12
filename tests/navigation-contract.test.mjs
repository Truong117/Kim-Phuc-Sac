import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

test("the employees navigation key renders the employee item and guards its routes", async () => {
  const [navigationTypes, sidebar, app, authorizationProvider, authorizationRoute] =
    await Promise.all([
      readSource("src/types/authorization.ts"),
      readSource("src/layout/AppSidebar.tsx"),
      readSource("src/App.tsx"),
      readSource("src/context/AuthorizationProvider.tsx"),
      readSource("src/components/auth/AuthorizationRoute.tsx"),
    ]);

  assert.match(navigationTypes, /"employees"/);
  assert.match(
    sidebar,
    /navigationKey:\s*"employees"[\s\S]*?path:\s*"\/employees"/,
  );
  assert.match(
    sidebar,
    /group\.items\.filter\(\(item\)\s*=>\s*canNavigate\(item\.navigationKey\)\)/,
  );
  assert.match(
    app,
    /AuthorizationRoute navigationKey="employees"[\s\S]*?path="\/employees"/,
  );

  for (const source of [sidebar, app, authorizationProvider, authorizationRoute]) {
    assert.doesNotMatch(source, /\b(?:HR_MANAGER|OFFICE_STAFF|OWNER|ADMIN)\b/);
  }
});
