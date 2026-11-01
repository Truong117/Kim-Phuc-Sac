import react from "@vitejs/plugin-react";
import { defineConfig, loadEnv } from "vite";
import svgr from "vite-plugin-svgr";

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
        "@": "/src",
      },
    },
    plugins: [
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
