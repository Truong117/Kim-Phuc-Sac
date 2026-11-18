import { useCallback, useState } from "react";
import { useTranslation } from "react-i18next";
import { useSearchParams } from "react-router";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import Button from "@/components/ui/button/Button";
import { PlusIcon } from "@/icons";
import { dateOnlyInTimeZone, isDateOnly } from "@/utils/dateOnly";
import { useModal } from "@/hooks/useModal";
import { spaMoney } from "../../businessSelectors";
import useSpaBookingRead from "../../hooks/useSpaBookingRead";
import {
  listSpaAppointments,
  type SpaBookingCapabilities,
  type SpaSavedAppointment,
} from "../../services/spaBookingService";
import SpaDateFilter from "../SpaDateFilter";
import SpaStatusBadge from "../SpaStatusBadge";
import { SpaAction, spaPanel } from "../business/SpaBusinessUi";
import SpaLiveAppointmentDetails from "./SpaLiveAppointmentDetails";
import SpaLiveAppointmentActionDialog from "./SpaLiveAppointmentActionDialog";
import SpaLiveBookingDialog from "./SpaLiveBookingDialog";
import SpaLiveFormError from "./SpaLiveFormError";
import SpaLivePagination from "./SpaLivePagination";

export default function SpaLiveAppointments({
  capabilities,
}: {
  capabilities: SpaBookingCapabilities;
}) {
  const { t } = useTranslation();
  const [params, setParams] = useSearchParams();
  const requestedDate = params.get("date") ?? "";
  const requestedCreate = params.get("new") === "1";
  const date = isDateOnly(requestedDate) ? requestedDate : dateOnlyInTimeZone();
  const [pagination, setPagination] = useState({ date, page: 1 });
  const page = pagination.date === date ? pagination.page : 1;
  const request = useCallback(
    (signal: AbortSignal) => listSpaAppointments({ date, page }, signal),
    [date, page],
  );
  const { data, failed, loading, reload } = useSpaBookingRead(request);
  const create = useModal();
  const [detailId, setDetailId] = useState<string>();
  const [action, setAction] = useState<{
    type: "reschedule" | "cancel";
    appointment: SpaSavedAppointment;
  }>();
  const [notice, setNotice] = useState<"bookingSaved" | "bookingCancelled">();
  const selectDate = (value: string) => {
    if (!isDateOnly(value)) return;
    setPagination({ date: value, page: 1 });
    setParams(
      (previous) => {
        const next = new URLSearchParams(previous);
        next.set("date", value);
        next.delete("new");
        return next;
      },
      { replace: true },
    );
  };
  const closeCreate = () => {
    create.closeModal();
    if (requestedCreate) {
      setParams(
        (previous) => {
          const next = new URLSearchParams(previous);
          next.delete("new");
          return next;
        },
        { replace: true },
      );
    }
  };
  const showSaved = (appointment: SpaSavedAppointment) => {
    create.closeModal();
    setAction(undefined);
    setNotice(
      appointment.status === "cancelled" ? "bookingCancelled" : "bookingSaved",
    );
    selectDate(appointment.date);
    reload();
  };
  return (
    <>
      <PageBreadCrumb
        spa
        pageTitle={t("spa.navigation.appointments")}
        description={t("spa.live.appointmentsDescription")}
        actions={
          <div className="flex w-full flex-wrap gap-2 sm:w-auto">
            <SpaDateFilter
              date={date}
              onChange={(value) => {
                setNotice(undefined);
                selectDate(value);
              }}
            />
            {capabilities.createAppointment && (
              <Button
                type="button"
                size="sm"
                startIcon={<PlusIcon className="size-4" />}
                className="min-h-11 !bg-kps-primary hover:!bg-kps-primary-hover"
                onClick={create.openModal}
              >
                {t("spa.live.newBooking")}
              </Button>
            )}
          </div>
        }
      />
      {notice && (
        <p
          role="status"
          className="mb-4 text-sm text-success-700 dark:text-success-400"
        >
          {t(`spa.live.${notice}`)}
        </p>
      )}
      <section aria-label={t("spa.live.appointmentList")} className="space-y-4">
        {loading && <p role="status">{t("spa.live.loading")}</p>}
        {failed && (
          <div className="space-y-3">
            <SpaLiveFormError error="loadError" />
            <SpaAction secondary onClick={reload}>
              {t("spa.live.retry")}
            </SpaAction>
          </div>
        )}
        {data && (
          <>
            <p className="text-sm text-gray-500 dark:text-gray-400">
              {t("spa.live.totalAppointments", { count: data.meta.total })}
            </p>
            {!data.data.length && (
              <p className={spaPanel}>{t("spa.live.noAppointments")}</p>
            )}
            <ul className="grid gap-3 lg:grid-cols-2">
              {data.data.map((appointment) => (
                <li key={appointment.id} className={`${spaPanel} space-y-3`}>
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="font-semibold text-kps-primary-hover dark:text-gray-100">
                      {appointment.start_time}–{appointment.end_time}
                    </p>
                    <SpaStatusBadge
                      kind="appointment"
                      status={appointment.status}
                    />
                  </div>
                  <div className="space-y-1">
                    <h3 className="font-semibold break-words text-gray-900 dark:text-white">
                      {appointment.customer.name}
                    </h3>
                    <p className="text-sm break-words text-gray-600 dark:text-gray-300">
                      {appointment.service.name} ·{" "}
                      {spaMoney(appointment.service.price)}
                    </p>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    <SpaAction
                      secondary
                      onClick={() => setDetailId(appointment.id)}
                    >
                      {t("spa.flow.details")}
                    </SpaAction>
                    {appointment.status === "booked" &&
                      capabilities.rescheduleAppointment && (
                        <SpaAction
                          secondary
                          onClick={() =>
                            setAction({ type: "reschedule", appointment })
                          }
                        >
                          {t("spa.live.reschedule")}
                        </SpaAction>
                      )}
                    {appointment.status === "booked" &&
                      capabilities.cancelAppointment && (
                        <SpaAction
                          secondary
                          onClick={() =>
                            setAction({ type: "cancel", appointment })
                          }
                        >
                          {t("spa.flow.cancelBooking")}
                        </SpaAction>
                      )}
                  </div>
                </li>
              ))}
            </ul>
            <SpaLivePagination
              meta={data.meta}
              onChange={(value) => setPagination({ date, page: value })}
            />
          </>
        )}
      </section>
      {(create.isOpen || requestedCreate) && capabilities.createAppointment && (
        <SpaLiveBookingDialog
          initialDate={date}
          canCreateCustomer={capabilities.createCustomer}
          onClose={closeCreate}
          onSaved={showSaved}
        />
      )}
      {detailId && (
        <SpaLiveAppointmentDetails
          id={detailId}
          onClose={() => setDetailId(undefined)}
        />
      )}
      {action &&
        (action.type === "cancel"
          ? capabilities.cancelAppointment
          : capabilities.rescheduleAppointment) && (
          <SpaLiveAppointmentActionDialog
            action={action.type}
            appointment={action.appointment}
            onClose={() => setAction(undefined)}
            onSaved={showSaved}
          />
        )}
    </>
  );
}
