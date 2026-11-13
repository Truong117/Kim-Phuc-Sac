import { useSearchParams } from "react-router";
import { useSidebar } from "@/context/SidebarContext";
import { useAuthorization } from "@/hooks/useAuthorization";
import { useSpaPreview } from "@/features/spa/hooks/useSpaPreviewSnapshot";
import SpaDateFilter from "@/features/spa/components/SpaDateFilter";
import Select from "@/components/form/Select";
import Label from "@/components/form/Label";
import SpaPageHeading from "@/features/spa/components/SpaPageHeading";
import SpaAvatar from "@/features/spa/components/SpaAvatar";
import PageMeta from "@/components/common/PageMeta";
import SpaStatusBadge from "@/features/spa/components/SpaStatusBadge";
import SpaTechnicianDetail from "@/features/spa/components/SpaTechnicianDetail";
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
  const { isExpanded, isHovered } = useSidebar();
  const { snapshot, execute } = useSpaPreview();
  const { workspaces } = useAuthorization();
  const [params] = useSearchParams();
  const [date, setDate] = useState(params.get("date") ?? snapshot.date);
  const canReviewTeam = workspaces.some(
    (item) => item.id === "spa" && item.sections.includes("reception"),
  );
  const [view, setView] = useState<(typeof views)[number]["key"]>("today");
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const tasks = getSpaTechnicianTasks(
    { ...snapshot, date },
    snapshot.previewTechnician.id,
  ).filter((task) =>
    view === "history"
      ? task.status === "completed"
      : view === "today"
        ? task.status !== "completed" &&
          task.technicianId === snapshot.previewTechnician.id &&
          snapshot.appointments.some(
            (item) =>
              item.id === task.appointmentId && item.status === "arrived",
          )
        : view === "handover"
          ? Boolean(task.handover)
          : task.technicianId === snapshot.previewTechnician.id ||
            task.handover?.toId === snapshot.previewTechnician.id,
  );
  const ownTasks = getSpaTechnicianTasks(
    { ...snapshot, date },
    snapshot.previewTechnician.id,
  ).filter((task) => task.technicianId === snapshot.previewTechnician.id);
  const stats = [
    {
      key: "active",
      count: ownTasks.filter((task) => task.status === "in_progress").length,
      view: "today",
    },
    {
      key: "waiting",
      count: ownTasks.filter(
        (task) =>
          task.status !== "completed" &&
          task.status !== "in_progress" &&
          snapshot.appointments.some(
            (a) => a.id === task.appointmentId && a.status === "arrived",
          ),
      ).length,
      view: "today",
    },
    {
      key: "upcoming",
      count: ownTasks.filter((task) =>
        snapshot.appointments.some(
          (a) => a.id === task.appointmentId && a.status === "booked",
        ),
      ).length,
      view: "schedule",
    },
    {
      key: "completed",
      count: ownTasks.filter((task) => task.status === "completed").length,
      view: "history",
    },
  ] as const;
  const selected = tasks.find((task) => task.id === selectedId) ?? tasks[0];
  return (
    <>
      <PageMeta
        title={t("spa.meta.technician")}
        description={t("spa.meta.description")}
      />
      <SpaPageHeading section="technician" />
      <div className="space-y-4 pb-24">
        <div className="flex flex-wrap items-end gap-4">
          <SpaDateFilter
            date={date}
            onChange={(value) => {
              setDate(value);
              setSelectedId(null);
            }}
          />
          {canReviewTeam && (
            <div className="w-full sm:w-60">
              <Label htmlFor="spa-review-technician">
                {t("spa.flow.reviewTechnician")}
              </Label>
              <Select
                id="spa-review-technician"
                placeholder={t("spa.flow.reviewTechnician")}
                value={snapshot.previewTechnician.id}
                onChange={(id) => {
                  execute({ kind: "technician", id });
                  setSelectedId(null);
                }}
                options={snapshot.technicians.map((item) => ({
                  value: item.id,
                  label: item.name,
                }))}
              />
            </div>
          )}
        </div>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          {t("spa.technician.identity", {
            name: snapshot.previewTechnician.name,
          })}
        </p>
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {stats.map((stat) => (
            <button
              key={stat.key}
              type="button"
              onClick={() => {
                setView(stat.view);
                setSelectedId(null);
              }}
              className="flex min-h-20 items-center gap-3 rounded-xl border border-gray-200 bg-white p-4 text-start shadow-theme-xs dark:border-gray-800 dark:bg-gray-900"
            >
              <span className="text-title-sm font-semibold text-kps-primary-hover dark:text-gray-200">
                {stat.count}
              </span>
              <span className="text-theme-xs text-gray-500 dark:text-gray-400">
                {t(`spa.concept.techStats.${stat.key}`)}
              </span>
            </button>
          ))}
        </div>
        {tasks.length ? (
          <div className="grid gap-4 md:grid-cols-3">
            <section
              className={`${selectedId ? "hidden md:block" : ""} min-w-0 space-y-3`}
              aria-label={t("spa.technician.taskList")}
            >
              {tasks.map((task) => (
                <button
                  key={task.id}
                  type="button"
                  onClick={() => setSelectedId(task.id)}
                  aria-pressed={selected?.id === task.id}
                  className={`w-full rounded-xl border bg-white p-4 text-start focus-visible:ring-2 focus-visible:ring-kps-primary focus-visible:outline-none dark:bg-gray-900 ${selected?.id === task.id ? "border-kps-primary/70 dark:border-kps-primary-hover" : "border-gray-200 dark:border-gray-800"}`}
                >
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-sm font-semibold text-gray-900 dark:text-white">
                      {task.startTime}
                    </span>
                    <SpaStatusBadge kind="task" status={task.status} />
                  </div>
                  <p className="mt-3 flex items-center gap-2 font-semibold text-gray-900 dark:text-white">
                    <SpaAvatar name={task.customerName} />
                    {task.customerName}
                  </p>
                  <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    {task.serviceName ?? t(`spa.services.${task.serviceKey}`)}
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
                  key={selected.id}
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
                ? "spa.business.noPending"
                : "spa.technician.empty",
            )}
          </p>
        )}{" "}
        <div
          className={`fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 gap-1 border-t border-gray-200 bg-white p-1 shadow-theme-sm md:px-6 dark:border-gray-800 dark:bg-gray-900 ${isExpanded || isHovered ? "xl:start-72.5" : "xl:start-22.5"}`}
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
      </div>
    </>
  );
}
