import { spaFieldClasses } from "@/features/spa/config";
import { useState } from "react";
import { useTranslation } from "react-i18next";
import { Link, useLocation } from "react-router";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import Input from "@/components/form/input/InputField";
import TextArea from "@/components/form/input/TextArea";
import Button from "@/components/ui/button/Button";
import { TrashBinIcon, PlusIcon } from "@/icons";
import useSpaForm from "../hooks/useSpaForm";
import { useSpaPreview } from "../hooks/useSpaPreviewSnapshot";
import { normalizeSpaText } from "../previewWorkflow";
import type { SpaAppointment, SpaBookingDraft } from "../types";
import SpaDialog from "./SpaDialog";
import SpaFormError from "./SpaFormError";

export default function SpaBookingDialog({
  appointment,
  customerId = "",
  serviceKey = "",
  packageId,
  date,
  onClose,
  onSaved,
}: {
  appointment?: SpaAppointment;
  customerId?: string;
  serviceKey?: string;
  packageId?: string;
  date: string;
  onClose: () => void;
  onSaved: (id: string, date: string) => void;
}) {
  const { t } = useTranslation();
  const { pathname } = useLocation();
  const { snapshot, execute } = useSpaPreview();
  const initial: SpaBookingDraft = {
    customerId: appointment?.customerId ?? customerId,
    date: appointment?.date ?? date,
    startTime: appointment?.startTime ?? "14:00",
    serviceKeys: appointment?.tasks.map((task) => task.serviceKey) ?? [
      serviceKey,
    ],
    packageIds: appointment?.tasks.map((task) => task.packageId ?? null) ?? [
      packageId ?? null,
    ],
    note: appointment?.note ?? "",
  };
  const [values, setValues] = useState(initial);
  const [query, setQuery] = useState("");
  const {
    ref: formRef,
    error,
    setError,
    fail,
    close,
  } = useSpaForm(JSON.stringify(values) !== JSON.stringify(initial), onClose);
  const update = <K extends keyof SpaBookingDraft>(
    key: K,
    value: SpaBookingDraft[K],
  ) => {
    setValues((current) => ({ ...current, [key]: value }));
    setError(undefined);
  };
  const matches = snapshot.customers.filter(
    (customer) =>
      customer.id === values.customerId ||
      normalizeSpaText(`${customer.name} ${customer.phone}`).includes(
        normalizeSpaText(query),
      ),
  );
  return (
    <SpaDialog
      title={t(appointment ? "spa.flow.editBooking" : "spa.flow.newBooking")}
      onClose={close}
    >
      <form
        ref={formRef}
        className="space-y-5"
        noValidate
        onSubmit={(event) => {
          event.preventDefault();
          let sequence = snapshot.appointments.length + 1;
          while (
            snapshot.appointments.some(
              (item) => item.id === `SPA-${String(sequence).padStart(3, "0")}`,
            )
          )
            sequence++;
          const id =
            appointment?.id ?? `SPA-${String(sequence).padStart(3, "0")}`;
          const error = execute({
            kind: "booking",
            id,
            draft: values,
            editing: Boolean(appointment),
          });
          if (error) fail(error);
          else onSaved(id, values.date);
        }}
      >
        <SpaFormError error={error} />
        {appointment && (
          <p className="text-sm text-warning-700 dark:text-warning-400">
            {t("spa.flow.editBookingHint")}
          </p>
        )}
        <div>
          <Label htmlFor="spa-booking-search">
            {t("spa.flow.findCustomer")}
          </Label>
          <Input
            id="spa-booking-search"
            type="search"
            value={query}
            onChange={(event) => setQuery(event.target.value)}
            className={spaFieldClasses}
          />
        </div>
        <div>
          <Label htmlFor="spa-booking-customer">
            {t("spa.flow.chooseCustomer")}
          </Label>
          <Select
            id="spa-booking-customer"
            placeholder={t("spa.flow.chooseCustomer")}
            value={values.customerId}
            onChange={(value) => {
              setValues((v) => ({
                ...v,
                customerId: value,
                packageIds: v.serviceKeys.map(() => null),
              }));
              setError(undefined);
            }}
            options={matches.map((customer) => ({
              value: customer.id,
              label: `${customer.name} · ${customer.phone}`,
            }))}
            required
            error={error === "customerRequired"}
            className={spaFieldClasses}
          />
          {pathname !== "/spa/customers" && (
            <Link
              to="/spa/customers"
              className="mt-1 inline-flex min-h-11 items-center text-sm font-medium text-kps-primary dark:text-gray-200"
            >
              {t("spa.flow.manageCustomers")}
            </Link>
          )}
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <Label htmlFor="spa-booking-date">{t("spa.flow.date")}</Label>
            <KpsDatePicker
              id="spa-booking-date"
              value={values.date}
              onChange={(value) => update("date", value)}
              ariaLabel={t("spa.flow.date")}
              placeholder={t("spa.flow.date")}
              error={error === "dateInvalid"}
              required
            />
          </div>
          <div>
            <Label htmlFor="spa-booking-time">{t("spa.flow.time")}</Label>
            <Input
              id="spa-booking-time"
              type="time"
              value={values.startTime}
              onInput={(event) =>
                update("startTime", event.currentTarget.value)
              }
              className={spaFieldClasses}
              required
              error={error === "timeInvalid" || error === "customerConflict"}
              aria-invalid={
                error === "timeInvalid" || error === "customerConflict"
              }
            />
          </div>
        </div>
        <fieldset className="space-y-3">
          <legend className="mb-2 font-medium text-gray-800 dark:text-white">
            {t("spa.flow.services")}
          </legend>
          {values.serviceKeys.map((key, index) => (
            <div key={index} className="space-y-3">
              <div className="flex items-end gap-2">
                <div className="min-w-0 flex-1">
                  <Label htmlFor={`spa-service-${index}`}>
                    {t("spa.flow.serviceNumber", { number: index + 1 })}
                  </Label>
                  <Select
                    id={`spa-service-${index}`}
                    placeholder={t("spa.flow.chooseService")}
                    value={key}
                    onChange={(value) => {
                      setValues((v) => ({
                        ...v,
                        serviceKeys: v.serviceKeys.map((old, at) =>
                          at === index ? value : old,
                        ),
                        packageIds: v.serviceKeys.map((_, at) =>
                          at === index ? null : (v.packageIds?.[at] ?? null),
                        ),
                      }));
                      setError(undefined);
                    }}
                    options={snapshot.services.map((service) => ({
                      value: service.key,
                      label: `${service.name ?? t(`spa.services.${service.key}`)} · ${t("spa.minutes", { count: service.durationMinutes })}`,
                    }))}
                    error={error === "serviceRequired" && !key}
                    required
                    className={spaFieldClasses}
                  />
                </div>
                {values.serviceKeys.length > 1 && (
                  <button
                    type="button"
                    onClick={() => {
                      setValues((v) => ({
                        ...v,
                        serviceKeys: v.serviceKeys.filter(
                          (_, at) => at !== index,
                        ),
                        packageIds: v.serviceKeys
                          .filter((_, at) => at !== index)
                          .map(
                            (_, at) =>
                              v.packageIds?.[at >= index ? at + 1 : at] ?? null,
                          ),
                      }));
                      setError(undefined);
                    }}
                    aria-label={t("spa.flow.removeService", {
                      number: index + 1,
                    })}
                    className="flex size-11 items-center justify-center rounded-lg border border-gray-200 text-error-600 dark:border-gray-700 dark:text-error-400"
                  >
                    <TrashBinIcon className="size-5" />
                  </button>
                )}
              </div>
              <div>
                <Label htmlFor={`spa-package-${index}`}>
                  {t("spa.business.packageSelect", { number: index + 1 })}
                </Label>
                <Select
                  id={`spa-package-${index}`}
                  value={values.packageIds?.[index] ?? ""}
                  placeholder={t("spa.business.packageNone")}
                  options={[
                    { value: "", label: t("spa.business.packageNone") },
                    ...snapshot.packages
                      .filter(
                        (p) =>
                          p.customerId === values.customerId &&
                          p.serviceKey === key &&
                          p.expires >= values.date &&
                          p.used < p.total,
                      )
                      .map((p) => ({
                        value: p.id,
                        label: t("spa.business.packageBalance", {
                          name: p.name,
                          count: p.total - p.used,
                        }),
                      })),
                  ]}
                  onChange={(value) => {
                    setValues((v) => ({
                      ...v,
                      packageIds: v.serviceKeys.map((_, at) =>
                        at === index ? value : (v.packageIds?.[at] ?? null),
                      ),
                    }));
                    setError(undefined);
                  }}
                  error={error === "packageInvalid" || error === "packageEmpty"}
                  className={spaFieldClasses}
                />
              </div>
            </div>
          ))}
          <Button
            type="button"
            variant="outline"
            size="sm"
            startIcon={<PlusIcon className="size-4" />}
            onClick={() => {
              setValues((v) => ({
                ...v,
                serviceKeys: [...v.serviceKeys, ""],
                packageIds: [...(v.packageIds ?? []), null],
              }));
              setError(undefined);
            }}
            disabled={values.serviceKeys.length >= 5}
          >
            {t("spa.flow.addService")}
          </Button>
          <p className="text-sm text-gray-500 dark:text-gray-400">
            {t("spa.flow.totalDuration", {
              count: values.serviceKeys.reduce(
                (sum, key) =>
                  sum +
                  (snapshot.services.find((item) => item.key === key)
                    ?.durationMinutes ?? 0),
                0,
              ),
            })}
          </p>
        </fieldset>
        <div>
          <Label htmlFor="spa-booking-note">{t("spa.flow.bookingNote")}</Label>
          <TextArea
            id="spa-booking-note"
            placeholder={t("spa.flow.optionalNote")}
            value={values.note}
            onChange={(value) => update("note", value)}
            className={spaFieldClasses}
            error={error === "noteTooLong"}
          />
        </div>
        <div className="flex flex-wrap justify-end gap-3">
          <Button type="button" variant="outline" onClick={close}>
            {t("spa.flow.back")}
          </Button>
          <Button
            type="submit"
            className="!bg-kps-primary hover:!bg-kps-primary-hover"
          >
            {t("spa.flow.saveBooking")}
          </Button>
        </div>
      </form>
    </SpaDialog>
  );
}
