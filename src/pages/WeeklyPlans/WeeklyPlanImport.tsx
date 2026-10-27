import ComponentCard from "@/components/common/ComponentCard";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import GoogleDocsPreviewPanel from "@/components/weekly-plans/GoogleDocsPreviewPanel";
import Button from "@/components/ui/button/Button";
import { ApiServiceError } from "@/services/apiClient";
import {
  downloadWeeklyPlanTemplate,
  getWeeklyPlanReferences,
  importWeeklyPlan,
} from "@/services/weeklyPlanningService";
import type { WeeklyPlanReferences } from "@/types/weeklyPlanning";
import { formatDateOnly } from "@/utils/dateOnly";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { useNavigate } from "react-router";

export default function WeeklyPlanImport() {
  const { t } = useTranslation("common", { keyPrefix: "weeklyPlanning" });
  const navigate = useNavigate();
  const [references, setReferences] = useState<WeeklyPlanReferences | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [touched, setTouched] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [downloading, setDownloading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const controller = new AbortController();
    getWeeklyPlanReferences(controller.signal)
      .then((next) => {
        setReferences(next);
        if (!next.actions.can_import) setError(t("errors.forbidden"));
      })
      .catch((nextError: unknown) => {
        if (nextError instanceof DOMException && nextError.name === "AbortError") {
          return;
        }
        if (nextError instanceof ApiServiceError) {
          if (nextError.status === 401 || nextError.status === 419) {
            setError(t("errors.sessionExpired"));
            return;
          }
          if (nextError.status === 403) {
            setError(t("errors.forbidden"));
            return;
          }
          if (nextError.status === 404) {
            setError(t("errors.referencesUnavailable"));
            return;
          }
          if (nextError.status === 0) {
            setError(t("errors.network"));
            return;
          }
        }
        setError(t("errors.loadReferences"));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [t]);

  const fileError =
    (submitted || touched) && !file ? t("import.fileRequired") : undefined;

  const downloadTemplate = async () => {
    setDownloading(true);
    setError(null);
    try {
      const blob = await downloadWeeklyPlanTemplate();
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement("a");
      anchor.href = url;
      anchor.download = "KPS_Ke_hoach_cong_viec_tuan.xlsx";
      anchor.click();
      URL.revokeObjectURL(url);
    } catch {
      setError(t("errors.downloadTemplate"));
    } finally {
      setDownloading(false);
    }
  };

  return (
    <>
      <PageMeta title={t("import.metaTitle")} description={t("import.metaDescription")} />
      <PageBreadCrumb pageTitle={t("import.title")} />
      {loading ? (
        <div className="h-80 animate-pulse rounded-2xl bg-gray-100 dark:bg-gray-800" />
      ) : (
        <div className="space-y-6">
          {references?.actions.can_import && (
            <GoogleDocsPreviewPanel
              onImported={(plan) => navigate("/weekly-plans/" + plan.id)}
            />
          )}
          <ComponentCard title={t("import.cardTitle")} desc={t("import.cardDescription")} compact>
            {references && (
              <p className="rounded-xl bg-kps-primary/8 px-4 py-3 text-theme-sm text-gray-700 dark:text-gray-300">
                {t("import.period", {
                  start: formatDateOnly(references.next_week.start),
                  end: formatDateOnly(references.next_week.end),
                })}
              </p>
            )}
            {error && (
              <p role="alert" className="rounded-lg bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400">
                {error}
              </p>
            )}
            <form
              className="space-y-5"
              noValidate
              onSubmit={async (event) => {
                event.preventDefault();
                setSubmitted(true);
                if (!file || saving || !references?.actions.can_import) return;
                setSaving(true);
                setError(null);
                try {
                  const plan = await importWeeklyPlan(file);
                  navigate("/weekly-plans/" + plan.id);
                } catch (nextError) {
                  if (nextError instanceof ApiServiceError) {
                    const fileError = nextError.validationErrors?.file?.[0];
                    setError(
                      fileError ??
                        (nextError.status === 409
                          ? nextError.message
                          : t("errors.import")),
                    );
                  } else {
                    setError(t("errors.import"));
                  }
                } finally {
                  setSaving(false);
                }
              }}
            >
              <div>
                <label
                  htmlFor="weekly-plan-file"
                  className="mb-2 block text-theme-sm font-medium text-gray-700 dark:text-gray-300"
                >
                  {t("import.fileLabel")}
                </label>
                <input
                  id="weekly-plan-file"
                  type="file"
                  accept=".xlsx,.docx"
                  onBlur={() => setTouched(true)}
                  onChange={(event) => {
                    setFile(event.target.files?.[0] ?? null);
                    setError(null);
                  }}
                  className="block w-full rounded-lg border border-gray-300 bg-white p-3 text-theme-sm text-gray-700 file:me-4 file:rounded-lg file:border-0 file:bg-kps-primary/10 file:px-4 file:py-2 file:font-semibold file:text-kps-primary hover:file:bg-kps-primary/15 focus-visible:ring-3 focus-visible:ring-kps-primary/25 focus-visible:outline-none dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
                />
                {fileError && <p className="mt-1.5 text-theme-xs text-error-500">{fileError}</p>}
                <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
                  {t("import.fileHint")}
                </p>
              </div>
              <div className="flex flex-col-reverse gap-3 sm:flex-row sm:justify-between">
                <Button
                  type="button"
                  variant="outline"
                  disabled={downloading}
                  onClick={() => void downloadTemplate()}
                >
                  {downloading ? t("import.downloading") : t("import.downloadTemplate")}
                </Button>
                <div className="flex gap-3">
                  <Button type="button" variant="outline" onClick={() => navigate("/weekly-plans")}>
                    {t("actions.cancel")}
                  </Button>
                  <Button
                    type="submit"
                    disabled={saving || !references?.actions.can_import}
                    className="bg-kps-primary hover:bg-kps-primary-hover"
                  >
                    {saving ? t("import.processing") : t("import.submit")}
                  </Button>
                </div>
              </div>
            </form>
          </ComponentCard>
        </div>
      )}
    </>
  );
}
