import assert from "node:assert/strict";
import { test } from "node:test";
import { createRequire } from "node:module";
import { mkdtemp, writeFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { basename, dirname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { ESLint } from "eslint";
import * as parser from "@typescript-eslint/parser";
import { Minimatch, minimatch } from "minimatch";

const root = fileURLToPath(new URL("../", import.meta.url));

test("patched minimatch is resolved by every installed lint consumer, in CJS and ESM", () => {
  for (const consumer of ["eslint", "@eslint/config-array", "@typescript-eslint/typescript-estree"]) {
    const require = createRequire(import.meta.resolve(consumer));
    assert.equal(require("minimatch/package.json").version, "11.0.1", consumer);
    const { Minimatch: CommonJsMatcher } = require("minimatch");
    assert.equal(new CommonJsMatcher("**/*.{ts,tsx}", { dot: true }).match("src/pages/Auth/Login.tsx"), true);
  }
  const matcher = new Minimatch("src/**/*.{ts,tsx}", { dot: true });
  assert.equal(matcher.match("src/pages", true), true); // ESLint directory traversal.
  assert.equal(matcher.match("src/pages/Auth/Login.tsx"), true);
  assert.equal(matcher.match("src/pages/Auth/Login.svg"), false);
  assert.equal(minimatch(".config.ts", "*.ts", { dot: true }), true); // Parser allowDefaultProject.
});

test("actual ESLint flat configuration selects TS/TSX, ignores dist and lints with the TypeScript parser", async () => {
  const lint = new ESLint({ cwd: root });
  for (const file of ["src/pages/Auth/Login.tsx", "src/utils/example.ts"]) {
    assert.equal(await lint.isPathIgnored(file), false);
    const config = await lint.calculateConfigForFile(file);
    assert.ok(config.languageOptions.parser);
  }
  assert.equal(await lint.isPathIgnored("dist/example.ts"), true);
  const results = await lint.lintText("export const value: number = 1;\n", { filePath: "src/utils/compatibility.ts" });
  assert.equal(results[0].fatalErrorCount, 0);
  assert.equal(results[0].errorCount, 0);
});

test("type-aware parser project-service allowDefaultProject still works with patched minimatch", async () => {
  const directory = await mkdtemp(join(tmpdir(), "kps-parser-compat-"));
  try {
    const file = join(directory, "compat.config.ts");
    const source = "const value: number = 1;";
    await writeFile(file, source);
    const parsed = parser.parseForESLint(source, { filePath: file, tsconfigRootDir: directory,
      projectService: { allowDefaultProject: ["*.ts"] } });
    assert.ok(parsed.services.program.getSourceFile(file));
    assert.equal(parsed.ast.body[0].type, "VariableDeclaration");
  } finally {
    assert.equal(resolve(dirname(directory)), resolve(tmpdir()));
    assert.ok(basename(directory).startsWith("kps-parser-compat-"));
    await rm(directory, { recursive: true, force: true });
  }
});
