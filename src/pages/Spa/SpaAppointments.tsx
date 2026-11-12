import ComponentCard from "@/components/common/ComponentCard";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import Select from "@/components/form/Select";
import Input from "@/components/form/input/InputField";
import SpaAppointmentList from "@/features/spa/components/SpaAppointmentList";
import useSpaPreviewSnapshot from "@/features/spa/hooks/useSpaPreviewSnapshot";
import { filterSpaAppointments } from "@/features/spa/selectors";
import type { SpaAppointmentStatus } from "@/features/spa/types";
import { useState } from "react";
import { useTranslation } from "react-i18next";

export default function SpaAppointments() {
  const { t } = useTranslation();
  const snapshot = useSpaPreviewSnapshot();
  const [query, setQuery] = useState("");
  const [status, setStatus] = useState<SpaAppointmentStatus | "all">("all");
  const appointments = filterSpaAppointments(
    snapshot.appointments,
    query,
    status,
  );
  return (
    <>
      <PageMeta
        title={t("spa.meta.appointments")}
        description={t("spa.meta.description")}
      />
      <PageBreadCrumb pageTitle={t("spa.navigation.appointments")} />
      <ComponentCard
        title={t("spa.appointments.title")}
        desc={t("spa.sampleDate", { date: snapshot.date })}
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
        <SpaAppointmentList appointments={appointments} />
      </ComponentCard>
    </>
  );
}
