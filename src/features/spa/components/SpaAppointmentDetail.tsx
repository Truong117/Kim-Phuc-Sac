import { useTranslation } from "react-i18next";
import type { SpaAppointment } from "../types";
import SpaDialog from "./SpaDialog";
import SpaStatusBadge from "./SpaStatusBadge";

export default function SpaAppointmentDetail({
  appointment,
  onClose,
}: {
  appointment: SpaAppointment;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  return (
    <SpaDialog title={t("spa.flow.bookingDetail")} onClose={onClose}>
      <div className="space-y-5 text-sm text-gray-700 dark:text-gray-300">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h3 className="text-base font-semibold text-gray-900 dark:text-white">
            {appointment.customerName} · {appointment.id}
          </h3>
          <SpaStatusBadge kind="appointment" status={appointment.status} />
        </div>
        <p>
          {appointment.date} · {appointment.startTime}
        </p>
        <p>
          {t("spa.flow.receivedBy", {
            name: appointment.receivedBy?.name ?? t("spa.flow.notReceived"),
          })}
        </p>
        {appointment.note && (
          <p className="break-words whitespace-pre-wrap">
            {t("spa.flow.bookingNote")}: {appointment.note}
          </p>
        )}
        {appointment.cancelReason && (
          <p className="break-words whitespace-pre-wrap text-error-600 dark:text-error-400">
            {t("spa.flow.cancelReason")}: {appointment.cancelReason}
          </p>
        )}
        <ul className="space-y-3">
          {appointment.tasks.map((task) => (
            <li
              key={task.id}
              className="rounded-xl border border-gray-200 p-4 dark:border-gray-700"
            >
              <div className="flex flex-wrap justify-between gap-2">
                <span className="font-medium">
                  {task.serviceName ?? t(`spa.services.${task.serviceKey}`)}
                </span>
                <SpaStatusBadge kind="task" status={task.status} />
              </div>
              <p className="mt-2">
                {task.startTime} ·{" "}
                {t("spa.minutes", { count: task.durationMinutes })}
              </p>
              <p className="mt-2">
                {task.technicianName || t("spa.flow.notAssigned")}
                {task.room &&
                  ` · ${t("spa.flow.bedLabel", { room: task.room, bed: task.bedNumber ?? "01" })}`}
              </p>
              {task.result && (
                <p className="mt-2 whitespace-pre-wrap">
                  {t("spa.business.resultRead")}: {task.result}
                </p>
              )}
              {task.handover && (
                <p className="mt-2 text-warning-700 dark:text-warning-400">
                  {t("spa.business.pendingHandover", {
                    name: task.handover.toName,
                  })}
                </p>
              )}
              {task.assignedBy && (
                <p className="mt-2 text-gray-500 dark:text-gray-400">
                  {t("spa.flow.dispatchedBy", { name: task.assignedBy.name })}
                </p>
              )}
            </li>
          ))}
        </ul>
        <section>
          <h3 className="mb-3 font-semibold text-gray-900 dark:text-white">
            {t("spa.flow.activity")}
          </h3>
          {appointment.audit?.length ? (
            <ol className="space-y-3 border-s border-gray-200 ps-4 dark:border-gray-700">
              {appointment.audit.toReversed().map((event, index) => (
                <li key={index}>
                  <p className="font-medium">
                    {t(`spa.flow.events.${event.kind}`)} · {event.actor.name}
                  </p>
                  <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                    {new Intl.DateTimeFormat("vi-VN", {
                      dateStyle: "short",
                      timeStyle: "short",
                      timeZone: "Asia/Ho_Chi_Minh",
                    }).format(new Date(event.at))}
                  </p>
                  {event.detail && (
                    <p className="mt-1 break-words">{event.detail}</p>
                  )}
                </li>
              ))}
            </ol>
          ) : (
            <p className="text-gray-500 dark:text-gray-400">
              {t("spa.flow.noActivity")}
            </p>
          )}
        </section>
      </div>
    </SpaDialog>
  );
}
