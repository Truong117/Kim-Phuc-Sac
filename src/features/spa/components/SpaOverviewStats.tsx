import { useTranslation } from "react-i18next";
import {
  CalenderIcon,
  CheckCircleIcon,
  MultiUserIcon,
  TimeIcon,
} from "@/icons";
import { getSpaOverview } from "../selectors";
import type { SpaPreviewSnapshot } from "../types";

const metrics = [
  { key: "appointments", icon: CalenderIcon },
  { key: "waiting", icon: MultiUserIcon },
  { key: "inProgress", icon: TimeIcon },
  { key: "completed", icon: CheckCircleIcon },
] as const;

export default function SpaOverviewStats({
  snapshot,
}: {
  snapshot: SpaPreviewSnapshot;
}) {
  const { t } = useTranslation();
  const totals = getSpaOverview(snapshot);
  return (
    <dl className="grid grid-cols-2 gap-3 xl:grid-cols-4">
      {metrics.map(({ key, icon: Icon }) => (
        <div
          key={key}
          className="rounded-2xl border border-gray-200 bg-white p-4 sm:p-5 dark:border-gray-800 dark:bg-gray-900"
        >
          <Icon
            className="mb-4 size-6 text-kps-primary dark:text-gray-300"
            aria-hidden="true"
          />
          <dt className="text-sm text-gray-500 dark:text-gray-400">
            {t(`spa.metrics.${key}`)}
          </dt>
          <dd className="mt-2 text-title-sm font-semibold text-gray-900 dark:text-white">
            {totals[key]}
          </dd>
        </div>
      ))}
    </dl>
  );
}
