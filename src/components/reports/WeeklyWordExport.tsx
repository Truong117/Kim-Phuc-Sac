import { useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import { apiDownload, ApiServiceError } from "@/services/apiClient";
import type { WorkReportAnalysis } from "@/types/workReportAnalysis";

type Target =
  | { kind: "company"; analysis: number }
  | { kind: "employee"; analysis: number; employee: number; name: string }
  | { kind: "personal"; version: string; name: string };

interface Props {
  period: WorkReportAnalysis["period"];
  target: Target;
}

export default function WeeklyWordExport({ period, target }: Props) {
  const { t } = useTranslation("common", { keyPrefix: "weeklyWord" });
  const [mode, setMode] = useState("full");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const pending = useRef<AbortController | null>(null);
  useEffect(() => () => pending.current?.abort(), []);

  async function download() {
    if (pending.current) return;
    const controller = new AbortController();
    pending.current = controller;
    setBusy(true);
    setError("");
    try {
      const path =
        target.kind === "personal"
          ? "/api/reports/my-weekly-summary/word?" +
            new URLSearchParams({
              end_date: period.end_date,
              version: target.version,
            })
          : "/api/ai/work-report-analyses/" +
            target.analysis +
            "/word?" +
            new URLSearchParams(
              target.kind === "employee"
                ? { mode: "employee", employee_id: String(target.employee) }
                : { mode },
            );
      const blob = await apiDownload(path, controller.signal);
      if (controller.signal.aborted) return;
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      const name =
        target.kind === "company"
          ? mode === "full"
            ? "Tong_hop_va_nhan_vien"
            : "Tong_hop"
          : target.name.replace(/[^\p{L}\p{N}_-]+/gu, "_");
      a.download =
        ["KPS_Bao_cao_tuan", name, period.start_date, period.end_date].join(
          "_",
        ) + ".docx";
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
      if (!controller.signal.aborted)
        setError(
          t(
            e instanceof ApiServiceError && e.status === 409
              ? "changed"
              : "error",
          ),
        );
    } finally {
      if (!controller.signal.aborted) setBusy(false);
      pending.current = null;
    }
  }

  return (
    <div className="mb-4 flex flex-wrap items-center gap-2">
      {target.kind === "company" && (
        <Select
          aria-label={t("scope")}
          placeholder={t("scope")}
          disabled={busy}
          value={mode}
          onChange={setMode}
          options={[
            { value: "full", label: t("full") },
            { value: "summary", label: t("summary") },
          ]}
        />
      )}
      <Button
        size="sm"
        variant="outline"
        disabled={busy}
        onClick={() => void download()}
      >
        {t(busy ? "downloading" : "download")}
      </Button>
      {error && (
        <p role="alert" className="text-sm text-error-700 dark:text-error-300">
          {error}
        </p>
      )}
    </div>
  );
}
