import { useEffect, useState } from "react";
import { apiRequest } from "@/services/apiClient";
import type { FacebookReportPage } from "@/types/reports";

// One request per report editor, never one per item or a Meta catalogue scan.
export function useFacebookReportPages(
  actor: string | number | null,
  enabled: boolean,
): FacebookReportPage[] {
  const key = enabled && actor !== null ? String(actor) : "";
  const [result, setResult] = useState<{
    key: string;
    pages: FacebookReportPage[];
  } | null>(null);
  useEffect(() => {
    if (!key) return;
    let active = true;
    let lastAttempt = 0;
    let request: AbortController | null = null;
    let timeout: number | undefined;
    const load = async () => {
      if (
        document.visibilityState === "hidden" ||
        Date.now() - lastAttempt < 5000
      )
        return;
      lastAttempt = Date.now();
      request?.abort();
      window.clearTimeout(timeout);
      request = new AbortController();
      const current = request;
      timeout = window.setTimeout(() => current.abort(), 10_000);
      try {
        const response = await apiRequest<{ data: FacebookReportPage[] }>(
          "/api/reports/facebook-pages",
          { signal: current.signal },
        );
        if (active && request === current && !current.signal.aborted) {
          const pages = Array.isArray(response.data)
            ? response.data.filter(
                (page) =>
                  Number.isInteger(page.id) && typeof page.name === "string",
              )
            : [];
          setResult({ key, pages });
        }
      } catch {
        // Failure is closed: no assigned Page data means no picker controls.
        if (active && request === current) setResult({ key, pages: [] });
      } finally {
        if (request === current) window.clearTimeout(timeout);
      }
    };
    const timer = window.setTimeout(() => {
      void load();
    }, 0);
    const refresh = () => {
      void load();
    };
    window.addEventListener("focus", refresh);
    document.addEventListener("visibilitychange", refresh);
    return () => {
      active = false;
      window.clearTimeout(timer);
      window.clearTimeout(timeout);
      request?.abort();
      window.removeEventListener("focus", refresh);
      document.removeEventListener("visibilitychange", refresh);
    };
  }, [key]);
  return result?.key === key && key ? result.pages : [];
}
