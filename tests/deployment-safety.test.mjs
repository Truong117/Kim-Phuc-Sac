import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

import {
  createVercelConfig,
  normalizeBackendOrigin,
} from "../deployment/vercel-config.mjs";

test("Vercel config fails closed without an environment-specific backend", () => {
  assert.throws(
    () => createVercelConfig(undefined),
    /KPS_BACKEND_ORIGIN must be configured/,
  );
});

test("backend origin rejects unsafe or ambiguous values", () => {
  for (const value of [
    "http://api.example.test",
    "https://user:password@api.example.test",
    "https://api.example.test/base",
    "https://api.example.test?target=production",
    "not-a-url",
  ]) {
    assert.throws(() => normalizeBackendOrigin(value));
  }
});

test("loopback HTTP remains available for local Vercel development", () => {
  assert.equal(
    normalizeBackendOrigin("http://localhost:8000/"),
    "http://localhost:8000",
  );
});

test("API and Sanctum are proxied before the SPA fallback", () => {
  assert.deepEqual(createVercelConfig("https://staging-api.example.test"), {
    rewrites: [
      {
        source: "/api/:path*",
        destination: "https://staging-api.example.test/api/:path*",
      },
      {
        source: "/sanctum/:path*",
        destination: "https://staging-api.example.test/sanctum/:path*",
      },
      { source: "/(.*)", destination: "/index.html" },
    ],
    headers: [
      {
        source: "/api/:path*",
        headers: [
          { key: "x-vercel-enable-rewrite-caching", value: "0" },
        ],
      },
      {
        source: "/sanctum/:path*",
        headers: [
          { key: "x-vercel-enable-rewrite-caching", value: "0" },
        ],
      },
    ],
  });
});

test("Vercel entrypoint reads its server-side deployment environment", async () => {
  const previousOrigin = process.env.KPS_BACKEND_ORIGIN;
  process.env.KPS_BACKEND_ORIGIN = "https://ci-api.example.test";

  try {
    const { config } = await import(`../vercel.mjs?test=${Date.now()}`);
    assert.equal(
      config.rewrites[0].destination,
      "https://ci-api.example.test/api/:path*",
    );
  } finally {
    if (previousOrigin === undefined) {
      delete process.env.KPS_BACKEND_ORIGIN;
    } else {
      process.env.KPS_BACKEND_ORIGIN = previousOrigin;
    }
  }
});

test("frontend explicitly accepts the same-origin API base", async () => {
  const source = await readFile(
    new URL("../src/services/apiClient.ts", import.meta.url),
    "utf8",
  );

  assert.match(source, /configuredApiBaseUrl === ["']\/["']/);
  assert.match(source, /credentials: ["']include["']/);
  assert.match(source, /getCookie\(["']XSRF-TOKEN["']\)/);
});
