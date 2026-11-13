import SpaTeamSchedule from "@/features/spa/components/SpaTeamSchedule";
import { SpaTabs } from "@/features/spa/components/business/SpaBusinessUi";
import { spaFieldClasses } from "@/features/spa/config";
import ComponentCard from "@/components/common/ComponentCard";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
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
  const [view, setView] = useState("list");
  const [notice, setNotice] = useState(false);
  const editor = useModal();
  const appointments = filterSpaAppointments(
    snapshot.appointments.filter((item) => item.date === date),
    query,
    status,
  );
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
      <PageBreadCrumb pageTitle={t("spa.navigation.appointments")} />
      <div className="space-y-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <SpaDateFilter date={date} onChange={setDate} />
          <Button
            className="!bg-kps-primary hover:!bg-kps-primary-hover"
            onClick={() => {
              setEditingId(null);
              editor.openModal();
            }}
          >
            {t("spa.flow.newBooking")}
          </Button>
        </div>
        {notice && (
          <p
            role="status"
            className="text-sm text-success-700 dark:text-success-400"
          >
            {t("spa.flow.saved")}
          </p>
        )}
        <ComponentCard
          title={t("spa.appointments.title")}
          desc={t("spa.sampleDate", { date })}
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <label
                htmlFor="spa-search"
                className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300"
              >
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
              <label
                htmlFor="spa-status"
                className="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300"
              >
                {t("spa.appointments.status")}
              </label>
              <Select
                id="spa-status"
                placeholder={t("spa.appointments.status")}
                value={status}
                onChange={(value) => {
                  if (["all", "booked", "arrived", "cancelled"].includes(value))
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
          <SpaTabs
            value={view}
            onChange={setView}
            items={["list", "teamSchedule"].map((key) => ({
              key,
              label: t(`spa.business.${key}`),
            }))}
          />
          {view === "teamSchedule" ? (
            <SpaTeamSchedule
              appointments={appointments}
              onDetail={setDetailId}
            />
          ) : (
            <SpaAppointmentList
              appointments={appointments}
              renderActions={(appointment) => (
                <div className="flex flex-wrap gap-2">
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
        </ComponentCard>
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
