import { useTranslation } from "react-i18next";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import TextArea from "@/components/form/input/TextArea";
import Select from "@/components/form/Select";
import { spaFieldClasses } from "../config";
import { normalizeSpaPhone } from "../customerValidation";
import { normalizeSpaText } from "../previewWorkflow";
import { useSpaPreview } from "../hooks/useSpaPreviewSnapshot";
import type { SpaCustomer, SpaPreviewError } from "../types";
import { SpaTabs, SpaAction } from "./business/SpaBusinessUi";
export default function SpaBookingCustomerFields({
  mode,
  onMode,
  customerId,
  onSelect,
  query,
  onQuery,
  customer,
  onCustomer,
  error,
}: {
  mode: "existing" | "new";
  onMode: (mode: "existing" | "new") => void;
  customerId: string;
  onSelect: (id: string) => void;
  query: string;
  onQuery: (query: string) => void;
  customer: Omit<SpaCustomer, "id">;
  onCustomer: (customer: Omit<SpaCustomer, "id">) => void;
  error?: SpaPreviewError;
}) {
  const { t } = useTranslation();
  const { snapshot } = useSpaPreview();
  const matches = snapshot.customers.filter(
    (c) =>
      c.id === customerId ||
      normalizeSpaText(`${c.name} ${c.phone}`).includes(
        normalizeSpaText(query),
      ),
  );
  const duplicate = customer.phone.trim()
    ? snapshot.customers.find(
        (c) => normalizeSpaPhone(c.phone) === normalizeSpaPhone(customer.phone),
      )
    : undefined;
  const update = (key: keyof typeof customer, value: string) =>
    onCustomer({ ...customer, [key]: value });
  return (
    <fieldset className="space-y-4">
      <legend className="mb-3 font-medium text-gray-900 dark:text-white">
        {t("spa.booking.customerType")}
      </legend>
      <SpaTabs
        value={mode}
        onChange={(value) => onMode(value as "existing" | "new")}
        items={["existing", "new"].map((key) => ({
          key,
          label: t(`spa.booking.${key}`),
        }))}
      />
      {mode === "existing" ? (
        <>
          <div>
            <Label htmlFor="spa-booking-search">
              {t("spa.flow.findCustomer")}
            </Label>
            <Input
              id="spa-booking-search"
              type="search"
              value={query}
              onChange={(e) => onQuery(e.target.value)}
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
              value={customerId}
              onChange={onSelect}
              options={matches.map((c) => ({
                value: c.id,
                label: `${c.name} · ${c.phone}`,
              }))}
              required
              error={error === "customerRequired"}
              className={spaFieldClasses}
            />
          </div>
          {!matches.length && (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              {t("spa.booking.noExisting")}
            </p>
          )}
        </>
      ) : (
        <>
          <p className="text-sm text-gray-500 dark:text-gray-400">
            {t("spa.booking.atomicHint")}
          </p>
          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <Label htmlFor="spa-booking-new-name">
                {t("spa.flow.customerName")}
              </Label>
              <Input
                id="spa-booking-new-name"
                autoComplete="off"
                value={customer.name}
                onChange={(e) => update("name", e.target.value)}
                maxLength={100}
                required
                aria-invalid={error === "nameRequired"}
                error={error === "nameRequired"}
                className={spaFieldClasses}
              />
            </div>
            <div>
              <Label htmlFor="spa-booking-new-phone">
                {t("spa.flow.phone")}
              </Label>
              <Input
                id="spa-booking-new-phone"
                type="tel"
                autoComplete="off"
                value={customer.phone}
                onChange={(e) => update("phone", e.target.value)}
                maxLength={24}
                required
                aria-invalid={
                  error === "phoneInvalid" || error === "phoneDuplicate"
                }
                error={error === "phoneInvalid" || error === "phoneDuplicate"}
                className={spaFieldClasses}
              />
            </div>
          </div>
          {duplicate && (
            <div className="space-y-2 rounded-xl border border-warning-200 bg-warning-50 p-3 text-sm text-warning-700 dark:border-warning-800 dark:bg-warning-500/10 dark:text-warning-400">
              <p>
                {t("spa.booking.duplicateHint", {
                  name: duplicate.name,
                  phone: duplicate.phone,
                })}
              </p>
              <SpaAction
                secondary
                onClick={() => {
                  onMode("existing");
                  onSelect(duplicate.id);
                  onQuery(duplicate.phone);
                }}
              >
                {t("spa.booking.useExisting")}
              </SpaAction>
            </div>
          )}
          <div>
            <Label htmlFor="spa-booking-new-note">
              {t("spa.flow.customerNote")}
            </Label>
            <TextArea
              id="spa-booking-new-note"
              value={customer.note}
              placeholder={t("spa.flow.optionalNote")}
              onChange={(v) => update("note", v)}
              className={spaFieldClasses}
              error={error === "customerNoteTooLong"}
            />
          </div>
        </>
      )}
    </fieldset>
  );
}
