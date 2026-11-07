import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import ComponentCard from "@/components/common/ComponentCard";
import { AiIcon } from "@/icons";
import type { FacebookAnalyticsData } from "@/types/facebookAnalytics";
import { observationDate } from "@/utils/facebookAnalytics";
interface Props {
  data: FacebookAnalyticsData;
  busy: boolean;
  onGenerate: () => void;
  onPost: (id: number) => void;
}
export default function AnalyticsAiPanel({
  data,
  busy,
  onGenerate,
  onPost,
}: Props) {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  const a = data.analysis;
  const pending =
    ["queued", "running"].includes(a?.status ?? "") && !a?.interrupted;
  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-kps-primary/20 bg-kps-primary/5 p-5 dark:bg-kps-primary/10">
        <div>
          <h2 className="flex items-center gap-2 font-semibold text-gray-800 dark:text-gray-100">
            <AiIcon className="size-5 text-kps-primary" />
            {t("aiTitle")}
          </h2>
          <p className="mt-2 max-w-2xl text-xs leading-relaxed text-gray-500 dark:text-gray-400">
            {t("aiPolicy")}
          </p>
        </div>
        <Button
          onClick={onGenerate}
          disabled={
            busy ||
            pending ||
            !data.ai_enabled ||
            data.connection_status !== "connected" ||
            !data.principal_available ||
            !data.posts.some((p) => p.sample_count >= 2)
          }
        >
          {busy || pending ? t("aiPending") : t("generate")}
        </Button>
      </div>
      {!data.ai_enabled && (
        <p className="text-sm text-warning-700 dark:text-warning-400">
          {t("aiDisabled")}
        </p>
      )}
      {a?.stale && (
        <p
          role="status"
          className="text-sm text-warning-700 dark:text-warning-400"
        >
          {t("stale")}
        </p>
      )}
      {a?.interrupted && (
        <p
          role="alert"
          className="text-sm text-warning-700 dark:text-warning-400"
        >
          {t("interrupted")}
        </p>
      )}
      {a?.status === "failed" && (
        <p role="alert" className="text-sm text-error-600 dark:text-error-400">
          {t("aiFailed")}
        </p>
      )}
      {a?.result ? (
        <div className="grid gap-5 xl:grid-cols-3">
          <ComponentCard
            title={t("aiResult")}
            className="xl:col-span-2"
            compact
          >
            <p className="text-xs text-gray-400">
              {t("generatedAt", { at: observationDate(a.finished_at) })}
            </p>
            {a.result.insights.map((row, i) => (
              <section key={i} className="space-y-2">
                <h3 className="text-sm font-semibold text-gray-800 dark:text-gray-100">
                  {t(`insightKinds.${row.kind}`)}
                </h3>
                <p className="text-sm leading-relaxed whitespace-pre-line text-gray-600 dark:text-gray-300">
                  {row.text}
                </p>
                <div className="flex flex-wrap gap-2">
                  {row.post_ids.map((id) => (
                    <button
                      key={id}
                      type="button"
                      onClick={() => onPost(id)}
                      className="rounded-lg border border-kps-primary/20 bg-kps-primary/5 px-3 py-1 text-xs text-kps-primary hover:underline dark:text-brand-300"
                    >
                      {t("evidencePost", { id })}
                    </button>
                  ))}
                </div>
              </section>
            ))}
          </ComponentCard>
          <ComponentCard title={t("aiScope")} compact>
            <p className="text-sm text-gray-600 dark:text-gray-300">
              {t("selectedAiPosts", {
                selected: a.selected_posts,
                total: a.candidate_posts,
              })}
            </p>
            <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
              {t("aiSourcePolicy")}
            </p>
            <p className="text-xs leading-relaxed text-warning-700 dark:text-warning-400">
              {t("noAttribution")}
            </p>
          </ComponentCard>
        </div>
      ) : (
        <div className="rounded-xl border border-gray-200 bg-white p-8 text-center text-sm text-gray-500 dark:border-gray-800 dark:bg-white/3 dark:text-gray-400">
          {pending ? t("aiPending") : t("noAnalysis")}
        </div>
      )}
    </div>
  );
}
