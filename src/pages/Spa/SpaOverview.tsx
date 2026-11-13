import { useState } from "react";
import { Link } from "react-router";
import { useTranslation } from "react-i18next";
import { useAuth } from "@/hooks/useAuth";
import { useAuthorization } from "@/hooks/useAuthorization";
import { canOpenWorkspacePath } from "@/utils/workspace";
import PageMeta from "@/components/common/PageMeta";
import Button from "@/components/ui/button/Button";
import { ArrowRightIcon, BoxCubeIcon, ChatIcon, PlusIcon } from "@/icons";
import { filterSpaAppointments } from "@/features/spa/selectors";
import { spaHourlyVisits } from "@/features/spa/presentation";
import { useSpaPreview } from "@/features/spa/hooks/useSpaPreviewSnapshot";
import SpaPageHeading from "@/features/spa/components/SpaPageHeading";
import SpaDateFilter from "@/features/spa/components/SpaDateFilter";
import SpaBookingDialog from "@/features/spa/components/SpaBookingDialog";
import SpaAppointmentDetail from "@/features/spa/components/SpaAppointmentDetail";
import SpaPanel from "@/features/spa/components/SpaPanel";
import SpaActivityChart from "@/features/spa/components/SpaActivityChart";
import SpaRoomOccupancy from "@/features/spa/components/SpaRoomOccupancy";
import SpaOverviewStats from "@/features/spa/components/SpaOverviewStats";
import SpaVisitTable from "@/features/spa/components/SpaVisitTable";

