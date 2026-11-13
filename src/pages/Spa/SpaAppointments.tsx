import SpaTeamSchedule from "@/features/spa/components/SpaTeamSchedule";
import { SpaTabs } from "@/features/spa/components/business/SpaBusinessUi";
import { spaFieldClasses } from "@/features/spa/config";
import SpaPageHeading from "@/features/spa/components/SpaPageHeading";
import SpaRoomOccupancy from "@/features/spa/components/SpaRoomOccupancy";
import SpaAppointmentWaiting from "@/features/spa/components/SpaAppointmentWaiting";
import { getSpaOverview } from "@/features/spa/selectors";
import { useAuthorization } from "@/hooks/useAuthorization";
import { canOpenWorkspacePath } from "@/utils/workspace";
import { PlusIcon } from "@/icons";
import PageMeta from "@/components/common/PageMeta";
import Select from "@/components/form/Select";
import Input from "@/components/form/input/InputField";
import Button from "@/components/ui/button/Button";
import SpaAppointmentList from "@/features/spa/components/SpaAppointmentList";
import SpaBookingDialog from "@/features/spa/components/SpaBookingDialog";
import SpaCancelDialog from "@/features/spa/components/SpaCancelDialog";
import SpaAppointmentDetail from "@/features/spa/components/SpaAppointmentDetail";
import SpaDateFilter from "@/features/spa/components/SpaDateFilter";
import { useSpaPreview } from "@/features/spa/hooks/useSpaPreviewSnapshot";
import { filterSpaAppointments } from "@/features/spa/selectors";
import type { SpaAppointmentStatus } from "@/features/spa/types";
import { useModal } from "@/hooks/useModal";
import { useState } from "react";
import { useTranslation } from "react-i18next";
import { Link, useSearchParams } from "react-router";

