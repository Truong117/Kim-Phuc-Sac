import { useState } from "react";
import { useTranslation } from "react-i18next";
import { useSpaPreview } from "../../hooks/useSpaPreviewSnapshot";
import { spaDayTasks, spaTaskName } from "../../businessSelectors";
import SpaDateFilter from "../SpaDateFilter";
import SpaAppointmentDetail from "../SpaAppointmentDetail";
import SpaStatusBadge from "../SpaStatusBadge";
import { SpaAction, SpaStats, SpaHint, spaPanel } from "./SpaBusinessUi";
export default function SpaRoomsView() {
  const { t } = useTranslation();
  const b = (k: string) => t(`spa.business.${k}`);
  const { snapshot } = useSpaPreview();
  const [date, setDate] = useState(snapshot.date),
    [detail, setDetail] = useState<string>();
  const tasks = spaDayTasks(snapshot, date);
  const occupied = new Set(tasks.map((task) => task.bedId));
  const serving = new Set(
    tasks
      .filter((task) => task.status === "in_progress")
      .map((task) => task.bedId),
  );
  const appointment = snapshot.appointments.find((a) => a.id === detail);
  return (
    <div className="space-y-5">
      <SpaDateFilter date={date} onChange={setDate} />
      <SpaStats
        items={[
          { label: b("roomsServing"), value: serving.size },
          {
            label: b("roomsBooked"),
            value: [...occupied].filter(Boolean).length,
          },
          {
            label: b("roomsFree"),
            value: snapshot.beds.filter((bed) => !occupied.has(bed.id)).length,
          },
        ]}
      />
      <SpaHint>{b("roomHint")}</SpaHint>
      <div className="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
        {snapshot.beds.map((bed) => {
          const assigned = tasks
            .filter((task) => task.bedId === bed.id)
            .sort((a, b) => a.startTime.localeCompare(b.startTime));
          return (
            <article key={bed.id} className={spaPanel}>
              <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="font-semibold text-gray-900 dark:text-white">
                  {t("spa.flow.bedLabel", { room: bed.room, bed: bed.number })}
                </h2>
                <span
                  className={`rounded-full px-3 py-1 text-xs ${serving.has(bed.id) ? "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400" : "bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300"}`}
                >
                  {b(
                    serving.has(bed.id)
                      ? "serving"
                      : assigned.length
                        ? "scheduled"
                        : "available",
                  )}
                </span>
              </div>
              <div className="mt-4 space-y-3">
                {assigned.length ? (
                  assigned.map((task) => (
                    <div
                      key={task.id}
                      className="border-s-2 border-kps-primary/40 ps-3"
                    >
                      <p className="text-sm font-medium text-gray-900 dark:text-white">
                        {task.startTime} · {task.customerName}
                      </p>
                      <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        {spaTaskName(snapshot, task)} · {task.technicianName}
                      </p>
                      <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                        <SpaStatusBadge kind="task" status={task.status} />
                        <SpaAction
                          secondary
                          onClick={() => setDetail(task.appointmentId)}
                        >
                          {b("viewDetail")}
                        </SpaAction>
                      </div>
                    </div>
                  ))
                ) : (
                  <SpaHint>{b("noRoomTasks")}</SpaHint>
                )}
              </div>
            </article>
          );
        })}
      </div>
      {appointment && (
        <SpaAppointmentDetail
          appointment={appointment}
          onClose={() => setDetail(undefined)}
        />
      )}
    </div>
  );
}
