import { useTranslation } from "react-i18next";
import { useSpaPreview } from "../hooks/useSpaPreviewSnapshot";
import { spaTaskName } from "../businessSelectors";
import { spaScheduleBounds, spaScheduleItems, spaTime } from "../presentation";
import type { SpaAppointment } from "../types";
import SpaAvatar from "./SpaAvatar";

export default function SpaTeamSchedule({
  appointments,
  onDetail,
}: {
  appointments: SpaAppointment[];
  onDetail: (id: string) => void;
}) {
  const { t } = useTranslation();
  const { snapshot } = useSpaPreview();
  const { start, end } = spaScheduleBounds(
    appointments,
    snapshot.settings.open,
    snapshot.settings.close,
  );
  const unassigned = appointments.some(
    (a) =>
      a.status !== "cancelled" && a.tasks.some((task) => !task.technicianId),
  );
  const technicians = [
    ...snapshot.technicians,
    ...(unassigned ? [{ id: "", name: t("spa.business.unassigned") }] : []),
  ];
  const minutes = end - start,
    scale = 1.5;
  const palette = [
    "border-kps-primary/40 bg-kps-primary/10 text-kps-primary-hover dark:border-kps-primary/60 dark:bg-kps-primary/20 dark:text-gray-200",
    "border-success-500/40 bg-success-50 text-success-700 dark:border-success-500/40 dark:bg-success-500/10 dark:text-success-400",
    "border-blue-light-500/40 bg-blue-light-50 text-blue-light-700 dark:border-blue-light-500/40 dark:bg-blue-light-500/10 dark:text-blue-light-400",
  ];
  return (
    <section aria-label={t("spa.concept.timeline")} className="min-w-0">
      <p className="px-5 py-3 text-theme-xs text-gray-500 dark:text-gray-400">
        {t("spa.concept.timelineHint")}
      </p>
      <div
        tabIndex={0}
        role="region"
        aria-label={t("spa.concept.timelineScroll")}
        className="max-h-180 overflow-auto focus-visible:outline-kps-primary"
      >
        <div
          className="grid"
          style={{
            gridTemplateColumns: `3.5rem repeat(${technicians.length}, minmax(12rem, 1fr))`,
          }}
        >
          <div className="sticky top-0 z-20 border-y border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900" />
          {technicians.map((tech) => (
            <div
              key={tech.id}
              className="sticky top-0 z-20 flex items-center justify-center gap-2 border-y border-s border-gray-200 bg-white px-3 py-4 dark:border-gray-800 dark:bg-gray-900"
            >
              <SpaAvatar name={tech.name} />
              <div>
                <h3 className="text-theme-xs font-semibold text-gray-900 dark:text-white">
                  {tech.name}
                </h3>
                <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                  {snapshot.settings.open} – {snapshot.settings.close}
                </p>
              </div>
            </div>
          ))}
          <div
            className="relative bg-white text-theme-xs text-gray-500 dark:bg-gray-900 dark:text-gray-400"
            style={{ height: minutes * scale }}
          >
            {Array.from({ length: minutes / 60 }, (_, index) => (
              <span
                key={index}
                className="absolute start-2"
                style={{ top: index * 60 * scale + 4 }}
              >
                {spaTime(start + index * 60)}
              </span>
            ))}
          </div>
          {technicians.map((tech, index) => (
            <div
              key={tech.id}
              className="relative border-s border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900"
              style={{ height: minutes * scale }}
            >
              {Array.from({ length: minutes / 30 }, (_, row) => (
                <div
                  key={row}
                  aria-hidden="true"
                  className="absolute inset-x-0 border-t border-gray-100 dark:border-gray-800"
                  style={{ top: row * 30 * scale }}
                />
              ))}
              {spaScheduleItems(appointments, tech.id, 44 / scale).map(
                (task) => (
                  <button
                    key={task.id}
                    type="button"
                    onClick={() => onDetail(task.appointmentId)}
                    aria-label={`${task.startTime} – ${spaTime(task.start + task.durationMinutes)} · ${task.customerName} · ${spaTaskName(snapshot, task)}${task.incoming ? ` · ${t("spa.concept.incoming")}` : ""}`}
                    title={`${task.customerName} · ${spaTaskName(snapshot, task)} · ${task.technicianName} · ${t(`spa.status.task.${task.status}`)}`}
                    className={`absolute overflow-hidden rounded-lg border border-s-3 p-2 text-start focus-visible:z-10 focus-visible:ring-2 focus-visible:ring-kps-primary focus-visible:outline-none ${palette[index % palette.length]} ${task.incoming ? "border-dashed" : ""}`}
                    style={{
                      top: (task.start - start) * scale + 2,
                      height: Math.max(44, task.durationMinutes * scale - 4),
                      insetInlineStart: `calc(${(task.lane * 100) / task.lanes}% + 4px)`,
                      width: `calc(${100 / task.lanes}% - 8px)`,
                    }}
                  >
                    <span className="block truncate text-theme-xs">
                      {task.startTime} –{" "}
                      {spaTime(task.start + task.durationMinutes)} ·{" "}
                      {task.room || t("spa.business.unassigned")}
                    </span>
                    <span className="mt-1 block truncate text-theme-xs font-semibold">
                      {task.customerName}
                    </span>
                    <span className="mt-1 block truncate text-theme-xs">
                      {task.incoming
                        ? t("spa.concept.incoming")
                        : spaTaskName(snapshot, task)}
                    </span>
                  </button>
                ),
              )}
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
