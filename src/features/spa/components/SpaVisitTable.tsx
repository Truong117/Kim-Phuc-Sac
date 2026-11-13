import type { ReactNode } from "react";
import { useTranslation } from "react-i18next";
import {
  Table,
  TableHeader,
  TableBody,
  TableRow,
  TableCell,
} from "@/components/ui/table";
import type { SpaAppointment } from "../types";
import { spaServiceStage } from "../presentation";
import SpaAvatar from "./SpaAvatar";
import SpaStatusBadge from "./SpaStatusBadge";

export default function SpaVisitTable({
  appointments,
  renderActions,
}: {
  appointments: SpaAppointment[];
  renderActions: (appointment: SpaAppointment) => ReactNode;
}) {
  const { t } = useTranslation();
  if (!appointments.length)
    return (
      <p
        role="status"
        className="p-8 text-center text-sm text-gray-500 dark:text-gray-400"
      >
        {t("spa.appointments.empty")}
      </p>
    );
  return (
    <div className="overflow-x-auto">
      <Table className="min-w-160 text-sm">
        <TableHeader className="bg-gray-50 dark:bg-gray-800/50">
          <TableRow>
            {["time", "customer", "service", "staff", "status", "actions"].map(
              (key) => (
                <TableCell
                  key={key}
                  isHeader
                  className="px-4 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400"
                >
                  {t(`spa.concept.columns.${key}`)}
                </TableCell>
              ),
            )}
          </TableRow>
        </TableHeader>
        <TableBody>
          {appointments.map((appointment) => (
            <TableRow
              key={appointment.id}
              className="border-t border-gray-100 align-top dark:border-gray-800"
            >
              <TableCell className="px-4 py-4">
                <p className="font-semibold text-gray-800 dark:text-gray-200">
                  {appointment.startTime}
                </p>
                <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                  {appointment.id}
                </p>
              </TableCell>
              <TableCell className="px-4 py-4">
                <div className="flex items-center gap-2">
                  <SpaAvatar name={appointment.customerName} />
                  <p className="font-medium text-gray-800 dark:text-gray-200">
                    {appointment.customerName}
                  </p>
                </div>
              </TableCell>
              <TableCell className="space-y-2 px-4 py-4 text-gray-600 dark:text-gray-300">
                {appointment.tasks.map((task) => (
                  <p key={task.id}>
                    {task.serviceName ?? t(`spa.services.${task.serviceKey}`)}
                  </p>
                ))}
              </TableCell>
              <TableCell className="space-y-2 px-4 py-4 text-gray-600 dark:text-gray-300">
                {appointment.tasks.map((task) => (
                  <div key={task.id}>
                    <p>{task.technicianName || t("spa.flow.notAssigned")}</p>
                    {task.room && (
                      <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                        {t("spa.flow.bedLabel", {
                          room: task.room,
                          bed: task.bedNumber ?? "01",
                        })}
                      </p>
                    )}
                  </div>
                ))}
              </TableCell>
              <TableCell className="px-4 py-4">
                {appointment.status === "arrived" ? (
                  <span className="rounded-md bg-kps-primary/5 px-2 py-1 text-theme-xs font-medium text-kps-primary-hover dark:bg-kps-primary/20 dark:text-gray-200">
                    {t(
                      `spa.concept.board.${spaServiceStage(appointment.tasks)}`,
                    )}
                  </span>
                ) : (
                  <SpaStatusBadge
                    kind="appointment"
                    status={appointment.status}
                  />
                )}
              </TableCell>
              <TableCell className="px-4 py-3">
                {renderActions(appointment)}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}
