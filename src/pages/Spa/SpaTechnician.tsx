import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import SpaStatusBadge from "@/features/spa/components/SpaStatusBadge";
import SpaTechnicianDetail from "@/features/spa/components/SpaTechnicianDetail";
import useSpaPreviewSnapshot from "@/features/spa/hooks/useSpaPreviewSnapshot";
import { getSpaTechnicianTasks } from "@/features/spa/selectors";
import {
  CalenderIcon,
  CheckCircleIcon,
  MultiUserIcon,
  TimeIcon,
} from "@/icons";
import { useState } from "react";
import { useTranslation } from "react-i18next";

const views = [
  { key: "today", icon: TimeIcon },
  { key: "schedule", icon: CalenderIcon },
  { key: "handover", icon: MultiUserIcon },
  { key: "history", icon: CheckCircleIcon },
] as const;

export default function SpaTechnician() {
  const { t } = useTranslation();
  const snapshot = useSpaPreviewSnapshot();
  const [view, setView] = useState<(typeof views)[number]["key"]>("today");
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const tasks = getSpaTechnicianTasks(
    snapshot,
    snapshot.previewTechnician.id,
  ).filter((task) =>
    view === "history"
      ? task.status === "completed"
      : view === "today"
        ? task.status !== "completed"
        : view !== "handover",
  );
  const selected = tasks.find((task) => task.id === selectedId) ?? tasks[0];
  return (
    <>
      <PageMeta
        title={t("spa.meta.technician")}
        description={t("spa.meta.description")}
      />
      <PageBreadCrumb pageTitle={t("spa.navigation.technician")} />
      <div className="space-y-4">
        <p className="text-sm text-gray-500 dark:text-gray-400">
          {t("spa.technician.identity", {
            name: snapshot.previewTechnician.name,
          })}
        </p>
        <div
          className="grid grid-cols-4 gap-1 rounded-xl border border-gray-200 bg-white p-1 dark:border-gray-800 dark:bg-gray-900"
          role="group"
          aria-label={t("spa.technician.viewsLabel")}
        >
          {views.map(({ key, icon: Icon }) => (
            <button
              key={key}
              type="button"
              aria-pressed={view === key}
              onClick={() => {
                setView(key);
                setSelectedId(null);
              }}
              className={`flex min-h-14 min-w-0 flex-col items-center justify-center gap-1 rounded-lg px-1 py-2 text-theme-xs font-medium focus-visible:ring-2 focus-visible:ring-kps-primary focus-visible:outline-none ${view === key ? "bg-kps-primary/5 text-kps-primary-hover dark:bg-kps-primary/15 dark:text-gray-200" : "text-gray-500 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/5"}`}
            >
              <Icon className="size-5" aria-hidden="true" />
              {t(`spa.technician.views.${key}`)}
            </button>
          ))}
        </div>
        {tasks.length ? (
          <div className="grid gap-4 md:grid-cols-3">
            <section
              className={`${selectedId ? "hidden md:block" : ""} space-y-3`}
              aria-label={t("spa.technician.taskList")}
            >
              {tasks.map((task) => (
                <button
                  key={task.id}
                  type="button"
                  onClick={() => setSelectedId(task.id)}
                  aria-pressed={selected?.id === task.id}
                  className={`w-full rounded-2xl border bg-white p-4 text-start focus-visible:ring-2 focus-visible:ring-kps-primary focus-visible:outline-none dark:bg-gray-900 ${selected?.id === task.id ? "border-kps-primary/70 dark:border-kps-primary-hover" : "border-gray-200 dark:border-gray-800"}`}
                >
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-sm font-semibold text-gray-900 dark:text-white">
                      {task.startTime}
                    </span>
                    <SpaStatusBadge kind="task" status={task.status} />
                  </div>
                  <p className="mt-3 font-semibold text-gray-900 dark:text-white">
                    {task.customerName}
                  </p>
                  <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {t(`spa.services.${task.serviceKey}`)}
                  </p>
                  <p className="mt-3 text-theme-xs text-gray-500 dark:text-gray-400">
                    {t("spa.technician.roomLabel", { room: task.room })}
                  </p>
                </button>
              ))}
            </section>
            <div
              className={`${selectedId ? "" : "hidden md:block"} min-w-0 md:col-span-2`}
            >
              {selected ? (
                <SpaTechnicianDetail
                  task={selected}
                  onBack={() => setSelectedId(null)}
                />
              ) : null}
            </div>
          </div>
        ) : (
          <p
            role="status"
            className="rounded-2xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400"
          >
            {t(
              view === "handover"
                ? "spa.technician.handoverPlanned"
                : "spa.technician.empty",
            )}
          </p>
        )}
      </div>
    </>
  );
}
