import { useTranslation } from "react-i18next";
import { useSpaPreview } from "../hooks/useSpaPreviewSnapshot";
import { spaTaskName } from "../businessSelectors";
import type { SpaAppointment } from "../types";
import SpaStatusBadge from "./SpaStatusBadge";
import { spaPanel, SpaHint, SpaAction } from "./business/SpaBusinessUi";
export default function SpaTeamSchedule({
  appointments,
  onDetail,
}: {
  appointments: SpaAppointment[];
  onDetail: (id: string) => void;
}) {
  const { t } = useTranslation();
  const { snapshot } = useSpaPreview();
  const tasks = appointments
    .filter((a) => a.status !== "cancelled")
    .flatMap((a) =>
      a.tasks.map((task) => ({
        ...task,
        customerName: a.customerName,
        appointmentId: a.id,
      })),
    );
  return (
    <div className="grid items-start gap-4 lg:grid-cols-2 2xl:grid-cols-4">
      {[
        ...snapshot.technicians,
        { id: "", name: t("spa.business.unassigned") },
      ].map((tech) => (
        <section key={tech.id} className={spaPanel} aria-label={tech.name}>
          <h3 className="font-semibold text-gray-900 dark:text-white">
            {tech.name}
          </h3>
          <div className="mt-4 space-y-3">
            {tasks
              .filter((task) => task.technicianId === tech.id)
              .sort((a, b) => a.startTime.localeCompare(b.startTime))
              .map((task) => (
                <article
                  key={task.id}
                  className="space-y-2 rounded-lg border-s-2 border-kps-primary bg-gray-50 p-3 text-sm dark:bg-gray-800"
                >
                  <p className="font-semibold text-gray-900 dark:text-white">
                    {task.startTime} ·{" "}
                    {t("spa.minutes", { count: task.durationMinutes })}
                  </p>
                  <p className="text-gray-800 dark:text-gray-200">
                    {task.customerName}
                  </p>
                  <SpaHint>{spaTaskName(snapshot, task)}</SpaHint>
                  <SpaStatusBadge kind="task" status={task.status} />
                  <div>
                    <SpaAction
                      secondary
                      onClick={() => onDetail(task.appointmentId)}
                    >
                      {t("spa.business.viewDetail")}
                    </SpaAction>
                  </div>
                </article>
              ))}
            {!tasks.some((task) => task.technicianId === tech.id) && (
              <SpaHint>{t("spa.business.empty")}</SpaHint>
            )}
          </div>
        </section>
      ))}
    </div>
  );
}
