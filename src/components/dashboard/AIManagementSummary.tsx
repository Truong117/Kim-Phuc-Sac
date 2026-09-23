import Badge from "@/components/ui/badge/Badge";
import { AiIcon } from "@/icons";
import type { AIManagementInsight } from "@/types/dashboard";
import { useTranslation } from "react-i18next";

type AIManagementSummaryProps = AIManagementInsight;

export default function AIManagementSummary({
  summary,
  highlights,
}: AIManagementSummaryProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "managementDashboard.aiSummary",
  });

  return (
    <section className="rounded-2xl border border-brand-100 bg-brand-25 p-5 shadow-theme-xs sm:p-6 dark:border-brand-500/20 dark:bg-brand-500/10">
      <div className="flex items-start justify-between gap-4">
        <div className="flex items-center gap-3">
          <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-500 text-white">
            <AiIcon className="size-6" />
          </span>
          <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
            {t("title")}
          </h2>
        </div>
        <Badge color="primary">{t("badge")}</Badge>
      </div>

      <p className="mt-5 text-theme-sm leading-6 text-gray-700 dark:text-gray-300">
        {summary}
      </p>

      <div className="mt-6 border-t border-brand-100 pt-5 dark:border-brand-500/20">
        <h3 className="font-semibold text-gray-900 dark:text-white">
          {t("attention")}
        </h3>
        <ul className="mt-3 space-y-3">
          {highlights.map((highlight) => (
            <li
              key={highlight}
              className="flex gap-3 text-theme-sm text-gray-600 dark:text-gray-300"
            >
              <span className="mt-2 size-1.5 shrink-0 rounded-full bg-brand-500" />
              <span>{highlight}</span>
            </li>
          ))}
        </ul>
      </div>
    </section>
  );
}
