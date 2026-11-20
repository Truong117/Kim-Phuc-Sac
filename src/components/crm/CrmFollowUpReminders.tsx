import { useTranslation } from "react-i18next";
import type {
  CrmFollowUpSummary,
  CrmFollowUpWindow,
} from "@/services/crmService";
import { formatDateOnly } from "@/utils/dateOnly";

interface Props {
  summary?: CrmFollowUpSummary;
  selected?: CrmFollowUpWindow;
  loading: boolean;
  onSelect: (window?: CrmFollowUpWindow) => void;
}

export default function CrmFollowUpReminders({
  summary,
  selected,
  loading,
  onSelect,
}: Props) {
  const { t } = useTranslation("common", { keyPrefix: "crm.reminders" });
  return (
    <section
      aria-labelledby="crm-follow-up-heading"
      aria-busy={loading}
      className="flex flex-wrap items-center gap-x-6 gap-y-3 rounded-2xl border border-gray-200 bg-white px-4 py-3 sm:px-6 dark:border-gray-800 dark:bg-white/[0.03]"
    >
      <div>
        <h2
          id="crm-follow-up-heading"
          className="text-base font-semibold text-gray-800 dark:text-white/90"
        >
          {t("title")}
        </h2>
        {summary && (
          <p className="text-xs text-gray-500 dark:text-gray-400">
            {formatDateOnly(summary.business_date)} –{" "}
            {formatDateOnly(summary.upcoming_through)}
          </p>
        )}
      </div>
      <div className="flex flex-wrap items-center gap-2">
        {(["today", "upcoming"] as const).map((group) => (
          <button
            key={group}
            type="button"
            aria-pressed={selected === group}
            disabled={!summary}
            onClick={() => onSelect(selected === group ? undefined : group)}
            className="inline-flex min-h-11 items-center gap-2 rounded-lg border border-gray-200 px-3 text-start text-sm text-gray-700 hover:border-kps-primary focus-visible:ring-2 focus-visible:ring-kps-primary focus-visible:outline-none disabled:opacity-60 aria-pressed:border-kps-primary aria-pressed:bg-kps-primary/5 dark:border-gray-700 dark:text-gray-200 dark:aria-pressed:bg-kps-primary/15"
          >
            {t(group)}
            <span className="rounded-md bg-brand-50 px-2 py-0.5 font-semibold text-kps-primary tabular-nums dark:bg-kps-primary/20 dark:text-brand-200">
              {summary ? t("count", { count: summary[group] }) : "—"}
            </span>
          </button>
        ))}
        {selected && (
          <button
            type="button"
            onClick={() => onSelect()}
            className="min-h-11 rounded-lg px-3 text-sm font-medium text-kps-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-kps-primary focus-visible:outline-none dark:text-brand-200"
          >
            {t("all")}
          </button>
        )}
      </div>
    </section>
  );
}
