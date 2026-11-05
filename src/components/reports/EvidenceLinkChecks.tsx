import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import { ApiServiceError, apiRequest } from "@/services/apiClient";
import { isEvidenceUrl, parseEvidenceLinks } from "@/utils/reportEvidence";

type Check = {
  url: string;
  status:
    | "matched"
    | "identified"
    | "unverified"
    | "pending"
    | "unsupported"
    | "invalid"
    | "checking"
    | "rate_limited";
  page_name?: string;
  reason_code?: string;
};

const reasonMessages: Record<string, string> = {
  SOCIAL_RECONNECT_REQUIRED: "evidenceCheck.connectionExpired",
  SOCIAL_RATE_LIMITED: "evidenceCheck.rate_limited",
  SOCIAL_PERMISSION_OR_OBJECT_UNAVAILABLE: "evidenceCheck.pageUnavailable",
  SOCIAL_PROVIDER_UNAVAILABLE: "evidenceCheck.pageUnavailable",
  SOCIAL_NOT_CONFIGURED: "evidenceCheck.connectionUnavailable",
  SOCIAL_PRINCIPAL_UNAVAILABLE: "evidenceCheck.connectionUnavailable",
  SOCIAL_CONNECTION_UNAVAILABLE: "evidenceCheck.connectionUnavailable",
  SOCIAL_CHECK_PENDING: "evidenceCheck.matchPending",
  SOCIAL_POST_ALIAS_UNAVAILABLE: "evidenceCheck.aliasUnavailable",
};

const isFacebook = (value: string) => {
  try {
    return /^(?:(?:www|m|web)\.)?(?:facebook\.com|fb\.com)$/.test(
      new URL(value).hostname,
    );
  } catch {
    return false;
  }
};

export default function EvidenceLinkChecks({ value }: { value: string }) {
  const { t } = useTranslation();
  const [retry, setRetry] = useState(0);
  const [result, setResult] = useState<{ key: string; rows: Check[] } | null>(
    null,
  );
  const sourceUrls = [...new Set(parseEvidenceLinks(value))];
  const urls = sourceUrls.filter(isFacebook).slice(0, 10);
  const key = JSON.stringify(urls);
  const initial: Check[] = urls.map((url) => ({
    url,
    status: !isEvidenceUrl(url)
      ? "invalid"
      : isFacebook(url)
        ? "checking"
        : "unsupported",
  }));
  const rows = result?.key === key ? result.rows : initial;

  useEffect(() => {
    const current: string[] = JSON.parse(key);
    const candidates = current.filter(
      (url) => isEvidenceUrl(url) && isFacebook(url),
    );
    if (!candidates.length) return;
    const controller = new AbortController();
    let active = true;
    const timer = window.setTimeout(async () => {
      setResult({
        key,
        rows: current.map((url) => ({
          url,
          status: !isEvidenceUrl(url)
            ? "invalid"
            : isFacebook(url)
              ? "checking"
              : "unsupported",
        })),
      });
      const timeout = window.setTimeout(() => controller.abort(), 15_000);
      let checked: Check[];
      try {
        const response = await apiRequest<{ data: Check[] }>(
          "/api/reports/check-links",
          {
            method: "POST",
            body: JSON.stringify({ urls: candidates }),
            signal: controller.signal,
          },
        );
        checked = Array.isArray(response.data) ? response.data : [];
      } catch (error) {
        checked = candidates.map((url) => ({
          url,
          status:
            error instanceof ApiServiceError && error.status === 429
              ? "rate_limited"
              : "unverified",
        }));
      } finally {
        window.clearTimeout(timeout);
      }
      if (active) {
        setResult({
          key,
          rows: current.map(
            (url) =>
              checked.find((row) => row.url === url) ?? {
                url,
                status: !isEvidenceUrl(url)
                  ? "invalid"
                  : isFacebook(url)
                    ? "unverified"
                    : "unsupported",
              },
          ),
        });
      }
    }, 700);
    return () => {
      active = false;
      window.clearTimeout(timer);
      controller.abort();
    };
  }, [key, retry]);

  if (!urls.length) return null;
  const retryable = rows.some((row) =>
    ["identified", "unverified", "pending", "rate_limited"].includes(
      row.status,
    ),
  );
  return (
    <div className="mt-2 space-y-2 text-theme-xs" data-evidence-link-checks>
      <ul className="space-y-1" aria-live="polite" aria-atomic="true">
        {rows.map((row) => (
          <li
            key={row.url}
            className={
              row.status === "matched"
                ? "text-success-700 dark:text-success-400"
                : row.status === "invalid"
                  ? "text-error-600 dark:text-error-400"
                  : "text-gray-600 dark:text-gray-300"
            }
          >
            {t("evidenceCheck.number", {
              number: sourceUrls.indexOf(row.url) + 1,
            })}{" "}
            {t(
              row.status === "identified" &&
                row.reason_code &&
                reasonMessages[row.reason_code]
                ? reasonMessages[row.reason_code]
                : `evidenceCheck.${["matched", "identified", "unverified", "pending", "unsupported", "invalid", "checking", "rate_limited"].includes(row.status) ? row.status : "unverified"}`,
              { page: row.page_name ?? "" },
            )}
          </li>
        ))}
      </ul>
      <p className="text-gray-500 dark:text-gray-400">
        {t("evidenceCheck.advisory")}
      </p>
      {retryable && (
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => setRetry((value) => value + 1)}
        >
          {t("evidenceCheck.retry")}
        </Button>
      )}
    </div>
  );
}
