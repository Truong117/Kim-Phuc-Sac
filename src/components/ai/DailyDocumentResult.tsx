import type { ReactNode } from "react";
import { useTranslation } from "react-i18next";
import type { DailyDocument } from "@/types/dailyDocument";

type Result = NonNullable<DailyDocument["result"]>;
const tones = {
  summary:
    "border-brand-100 bg-brand-25 dark:border-brand-800 dark:bg-brand-500/5",
  clarification:
    "border-warning-100 bg-warning-50 dark:border-warning-800 dark:bg-warning-500/10",
  review:
    "border-blue-light-100 bg-blue-light-50 dark:border-blue-light-800 dark:bg-blue-light-500/10",
  support:
    "border-gray-200 bg-gray-50 dark:border-gray-700 dark:bg-gray-800/50",
};

function ResultSection({
  title,
  tone,
  children,
}: {
  title: string;
  tone: keyof typeof tones;
  children: ReactNode;
}) {
  return (
    <section className={`rounded-xl border p-4 ${tones[tone]}`}>
      <h3 className="font-semibold text-gray-900 dark:text-gray-100">
        {title}
      </h3>
      <div className="mt-2 leading-7 break-words whitespace-pre-wrap">
        {children}
      </div>
    </section>
  );
}

export default function DailyDocumentResult({ result }: { result: Result }) {
  const { t } = useTranslation("common", { keyPrefix: "dailyDocuments" });
  const quantified = result.work_totals.filter(
    (total) =>
      total.status === "confirmed" &&
      total.total !== null &&
      Number.isFinite(total.total) &&
      total.total >= 0,
  );
  const unresolved = result.work_totals.filter(
    (total) => !quantified.includes(total),
  );
  const summary = result.work_groups
    ?.map((group) => group.text.trim())
    .join(" ");
  return (
    <div className="space-y-3">
      <ResultSection title={t("reportSummary")} tone="summary">
        <p>{summary || t("noGroupedWork")}</p>
        {quantified.length > 0 && (
          <p className="mt-3 text-success-700 dark:text-success-400">
            <span className="font-medium">{t("reportedMetrics")}: </span>
            {quantified
              .map((total) => `${total.metric}: ${total.total} ${total.unit}`)
              .join("; ")}
          </p>
        )}
      </ResultSection>
      <ResultSection title={t("clarifications")} tone="clarification">
        {unresolved.length > 0 ? (
          <p>
            {t("unresolvedTotalsHint")}{" "}
            {unresolved.map((total) => total.metric).join("; ")}.
          </p>
        ) : (
          <p>{t("noClarifications")}</p>
        )}
      </ResultSection>
      <ResultSection title={t("review")} tone="review">
        <p>
          {result.comments.length
            ? result.comments.map((entry) => entry.text).join(" ")
            : t("noComments")}
        </p>
      </ResultSection>
      <ResultSection title={t("recommendations")} tone="support">
        <p>
          {result.recommendations.length
            ? result.recommendations.map((entry) => entry.text).join(" ")
            : t("noRecommendations")}
        </p>
      </ResultSection>
    </div>
  );
}
