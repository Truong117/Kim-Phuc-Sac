import { useAuthorization } from "@/hooks/useAuthorization";
import { canOpenWorkspacePath } from "@/utils/workspace";
import { SpaStats } from "@/features/spa/components/business/SpaBusinessUi";
import {
  spaMoney,
  spaReport,
  spaInvoiceBalance,
} from "@/features/spa/businessSelectors";
import ComponentCard from "@/components/common/ComponentCard";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import SpaAppointmentList from "@/features/spa/components/SpaAppointmentList";
import SpaOverviewStats from "@/features/spa/components/SpaOverviewStats";
import useSpaPreviewSnapshot from "@/features/spa/hooks/useSpaPreviewSnapshot";
import { filterSpaAppointments } from "@/features/spa/selectors";
import { ArrowRightIcon } from "@/icons";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";

export default function SpaOverview() {
  const { t } = useTranslation();
  const { workspaces } = useAuthorization();
  const snapshot = useSpaPreviewSnapshot();
  const appointments = filterSpaAppointments(
    snapshot.appointments,
    "",
    "all",
  ).filter(
    (item) => item.status !== "cancelled" && item.date === snapshot.date,
  );
  return (
    <>
      <PageMeta
        title={t("spa.meta.overview")}
        description={t("spa.meta.description")}
      />
      <PageBreadCrumb pageTitle={t("spa.navigation.overview")} />
      <div className="space-y-6">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm text-gray-500 dark:text-gray-400">
            {snapshot.settings.facilityName} ·{" "}
            {t("spa.sampleDate", { date: snapshot.date })}
          </p>
          <Link
            to="/spa/technician"
            className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-kps-primary px-4 py-3 text-sm font-semibold text-white hover:bg-kps-primary-hover focus-visible:ring-2 focus-visible:ring-kps-primary focus-visible:ring-offset-2 dark:bg-kps-primary dark:text-white"
          >
            {t("spa.openTechnician")}
            <ArrowRightIcon
              className="size-5 rtl:rotate-180"
              aria-hidden="true"
            />
          </Link>
        </div>
        <SpaOverviewStats snapshot={snapshot} />
        {canOpenWorkspacePath(workspaces, "/spa/cashier") && (
          <SpaStats
            items={[
              {
                label: t("spa.business.moneyToday"),
                value: spaMoney(
                  spaReport(snapshot, snapshot.date, snapshot.date).collected,
                ),
              },
              {
                label: t("spa.business.outstanding"),
                value: spaMoney(
                  snapshot.invoices.reduce(
                    (s, i) => s + spaInvoiceBalance(snapshot, i.id),
                    0,
                  ),
                ),
              },
              {
                label: t("spa.business.careOpen"),
                value: snapshot.careTasks.filter((j) => !j.done).length,
              },
              {
                label: t("spa.business.lowStock"),
                value: snapshot.products.filter((p) => p.stock < p.minStock)
                  .length,
              },
            ]}
          />
        )}

        <div className="grid gap-6 xl:grid-cols-3">
          <ComponentCard
            title={t("spa.overview.schedule")}
            className="xl:col-span-2"
            compact
          >
            <SpaAppointmentList appointments={appointments.slice(0, 4)} />
            <Link
              to="/spa/appointments"
              className="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-kps-primary-hover focus-visible:ring-2 focus-visible:ring-kps-primary dark:text-gray-200"
            >
              {t("spa.overview.viewSchedule")}
              <ArrowRightIcon
                className="size-4 rtl:rotate-180"
                aria-hidden="true"
              />
            </Link>
          </ComponentCard>
          <ComponentCard title={t("spa.overview.quickLinks")} compact>
            <div className="space-y-3">
              {(["reception", "customers", "treatments", "cashier"] as const)
                .filter((key) =>
                  canOpenWorkspacePath(workspaces, `/spa/${key}`),
                )
                .map((key) => (
                  <Link
                    key={key}
                    to={`/spa/${key}`}
                    className="flex min-h-11 items-center justify-between gap-3 rounded-xl border border-gray-200 p-4 text-sm font-medium text-gray-800 hover:border-kps-primary/40 focus-visible:ring-2 focus-visible:ring-kps-primary dark:border-gray-700 dark:text-gray-200 dark:hover:border-kps-primary-hover"
                  >
                    {t(`spa.navigation.${key}`)}
                    <ArrowRightIcon
                      className="size-4 shrink-0 rtl:rotate-180"
                      aria-hidden="true"
                    />
                  </Link>
                ))}
            </div>
            <p className="text-sm text-gray-500 dark:text-gray-400">
              {t("spa.overview.statusNote")}
            </p>
          </ComponentCard>
        </div>
      </div>
    </>
  );
}
