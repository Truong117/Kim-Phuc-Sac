const LOOPBACK_HOSTS = new Set(["localhost", "127.0.0.1", "[::1]"]);

export const normalizeBackendOrigin = (value) => {
  if (typeof value !== "string" || value.trim() === "") {
    throw new Error(
      "KPS_BACKEND_ORIGIN must be configured for this Vercel environment.",
    );
  }

  let url;

  try {
    url = new URL(value.trim());
  } catch {
    throw new Error("KPS_BACKEND_ORIGIN must be a valid absolute URL.");
  }

  if (url.username || url.password) {
    throw new Error("KPS_BACKEND_ORIGIN must not contain credentials.");
  }

  if (url.pathname !== "/" || url.search || url.hash) {
    throw new Error(
      "KPS_BACKEND_ORIGIN must contain only the backend origin, without a path, query, or fragment.",
    );
  }

  const isLoopback = LOOPBACK_HOSTS.has(url.hostname);
  if (url.protocol !== "https:" && !(url.protocol === "http:" && isLoopback)) {
    throw new Error(
      "KPS_BACKEND_ORIGIN must use HTTPS outside local development.",
    );
  }

  return url.origin;
};

export const createVercelConfig = (backendOrigin) => {
  const origin = normalizeBackendOrigin(backendOrigin);

  return {
    rewrites: [
      { source: "/api/:path*", destination: `${origin}/api/:path*` },
      {
        source: "/sanctum/:path*",
        destination: `${origin}/sanctum/:path*`,
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
  };
};