export default function SpaAppointments() {
  const { t } = useTranslation();
  const { snapshot } = useSpaPreview();
  const { workspaces } = useAuthorization();
  const [params] = useSearchParams();
  const [date, setDate] = useState(params.get("date") ?? snapshot.date);
  const [query, setQuery] = useState(
    snapshot.customers.find((item) => item.id === params.get("customer"))
      ?.name ?? "",
  );
  const [status, setStatus] = useState<SpaAppointmentStatus | "all">("all");
  const [editingId, setEditingId] = useState<string | null>(null);
  const [cancelId, setCancelId] = useState<string | null>(null);
  const [detailId, setDetailId] = useState<string | null>(null);
  const [view, setView] = useState(
    params.get("view") === "list" ? "list" : "teamSchedule",
  );
  const [notice, setNotice] = useState(false);
  const editor = useModal();
  const appointments = filterSpaAppointments(
    snapshot.appointments.filter((item) => item.date === date),
    query,
    status,
  );
  const dayStats = getSpaOverview({ ...snapshot, date });
  const showAside =
    canOpenWorkspacePath(workspaces, "/spa/reception") ||
    canOpenWorkspacePath(workspaces, "/spa/rooms");
  const detail = snapshot.appointments.find((item) => item.id === detailId);
  const cancellation = snapshot.appointments.find(
    (item) => item.id === cancelId,
  );
  return (
    <>
      <PageMeta
        title={t("spa.meta.appointments")}
        description={t("spa.meta.description")}
      />
      <SpaPageHeading
        section="appointments"
        actions={
          <div className="flex w-full flex-wrap gap-2 sm:w-auto">
            <SpaDateFilter date={date} onChange={setDate} />
            <Button
              size="sm"
              startIcon={<PlusIcon className="size-4" />}
              className="!bg-kps-primary hover:!bg-kps-primary-hover"
              onClick={() => {
                setEditingId(null);
                editor.openModal();
              }}
            >
              {t("spa.flow.newBooking")}
            </Button>
          </div>
        }
      />
      <div className="space-y-5">
        <div className="grid grid-cols-2 gap-3 xl:grid-cols-10">
          {[
            ["spa.concept.appointmentsDate", dayStats.appointments],
            ["spa.concept.waiting", dayStats.waiting],
            ["spa.concept.inProgress", dayStats.inProgress],
            ["spa.concept.technicians", snapshot.technicians.length],
          ].map(([key, value]) => (
            <p
              key={key}
              className="rounded-xl border border-gray-200 bg-white px-4 py-3 text-theme-xs text-gray-500 shadow-theme-xs dark:border-gray-800 dark:bg-gray-900 dark:text-gray-400"
            >
              <span className="me-2 font-semibold text-kps-primary-hover dark:text-gray-200">
                {value}
              </span>
              {t(String(key))}
            </p>
          ))}
        </div>
        {notice && (
          <p
            role="status"
            className="text-sm text-success-700 dark:text-success-400"
          >
            {t("spa.flow.saved")}
          </p>
        )}
        <div
          className={`grid items-start gap-5 ${showAside ? "xl:grid-cols-10" : ""}`}
        >
          <section
            aria-label={t("spa.appointments.title")}
            className={`min-w-0 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-theme-xs dark:border-gray-800 dark:bg-gray-900 ${showAside ? "xl:col-span-7" : ""}`}
          >
            <div className="space-y-3 border-b border-gray-100 p-4 dark:border-gray-800">
              <div className="grid gap-3 sm:grid-cols-2">
                <div>
                  <label htmlFor="spa-search" className="sr-only">
                    {t("spa.appointments.search")}
                  </label>
                  <Input
                    id="spa-search"
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder={t("spa.appointments.searchPlaceholder")}
                    className={spaFieldClasses}
                  />
                </div>
                <div>
                  <label htmlFor="spa-status" className="sr-only">
                    {t("spa.appointments.status")}
                  </label>
                  <Select
                    id="spa-status"
                    placeholder={t("spa.appointments.status")}
                    value={status}
                    onChange={(value) => {
                      if (
                        ["all", "booked", "arrived", "cancelled"].includes(
                          value,
                        )
                      )
                        setStatus(value as typeof status);
                    }}
                    options={["all", "booked", "arrived", "cancelled"].map(
                      (value) => ({
                        value,
                        label: t(
                          value === "all"
                            ? "spa.appointments.all"
                            : `spa.status.appointment.${value}`,
                        ),
                      }),
                    )}
                  />
                </div>
              </div>
              <p
                role="status"
                aria-live="polite"
                className="text-sm text-gray-500 dark:text-gray-400"
              >
                {t("spa.appointments.count", { count: appointments.length })}
              </p>
              <div className="flex flex-wrap items-center justify-between gap-2">
                <SpaTabs
                  value={view}
                  onChange={setView}
                  items={["teamSchedule", "list"].map((key) => ({
                    key,
                    label: t(`spa.business.${key}`),
                  }))}
                />
                <button
                  type="button"
                  onClick={() => setDate(snapshot.date)}
                  className="min-h-11 rounded-lg border border-gray-200 px-3 text-theme-xs text-gray-600 dark:border-gray-700 dark:text-gray-300"
                >
                  {t("spa.technician.views.today")}
                </button>
              </div>
            </div>
            {view === "teamSchedule" &&
            status !== "cancelled" &&
            appointments.length ? (
              <SpaTeamSchedule
                appointments={appointments}
                onDetail={setDetailId}
              />
            ) : (
              <SpaAppointmentList
                appointments={appointments}
                renderActions={(appointment) => (
                  <div className="flex flex-wrap gap-2 p-4">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setDetailId(appointment.id)}
                    >
                      {t("spa.flow.details")}
                    </Button>
                    {appointment.status === "booked" && (
                      <Button
                        variant="outline"
                        size="sm"
                        onClick={() => {
                          setEditingId(appointment.id);
                          editor.openModal();
                        }}
                      >
                        {t("spa.flow.editBooking")}
                      </Button>
                    )}
                    {appointment.status !== "cancelled" &&
                      !appointment.tasks.some((task) =>
                        ["in_progress", "completed"].includes(task.status),
                      ) && (
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => setCancelId(appointment.id)}
                        >
                          {t("spa.flow.cancelBooking")}
                        </Button>
                      )}
                    {appointment.status !== "cancelled" && (
                      <Link
                        to={`/spa/reception?date=${date}&appointment=${encodeURIComponent(appointment.id)}`}
                        className="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-medium text-kps-primary hover:bg-kps-primary/5 dark:text-gray-200"
                      >
                        {t("spa.flow.openReception")}
                      </Link>
                    )}
                  </div>
                )}
              />
            )}
          </section>
          {showAside && (
            <aside className="space-y-5 xl:col-span-3">
              {canOpenWorkspacePath(workspaces, "/spa/reception") && (
                <SpaAppointmentWaiting
                  appointments={appointments}
                  date={date}
                />
              )}
              {canOpenWorkspacePath(workspaces, "/spa/rooms") && (
                <SpaRoomOccupancy snapshot={snapshot} date={date} />
              )}
            </aside>
          )}
        </div>
      </div>
      {editor.isOpen && (
        <SpaBookingDialog
          key={editingId ?? "new"}
          appointment={snapshot.appointments.find(
            (item) => item.id === editingId,
          )}
          date={date}
          onClose={editor.closeModal}
          onSaved={(_, savedDate) => {
            editor.closeModal();
            setDate(savedDate);
            setQuery("");
            setStatus("all");
            setView("list");
            setNotice(true);
          }}
        />
      )}
      {cancellation && (
        <SpaCancelDialog
          appointment={cancellation}
          onClose={() => setCancelId(null)}
          onSaved={() => {
            setCancelId(null);
            setNotice(true);
          }}
        />
      )}
      {detail && (
        <SpaAppointmentDetail
          appointment={detail}
          onClose={() => setDetailId(null)}
        />
      )}
    </>
  );
}
