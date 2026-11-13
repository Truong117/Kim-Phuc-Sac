import { spaFieldClasses } from "@/features/spa/config";
import { useState } from "react";
import { useTranslation } from "react-i18next";
import { Link, useNavigate, useSearchParams } from "react-router";
import PageMeta from "@/components/common/PageMeta";
import SpaPageHeading from "@/features/spa/components/SpaPageHeading";
import SpaBookingDialog from "@/features/spa/components/SpaBookingDialog";
import Button from "@/components/ui/button/Button";
import { PlusIcon } from "@/icons";
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
  const [booking, setBooking] = useState(false);
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
      <SpaPageHeading
        section="reception"
        actions={
          <div className="flex flex-wrap gap-2">
            <Link
              to={`/spa/appointments?date=${date}`}
              className="inline-flex min-h-11 items-center rounded-lg border border-gray-200 bg-white px-3 text-sm font-medium text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
            >
              {t("spa.business.teamSchedule")}
            </Link>
            <Button
              size="sm"
              className="!bg-kps-primary hover:!bg-kps-primary-hover"
              startIcon={<PlusIcon className="size-4" />}
              onClick={() => setBooking(true)}
            >
              {t("spa.flow.newBooking")}
            </Button>
          </div>
        }
      />
      <div className="space-y-5">
        <div className="flex flex-wrap items-end gap-3">
          <SpaDateFilter date={date} onChange={setDate} />
          <div className="w-full sm:max-w-xs">
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
        </div>
        <div
          role="group"
          aria-label={t("spa.flow.queueFilter")}
          className="flex flex-wrap gap-2"
        >
          {stages.map((key) => (
            <button
              type="button"
              key={key}
              aria-pressed={stage === key}
              onClick={() => setStage(key)}
              className={`min-h-11 rounded-lg border px-3 py-2 text-start focus-visible:ring-2 focus-visible:ring-kps-primary ${stage === key ? "border-kps-primary bg-kps-primary/5 dark:bg-kps-primary/15" : "border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900"}`}
            >
              <span className="text-sm text-gray-600 dark:text-gray-300">
                {t(`spa.flow.stages.${key}`)}
              </span>
              <span className="ms-2 inline-flex min-w-5 justify-center rounded bg-gray-100 px-1 text-theme-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                {key === "all"
                  ? day.length
                  : day.filter((item) => spaReceptionStage(item) === key)
                      .length}
              </span>
            </button>
          ))}
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
          className="grid items-start gap-4 sm:grid-cols-2 xl:grid-cols-4"
        >
          {(["booked", "waiting", "serving", "completed"] as const).map(
            (column) => {
              const cards = visible.filter((appointment) => {
                const state = spaReceptionStage(appointment);
                return column === "waiting"
                  ? state === "waiting" || state === "assigned"
                  : state === column;
              });
              return (
                <section
                  key={column}
                  aria-label={t(`spa.concept.board.${column}`)}
                  className="min-w-0 rounded-xl bg-gray-100 p-3 dark:bg-gray-800/50"
                >
                  <div className="mb-3 flex items-center justify-between gap-2">
                    <h3 className="text-theme-xs font-semibold text-gray-700 dark:text-gray-200">
                      {t(`spa.concept.board.${column}`)}
                    </h3>
                    <span className="text-theme-xs text-gray-500 dark:text-gray-400">
                      {cards.length}
                    </span>
                  </div>
                  <div className="space-y-3">
                    {cards.map((appointment) => (
                      <SpaReceptionCard
                        key={appointment.id}
                        appointment={appointment}
                        onDetails={() => setDetailId(appointment.id)}
                        onAssign={(taskId) =>
                          setSelection({
                            appointmentId: appointment.id,
                            taskId,
                          })
                        }
                        onReceive={() => {
                          const error = execute({
                            kind: "receive",
                            id: appointment.id,
                          });
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
                    {!cards.length && (
                      <p className="py-8 text-center text-theme-xs text-gray-500 dark:text-gray-400">
                        {t("spa.flow.noQueue")}
                      </p>
                    )}
                  </div>
                </section>
              );
            },
          )}
        </section>
      </div>
      {booking && (
        <SpaBookingDialog
          date={date}
          onClose={() => setBooking(false)}
          onSaved={(_, nextDate) => {
            setBooking(false);
            setDate(nextDate);
            setQuery("");
            setStage("all");
            setNotice(true);
          }}
        />
      )}
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