export default function SpaOverview() {
  const { t } = useTranslation();
  const { currentUser } = useAuth();
  const { workspaces } = useAuthorization();
  const { snapshot } = useSpaPreview();
  const [date, setDate] = useState(snapshot.date),
    [booking, setBooking] = useState(false),
    [detailId, setDetailId] = useState<string>();
  const allowed = (path: string) => canOpenWorkspacePath(workspaces, path);
  const appointments = filterSpaAppointments(
    snapshot.appointments,
    "",
    "all",
  ).filter((item) => item.status !== "cancelled" && item.date === date);
  const points = spaHourlyVisits(
    appointments,
    snapshot.settings.open,
    snapshot.settings.close,
  );
  const attention = [
    {
      path: "/spa/care",
      icon: ChatIcon,
      title: t("spa.concept.careCount", {
        count: snapshot.careTasks.filter((job) => !job.done && job.due <= date)
          .length,
      }),
      note: t("spa.concept.careHint"),
    },
    {
      path: "/spa/inventory",
      icon: BoxCubeIcon,
      title: t("spa.concept.lowStockCount", {
        count: snapshot.products.filter((p) => p.stock < p.minStock).length,
      }),
      note: t("spa.concept.lowStockHint"),
    },
    {
      path: "/spa/reception",
      icon: ArrowRightIcon,
      title: t("spa.concept.handoverTitle"),
      note: t("spa.concept.handoverHint"),
    },
  ].filter((item) => allowed(item.path));
  const detail = snapshot.appointments.find((item) => item.id === detailId);
  return (
    <>
      <PageMeta
        title={t("spa.meta.overview")}
        description={t("spa.meta.description")}
      />
      <SpaPageHeading
        section="overview"
        actions={
          <div className="flex w-full flex-wrap gap-2 sm:w-auto">
            <SpaDateFilter date={date} onChange={setDate} />
            {allowed("/spa/appointments") && (
              <Button
                size="sm"
                className="!bg-kps-primary hover:!bg-kps-primary-hover"
                startIcon={<PlusIcon className="size-4" />}
                onClick={() => setBooking(true)}
              >
                {t("spa.flow.newBooking")}
              </Button>
            )}
          </div>
        }
      />
      <p className="-mt-3 mb-6 text-sm text-gray-500 dark:text-gray-400">
        {t("spa.concept.welcome", {
          name: currentUser?.name ?? "KPS",
          facility: snapshot.settings.facilityName,
        })}
      </p>
      <div className="space-y-5">
        <SpaOverviewStats snapshot={snapshot} date={date} />
        <div className="grid items-start gap-5 xl:grid-cols-3">
          <SpaPanel
            title={t("spa.concept.activity")}
            description={t("spa.concept.activityHint")}
            className={
              allowed("/spa/rooms") ? "xl:col-span-2" : "xl:col-span-3"
            }
          >
            <div className="p-5">
              <SpaActivityChart points={points} />
              <dl className="mt-3 grid grid-cols-3 gap-3 border-t border-gray-100 pt-4 text-theme-xs dark:border-gray-800">
                {[
                  [
                    t("spa.concept.openHours"),
                    `${snapshot.settings.open} – ${snapshot.settings.close}`,
                  ],
                  [
                    t("spa.concept.appointmentsStaff"),
                    `${appointments.length} / ${snapshot.technicians.length}`,
                  ],
                  [
                    t("spa.concept.durationRange"),
                    `${Math.min(...snapshot.services.map((s) => s.durationMinutes))} – ${Math.max(...snapshot.services.map((s) => s.durationMinutes))} ${t("spa.concept.minuteUnit")}`,
                  ],
                ].map(([label, value]) => (
                  <div key={label}>
                    <dt className="text-gray-500 dark:text-gray-400">
                      {label}
                    </dt>
                    <dd className="mt-1 font-semibold text-gray-800 dark:text-gray-200">
                      {value}
                    </dd>
                  </div>
                ))}
              </dl>
            </div>
          </SpaPanel>
          {allowed("/spa/rooms") && (
            <SpaRoomOccupancy snapshot={snapshot} date={date} />
          )}
        </div>
        <div className="grid items-start gap-5 xl:grid-cols-3">
          <SpaPanel
            title={t("spa.concept.appointmentsDate")}
            description={t("spa.concept.visitsHint")}
            className="xl:col-span-2"
            action={
              allowed("/spa/appointments") && (
                <Link
                  to={`/spa/appointments?date=${date}`}
                  className="inline-flex min-h-11 items-center gap-2 text-theme-xs font-medium text-kps-primary-hover dark:text-gray-200"
                >
                  {t("spa.overview.viewSchedule")}
                  <ArrowRightIcon
                    className="size-4 rtl:rotate-180"
                    aria-hidden="true"
                  />
                </Link>
              )
            }
          >
            <SpaVisitTable
              appointments={appointments.slice(0, 5)}
              renderActions={(appointment) => (
                <button
                  type="button"
                  onClick={() => setDetailId(appointment.id)}
                  className="min-h-11 rounded-lg px-2 text-theme-xs font-medium text-kps-primary-hover hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-800"
                >
                  {t("spa.flow.details")}
                </button>
              )}
            />
          </SpaPanel>
          <div className="space-y-5">
            <SpaPanel title={t("spa.concept.attention")}>
              <div className="divide-y divide-gray-100 px-5 dark:divide-gray-800">
                {attention.map(({ path, icon: Icon, title, note }) => (
                  <Link
                    key={path}
                    to={path}
                    className="flex min-h-20 items-center gap-3 py-4"
                  >
                    <span className="rounded-lg bg-kps-primary/5 p-2 text-kps-primary dark:bg-kps-primary/20 dark:text-gray-300">
                      <Icon className="size-5" aria-hidden="true" />
                    </span>
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-semibold text-gray-800 dark:text-gray-200">
                        {title}
                      </p>
                      <p className="mt-1 text-theme-xs leading-5 text-gray-500 dark:text-gray-400">
                        {note}
                      </p>
                    </div>
                    <ArrowRightIcon
                      className="size-4 shrink-0 text-kps-primary rtl:rotate-180 dark:text-gray-300"
                      aria-hidden="true"
                    />
                  </Link>
                ))}
              </div>
            </SpaPanel>
            <div className="rounded-xl border border-kps-primary/10 bg-kps-primary/5 p-5 dark:border-kps-primary/30 dark:bg-kps-primary/10">
              <h3 className="text-sm font-semibold text-kps-primary-hover dark:text-gray-200">
                {t("spa.concept.journey")}
              </h3>
              <p className="mt-2 text-theme-xs leading-6 text-gray-500 dark:text-gray-400">
                {t("spa.concept.journeySteps")}
              </p>
            </div>
            {allowed("/spa/technician") && (
              <Link
                to="/spa/technician"
                className="inline-flex min-h-11 items-center gap-2 text-sm font-medium text-kps-primary-hover dark:text-gray-200"
              >
                {t("spa.openTechnician")}
                <ArrowRightIcon
                  className="size-4 rtl:rotate-180"
                  aria-hidden="true"
                />
              </Link>
            )}
          </div>
        </div>
      </div>
      {booking && (
        <SpaBookingDialog
          date={date}
          onClose={() => setBooking(false)}
          onSaved={(_, nextDate) => {
            setBooking(false);
            setDate(nextDate);
          }}
        />
      )}
      {detail && (
        <SpaAppointmentDetail
          appointment={detail}
          onClose={() => setDetailId(undefined)}
        />
      )}
    </>
  );
}
