import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import Badge from "@/components/ui/badge/Badge";
import type { SpaAppointment } from "../types";
import { spaReceptionStage } from "../previewWorkflow";
import SpaStatusBadge from "./SpaStatusBadge";
import SpaAvatar from "./SpaAvatar";

export default function SpaReceptionCard({
  appointment,
  onReceive,
  onAssign,
  onDetails,
  onOpenTechnician,
}: {
  appointment: SpaAppointment;
  onReceive: () => void;
  onAssign: (taskId: string) => void;
  onDetails: () => void;
  onOpenTechnician?: (id: string) => void;
}) {
  const { t } = useTranslation();
  return (
    <article
      aria-label={`${appointment.id} · ${appointment.customerName}`}
      className="min-w-0 rounded-lg border border-gray-200 bg-white p-3 shadow-theme-xs dark:border-gray-800 dark:bg-gray-900"
    >
      <div className="flex flex-col items-start gap-2">
        <div>
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">
            {appointment.startTime} · {appointment.id}
          </p>
          <h3 className="mt-2 flex items-center gap-2 text-theme-xs font-semibold text-gray-900 dark:text-white">
            <SpaAvatar name={appointment.customerName} />
            {appointment.customerName}
          </h3>
        </div>
        <Badge color={appointment.status === "booked" ? "light" : "info"}>
          {t(`spa.flow.stages.${spaReceptionStage(appointment)}`)}
        </Badge>
      </div>
      {appointment.receivedBy && (
        <p className="mt-3 text-theme-xs text-gray-500 dark:text-gray-400">
          {t("spa.flow.receivedBy", { name: appointment.receivedBy.name })}
        </p>
      )}
      {appointment.note && (
        <p className="mt-3 text-theme-xs break-words whitespace-pre-wrap text-gray-600 dark:text-gray-300">
          {appointment.note}
        </p>
      )}
      <ul className="mt-4 space-y-3">
        {appointment.tasks.map((task) => (
          <li
            key={task.id}
            className="border-t border-gray-100 pt-3 dark:border-gray-800"
          >
            <div className="flex flex-wrap items-center justify-between gap-2">
              <p className="text-sm font-medium text-gray-900 dark:text-white">
                {task.serviceName ?? t(`spa.services.${task.serviceKey}`)}
              </p>
              <SpaStatusBadge kind="task" status={task.status} />
            </div>
            <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
              {task.startTime} ·{" "}
              {t("spa.minutes", { count: task.durationMinutes })}
            </p>
            <p className="mt-2 text-theme-xs text-gray-700 dark:text-gray-200">
              {task.technicianName || t("spa.flow.notAssigned")}
              {task.room &&
                ` · ${t("spa.flow.bedLabel", { room: task.room, bed: task.bedNumber ?? "01" })}`}
            </p>
            {task.assignedBy && (
              <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
                {t("spa.flow.dispatchedBy", { name: task.assignedBy.name })}
              </p>
            )}
            {appointment.status === "arrived" &&
              ["unassigned", "assigned"].includes(task.status) && (
                <div className="mt-3 flex flex-wrap gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => onAssign(task.id)}
                  >
                    {t(
                      task.status === "unassigned"
                        ? "spa.flow.assign"
                        : "spa.flow.reassign",
                    )}
                  </Button>
                  {task.technicianId && onOpenTechnician && (
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => onOpenTechnician(task.technicianId)}
                    >
                      {t("spa.openTechnician")}
                    </Button>
                  )}
                </div>
              )}
          </li>
        ))}
      </ul>
      <div className="mt-4 flex flex-wrap gap-2">
        {appointment.status === "booked" && (
          <Button
            size="sm"
            className="w-full !bg-kps-primary hover:!bg-kps-primary-hover"
            onClick={onReceive}
          >
            {t("spa.flow.receive")}
          </Button>
        )}
        <Button size="sm" variant="outline" onClick={onDetails}>
          {t("spa.flow.details")}
        </Button>
      </div>
    </article>
  );
}
