import { useCallback } from "react";
import { useTranslation } from "react-i18next";
import { formatDateOnly } from "@/utils/dateOnly";
import { spaMoney } from "../../businessSelectors";
import useSpaBookingRead from "../../hooks/useSpaBookingRead";
import { getSpaAppointment } from "../../services/spaBookingService";
import SpaDialog from "../SpaDialog";
import SpaStatusBadge from "../SpaStatusBadge";
import { SpaAction } from "../business/SpaBusinessUi";
import SpaLiveFormError from "./SpaLiveFormError";

const eventTime = new Intl.DateTimeFormat("vi-VN", {
  dateStyle: "short",
  timeStyle: "short",
  timeZone: "Asia/Ho_Chi_Minh",
});

export default function SpaLiveAppointmentDetails({
  id,
  onClose,
}: {
  id: string;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const request = useCallback(
    (signal: AbortSignal) => getSpaAppointment(id, signal),
    [id],
  );
  const {
    data: appointment,
    loading,
    failed,
    reload,
  } = useSpaBookingRead(request);
  return (
    <SpaDialog
      title={t("spa.live.appointmentDetail")}
      description={
        appointment
          ? t("spa.live.appointmentTime", {
              date: formatDateOnly(appointment.date),
              start: appointment.start_time,
              end: appointment.end_time,
            })
          : ""
      }
      onClose={onClose}
    >
      {loading && <p role="status">{t("spa.live.loading")}</p>}
      {failed && (
        <div className="space-y-3">
          <SpaLiveFormError error="loadError" />
          <SpaAction secondary onClick={reload}>
            {t("spa.live.retry")}
          </SpaAction>
        </div>
      )}
      {appointment && (
        <div className="space-y-5 text-sm text-gray-700 dark:text-gray-300">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h3 className="text-base font-semibold break-words text-gray-900 dark:text-white">
              {appointment.customer.name}
            </h3>
            <SpaStatusBadge kind="appointment" status={appointment.status} />
          </div>
          <div className="space-y-1 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p className="font-medium break-words">
              {appointment.service.name}
            </p>
            <p>
              {t("spa.minutes", {
                count: appointment.service.duration_minutes,
              })}{" "}
              · {spaMoney(appointment.service.price)}
            </p>
          </div>
          {appointment.note && (
            <dl>
              <dt className="font-medium">{t("spa.flow.bookingNote")}</dt>
              <dd className="mt-1 break-words whitespace-pre-wrap">
                {appointment.note}
              </dd>
            </dl>
          )}
          <section>
            <h3 className="mb-3 font-semibold text-gray-900 dark:text-white">
              {t("spa.live.activity")}
            </h3>
            {Boolean(appointment.events?.length) && (
              <ol className="space-y-3 border-s border-gray-200 ps-4 dark:border-gray-700">
                {appointment.events?.map((event, index) => (
                  <li
                    key={`${event.action}-${event.created_at}-${index}`}
                    className="space-y-1"
                  >
                    <p className="font-medium break-words">
                      {t(`spa.live.${event.action}`)} · {event.actor_name}
                    </p>
                    <p className="text-theme-xs text-gray-500 dark:text-gray-400">
                      {eventTime.format(new Date(event.created_at))}
                    </p>
                    {event.previous_date && event.previous_start_time && (
                      <dl className="grid gap-2 text-theme-xs sm:grid-cols-2">
                        <div>
                          <dt className="text-gray-500 dark:text-gray-400">
                            {t("spa.live.oldSchedule")}
                          </dt>
                          <dd>
                            {formatDateOnly(event.previous_date)} ·{" "}
                            {event.previous_start_time}
                          </dd>
                        </div>
                        <div>
                          <dt className="text-gray-500 dark:text-gray-400">
                            {t("spa.live.newSchedule")}
                          </dt>
                          <dd>
                            {formatDateOnly(event.date)} · {event.start_time}
                          </dd>
                        </div>
                      </dl>
                    )}
                    {event.reason && (
                      <dl>
                        <dt className="text-theme-xs text-gray-500 dark:text-gray-400">
                          {t("spa.flow.cancelReason")}
                        </dt>
                        <dd className="break-words whitespace-pre-wrap">
                          {event.reason}
                        </dd>
                      </dl>
                    )}
                  </li>
                ))}
              </ol>
            )}
          </section>
        </div>
      )}
    </SpaDialog>
  );
}
