import { readFile } from "node:fs/promises";
import { resolve, dirname } from "node:path";
import ts from "typescript";
const cache = new Map();
async function urlFor(path) {
  if (cache.has(path)) return cache.get(path);
  let compiled = ts.transpileModule(await readFile(path, "utf8"), {
    compilerOptions: {
      module: ts.ModuleKind.ESNext,
      target: ts.ScriptTarget.ES2023,
    },
  }).outputText;
  const imports = [...compiled.matchAll(/from\s+["'](\.[^"']+)["']/g)];
  for (const match of imports) {
    const dependency = resolve(dirname(path), `${match[1]}.ts`);
    compiled = compiled.replace(
      match[0],
      `from ${JSON.stringify(await urlFor(dependency))}`,
    );
  }
  const url = `data:text/javascript;base64,${Buffer.from(compiled).toString("base64")}`;
  cache.set(path, url);
  return url;
}
export const moduleFrom = async (path) => import(await urlFor(resolve(path)));
