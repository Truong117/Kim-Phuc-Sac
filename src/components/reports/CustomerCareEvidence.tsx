import { useState } from "react";
import { useTranslation } from "react-i18next";
import { downloadCareEvidence } from "@/services/customerCareService";
import type { CareReportEntry } from "@/types/tasks";
import { isEvidenceUrl } from "@/utils/reportEvidence";

export default function CustomerCareEvidence({
  taskId,
  entries,
}: {
  taskId: number;
  entries: CareReportEntry[];
}) {
  const { t } = useTranslation("common", { keyPrefix: "customerCare" });
  const [error, setError] = useState("");
  const [pending, setPending] = useState<number | null>(null);
  const rows = entries.filter(
    (e) => e.evidence_links.some(Boolean) || e.attachment_ids.length > 0,
  );
  if (rows.length === 0) return null;
  return (
    <details className="mt-3 space-y-2 text-theme-xs text-gray-600 dark:text-gray-300">
      <summary className="cursor-pointer text-kps-primary dark:text-sidebar-selected">
        {t("savedEvidence")}
      </summary>
      {rows.map((entry) => (
        <div key={entry.client_key} className="space-y-1">
          <p className="font-medium">
            {entry.name || entry.new_customer?.name}
          </p>
          {entry.evidence_links.filter(isEvidenceUrl).map((url) => (
            <p key={url}>
              <a
                href={url}
                target="_blank"
                rel="noopener noreferrer"
                className="break-all text-kps-primary underline dark:text-sidebar-selected"
              >
                {url}
              </a>
            </p>
          ))}
          {entry.attachment_ids.map((id) => (
            <p key={id}>
              <button
                type="button"
                disabled={pending !== null}
                className="text-kps-primary underline disabled:opacity-50 dark:text-sidebar-selected"
                onClick={() => {
                  setError("");
                  setPending(id);
                  void downloadCareEvidence(
                    taskId,
                    id,
                    entry.attachment_names?.[id] || `evidence-${id}`,
                  )
                    .catch(() => setError(t("downloadError")))
                    .finally(() => setPending(null));
                }}
              >
                {t(pending === id ? "downloading" : "fileId", { id })}
              </button>
            </p>
          ))}
        </div>
      ))}
      {error && (
        <p role="alert" className="text-error-600 dark:text-error-400">
          {error}
        </p>
      )}
    </details>
  );
}
