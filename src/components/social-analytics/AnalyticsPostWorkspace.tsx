import { useState } from "react";
import { useTranslation } from "react-i18next";
import AnalyticsContent from "./AnalyticsContent";
import AnalyticsMilestones from "./AnalyticsMilestones";
import type { FacebookAnalyticsData } from "@/types/facebookAnalytics";

export default function AnalyticsPostWorkspace({
  data,
  onPost,
}: {
  data: FacebookAnalyticsData;
  onPost: (id: number) => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  const [view, setView] = useState("current");
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        {["current", "milestones"].map((key) => (
          <button
            key={key}
            type="button"
            aria-pressed={view === key}
            onClick={() => setView(key)}
            className={
              "min-h-10 rounded-lg border px-3 py-2 text-xs " +
              (view === key
                ? "border-kps-primary bg-kps-primary/10 text-kps-primary dark:text-brand-300"
                : "border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300")
            }
          >
            {t("review.postViews." + key)}
          </button>
        ))}
      </div>
      {view === "current" ? (
        <AnalyticsContent data={data} onPost={onPost} />
      ) : (
        <AnalyticsMilestones data={data} onPost={onPost} />
      )}
    </div>
  );
}
