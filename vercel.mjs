import { createVercelConfig } from "./deployment/vercel-config.mjs";

export const config = createVercelConfig(process.env.KPS_BACKEND_ORIGIN);
