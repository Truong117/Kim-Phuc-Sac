import { spaFieldClasses } from "@/features/spa/config";
import { useState } from "react";
import { useTranslation } from "react-i18next";
import { Link, useNavigate, useSearchParams } from "react-router";
import PageMeta from "@/components/common/PageMeta";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import { useAuthorization } from "@/hooks/useAuthorization";
import { useSpaPreview } from "@/features/spa/hooks/useSpaPreviewSnapshot";
import { filterSpaAppointments } from "@/features/spa/selectors";
import { spaReceptionStage } from "@/features/spa/previewWorkflow";
import type { SpaPreviewError } from "@/features/spa/types";
import SpaDateFilter from "@/features/spa/components/SpaDateFilter";
import SpaReceptionCard from "@/features/spa/components/SpaReceptionCard";
import SpaAssignmentDialog from "@/features/spa/components/SpaAssignmentDialog";
import SpaAppointmentDetail from "@/features/spa/components/SpaAppointmentDetail";
import SpaFormError from "@/features/spa/components/SpaFormError";

const stages = [
  "all",
  "booked",
  "waiting",
  "assigned",
  "serving",
  "completed",
] as const;
export default function SpaReception() {
  const { t } = useTranslation();
  const { snapshot, execute } = useSpaPreview();
  const { workspaces } = useAuthorization();
  const canOpenTechnician = workspaces.some(
    (item) => item.id === "spa" && item.sections.includes("technician"),
  );
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const [date, setDate] = useState(params.get("date") ?? snapshot.date);
  const [query, setQuery] = useState(params.get("appointment") ?? "");
  const [stage, setStage] = useState<(typeof stages)[number]>("all");
  const [selection, setSelection] = useState<{
    appointmentId: string;
    taskId: string;
  } | null>(null);
  const [detailId, setDetailId] = useState<string | null>(null);
  const [error, setError] = useState<SpaPreviewError>();
  const [notice, setNotice] = useState(false);
  const day = snapshot.appointments.filter(
    (item) => item.date === date && item.status !== "cancelled",
  );
  const visible = filterSpaAppointments(day, query, "all").filter(
    (item) => stage === "all" || spaReceptionStage(item) === stage,
  );
  const selected = snapshot.appointments.find(
    (item) => item.id === selection?.appointmentId,
  );
  const selectedTask = selected?.tasks.find(
    (item) => item.id === selection?.taskId,
  );
  const detail = snapshot.appointments.find((item) => item.id === detailId);
  return (
    <>
      <PageMeta
        title={t("spa.meta.section", { name: t("spa.navigation.reception") })}
        description={t("spa.meta.description")}
      />
      <PageBreadCrumb pageTitle={t("spa.navigation.reception")} />
      <div className="space-y-5">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <SpaDateFilter date={date} onChange={setDate} />
          <Link
            to="/spa/appointments"
            className="inline-flex min-h-11 items-center rounded-lg bg-kps-primary px-4 py-3 text-sm font-semibold text-white hover:bg-kps-primary-hover"
          >
            {t("spa.flow.manageBookings")}
          </Link>
        </div>
        <div
          role="group"
          aria-label={t("spa.flow.queueFilter")}
          className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6"
        >
          {stages.map((key) => (
            <button
              type="button"
              key={key}
              aria-pressed={stage === key}
              onClick={() => setStage(key)}
              className={`min-h-20 rounded-xl border p-3 text-start focus-visible:ring-2 focus-visible:ring-kps-primary ${stage === key ? "border-kps-primary bg-kps-primary/5 dark:bg-kps-primary/15" : "border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900"}`}
            >
              <span className="text-sm text-gray-600 dark:text-gray-300">
                {t(`spa.flow.stages.${key}`)}
              </span>
              <span className="mt-2 block text-xl font-semibold text-gray-900 dark:text-white">
                {key === "all"
                  ? day.length
                  : day.filter((item) => spaReceptionStage(item) === key)
                      .length}
              </span>
            </button>
          ))}
        </div>
        <div className="sm:max-w-sm">
          <Label htmlFor="spa-reception-search">
            {t("spa.appointments.search")}
          </Label>
          <Input
            id="spa-reception-search"
            type="search"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            className={spaFieldClasses}
          />
        </div>
        <SpaFormError error={error} />
        {notice && (
          <p
            role="status"
            className="text-sm text-success-700 dark:text-success-400"
          >
            {t("spa.flow.saved")}
          </p>
        )}
        <section
          aria-label={t("spa.flow.queue")}
          className="grid items-start gap-4 lg:grid-cols-2"
        >
          {visible.map((appointment) => (
            <SpaReceptionCard
              key={appointment.id}
              appointment={appointment}
              onDetails={() => setDetailId(appointment.id)}
              onAssign={(taskId) =>
                setSelection({ appointmentId: appointment.id, taskId })
              }
              onReceive={() => {
                const error = execute({ kind: "receive", id: appointment.id });
                setError(error);
                if (!error) {
                  setNotice(true);
                  setStage("all");
                }
              }}
              onOpenTechnician={
                canOpenTechnician
                  ? (id) => {
                      execute({ kind: "technician", id });
                      navigate(`/spa/technician?date=${date}`);
                    }
                  : undefined
              }
            />
          ))}
          {!visible.length && (
            <p
              role="status"
              className="col-span-full rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400"
            >
              {t("spa.flow.noQueue")}
            </p>
          )}
        </section>
      </div>
      {selected && selectedTask && (
        <SpaAssignmentDialog
          key={selectedTask.id}
          appointment={selected}
          task={selectedTask}
          onClose={() => setSelection(null)}
          onSaved={() => {
            setSelection(null);
            setNotice(true);
            setError(undefined);
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
