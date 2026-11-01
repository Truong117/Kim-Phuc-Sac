import react from "@vitejs/plugin-react";
import { defineConfig, loadEnv } from "vite";
import svgr from "vite-plugin-svgr";
import { fileURLToPath } from "node:url";

import { assertNoFrontendCredentials } from "./deployment/frontend-environment-safety.mjs";

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const loadedEnvironment = loadEnv(mode, process.cwd(), "VITE_");

  assertNoFrontendCredentials({
    ...loadedEnvironment,
    ...process.env,
  });

  return {
    resolve: {
      alias: {
        "@": fileURLToPath(new URL("./src", import.meta.url)),
      },
    },
    plugins: [
      {
        name: "kps-release-metadata",
        generateBundle() {
          const sha = process.env.VERCEL_GIT_COMMIT_SHA ?? process.env.GITHUB_SHA;
          this.emitFile({
            type: "asset",
            fileName: "release.json",
            source: JSON.stringify({
              sha: sha && /^[a-f0-9]{40}$/.test(sha) ? sha : null,
              environment: process.env.VITE_KPS_ENVIRONMENT ?? "development",
            }),
          });
        },
      },
      react(),
      svgr({
        svgrOptions: {
          icon: true,
          // This will transform your SVG to a React component
          exportType: "named",
          namedExport: "ReactComponent",
        },
      }),
    ],
    build: {
      rolldownOptions: {
        onwarn(warning, warn) {
          // Skip eval warnings from react-jvectormap
          if (
            warning.code === "EVAL" &&
            warning.id?.includes("@react-jvectormap")
          ) {
            return;
          }
          warn(warning);
        },
      },
    },
  };
});
