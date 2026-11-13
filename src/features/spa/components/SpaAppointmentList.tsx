import type { ReactNode } from "react";
import { useTranslation } from "react-i18next";
import type { SpaAppointment } from "../types";
import SpaStatusBadge from "./SpaStatusBadge";

export default function SpaAppointmentList({
  appointments,
  renderActions,
}: {
  appointments: SpaAppointment[];
  renderActions?: (appointment: SpaAppointment) => ReactNode;
}) {
  const { t } = useTranslation();
  if (!appointments.length)
    return (
      <p
        role="status"
        className="py-8 text-center text-sm text-gray-500 dark:text-gray-400"
      >
        {t("spa.appointments.empty")}
      </p>
    );
  return (
    <ul className="divide-y divide-gray-100 dark:divide-gray-800">
      {appointments.map((appointment) => (
        <li
          key={appointment.id}
          aria-label={`${appointment.id} · ${appointment.customerName}`}
          className="flex flex-col gap-3 py-4 sm:flex-row sm:items-start"
        >
          <div className="flex shrink-0 items-center gap-3 sm:w-24 sm:flex-col sm:items-start sm:gap-1">
            <span className="font-semibold text-gray-900 dark:text-white">
              {appointment.startTime}
            </span>
            <span className="text-theme-xs text-gray-500 dark:text-gray-400">
              {appointment.id}
            </span>
          </div>
          <div className="min-w-0 flex-1">
            <p className="font-medium text-gray-900 dark:text-white">
              {appointment.customerName}
            </p>
            <ul className="mt-2 space-y-2">
              {appointment.tasks.map((task) => (
                <li
                  key={task.id}
                  className="text-sm text-gray-600 dark:text-gray-300"
                >
                  <div className="flex flex-wrap items-center gap-2">
                    <span>
                      {task.serviceName ?? t(`spa.services.${task.serviceKey}`)}
                    </span>
                    <SpaStatusBadge kind="task" status={task.status} />
                  </div>
                  <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                    {task.status === "unassigned"
                      ? t("spa.flow.notAssigned")
                      : t("spa.appointments.assignment", {
                          name: task.technicianName,
                          room: task.room,
                          time: task.startTime,
                        })}
                  </p>
                </li>
              ))}
            </ul>
            {renderActions && (
              <div className="mt-3">{renderActions(appointment)}</div>
            )}
          </div>
          <div className="self-start">
            <SpaStatusBadge kind="appointment" status={appointment.status} />
          </div>
        </li>
      ))}
    </ul>
  );
}
