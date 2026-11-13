import { Link } from "react-router";
import { useTranslation } from "react-i18next";
import { useAuthorization } from "@/hooks/useAuthorization";
import { canOpenWorkspacePath } from "@/utils/workspace";
import type { SpaAppointment } from "../types";
import SpaPanel from "./SpaPanel";
import SpaAvatar from "./SpaAvatar";
export default function SpaAppointmentWaiting({
  appointments,
  date,
}: {
  appointments: SpaAppointment[];
  date: string;
}) {
  const { t } = useTranslation();
  const { workspaces } = useAuthorization();
  const waiting = appointments.filter(
    (a) =>
      a.status === "booked" ||
      (a.status === "arrived" &&
        a.tasks.some((task) =>
          ["unassigned", "assigned"].includes(task.status),
        )),
  );
  return (
    <SpaPanel
      title={t("spa.concept.waitingQueue")}
      action={
        <span className="rounded-md bg-gray-100 px-2 py-1 text-theme-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">
          {waiting.length}
        </span>
      }
    >
      <div className="divide-y divide-gray-100 px-5 dark:divide-gray-800">
        {waiting.slice(0, 4).map((a) => (
          <div key={a.id} className="py-4">
            <div className="flex items-center gap-2">
              <SpaAvatar name={a.customerName} />
              <div className="min-w-0">
                <p className="text-sm font-semibold text-gray-800 dark:text-gray-200">
                  {a.customerName}
                </p>
                <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                  {a.startTime} ·{" "}
                  {a.tasks[0]?.serviceName ??
                    t(`spa.services.${a.tasks[0]?.serviceKey}`)}
                </p>
              </div>
            </div>
            {canOpenWorkspacePath(workspaces, "/spa/reception") && (
              <Link
                to={`/spa/reception?date=${date}&appointment=${encodeURIComponent(a.id)}`}
                className="mt-3 flex min-h-11 items-center justify-center rounded-lg border border-gray-200 text-theme-xs font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
              >
                {t("spa.flow.openReception")}
              </Link>
            )}
          </div>
        ))}
        {!waiting.length && (
          <p className="py-6 text-sm text-gray-500 dark:text-gray-400">
            {t("spa.flow.noQueue")}
          </p>
        )}
      </div>
    </SpaPanel>
  );
}
