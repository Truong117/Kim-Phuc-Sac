import { useEffect, useId, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import { spaFieldClasses } from "../../config";
import { normalizeSpaPhone } from "../../customerValidation";
import {
  findSpaCustomer,
  listSpaCustomers,
  type SpaBookingPage,
  type SpaSavedCustomer,
} from "../../services/spaBookingService";
import { SpaAction, SpaHint, SpaTabs } from "../business/SpaBusinessUi";
import SpaLiveFormError from "./SpaLiveFormError";
import SpaLivePagination from "./SpaLivePagination";

export interface SpaBookingCustomerChoice {
  mode: "existing" | "new";
  selected: SpaSavedCustomer | null;
  name: string;
  phone: string;
  duplicate: SpaSavedCustomer | null;
}

type SearchResult =
  | { status: "pending" | "failed" }
  | { status: "exact"; customer: SpaSavedCustomer | null }
  | { status: "list"; page: SpaBookingPage<SpaSavedCustomer> };

export default function SpaLiveBookingCustomerFields({
  value,
  onChange,
  canCreate,
  invalid,
  fail,
}: {
  value: SpaBookingCustomerChoice;
  onChange: (value: Partial<SpaBookingCustomerChoice>) => void;
  canCreate: boolean;
  invalid: string[];
  fail: (message: string, ...fields: string[]) => void;
}) {
  const { t } = useTranslation();
  const id = useId();
  const [query, setQuery] = useState("");
  const [result, setResult] = useState<SearchResult>();
  const [phoneCheck, setPhoneCheck] = useState<
    "pending" | "available" | "failed"
  >();
  const invalidCustomer = invalid.includes("customer_id");
  const showPicker = result?.status === "list" && result.page.data.length > 0;
  const request = useRef<AbortController>(null);
  useEffect(() => () => request.current?.abort(), []);
  const resetRequest = () => {
    request.current?.abort();
    const controller = new AbortController();
    request.current = controller;
    return controller.signal;
  };
  const switchMode = (mode: SpaBookingCustomerChoice["mode"]) => {
    request.current?.abort();
    if (result?.status === "pending") setResult(undefined);
    if (phoneCheck === "pending") setPhoneCheck(undefined);
    onChange({ mode });
  };

  const search = async (page = 1) => {
    const input = query.trim();
    if (!input) {
      fail("lookupRequired", "customer_id");
      return;
    }
    const signal = resetRequest();
    setResult({ status: "pending" });
    const phone = normalizeSpaPhone(input);
    try {
      if (/^\d{10,15}$/.test(phone)) {
        const customer = await findSpaCustomer(phone, signal);
        if (signal.aborted) return;
        setResult({ status: "exact", customer });
        onChange({ selected: customer });
      } else {
        const result = await listSpaCustomers({ search: input, page }, signal);
        if (!signal.aborted) setResult({ status: "list", page: result });
      }
    } catch {
      if (!signal.aborted) setResult({ status: "failed" });
    }
  };

  const checkPhone = async () => {
    const phone = normalizeSpaPhone(value.phone);
    if (!/^\d{10,15}$/.test(phone)) {
      fail("phoneInvalid", "customer.phone");
      return;
    }
    const signal = resetRequest();
    setPhoneCheck("pending");
    try {
      const duplicate = await findSpaCustomer(phone, signal);
      if (signal.aborted) return;
      onChange({ duplicate });
      setPhoneCheck(duplicate ? undefined : "available");
    } catch {
      if (!signal.aborted) setPhoneCheck("failed");
    }
  };

  return (
    <fieldset className="min-w-0 space-y-3">
      <legend className="mb-2 font-medium text-gray-900 dark:text-white">
        {t("spa.booking.customerType")}
      </legend>
      {canCreate && (
        <SpaTabs
          value={value.mode}
          onChange={(mode) =>
            switchMode(mode as SpaBookingCustomerChoice["mode"])
          }
          items={["existing", "new"].map((key) => ({
            key,
            label: t(`spa.booking.${key}`),
          }))}
        />
      )}
      {value.mode === "existing" ? (
        <>
          <div className="space-y-2">
            <Label htmlFor={`${id}-query`}>{t("spa.flow.findCustomer")}</Label>
            <div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
              <Input
                id={`${id}-query`}
                type="search"
                autoComplete="off"
                maxLength={100}
                value={query}
                onChange={(event) => {
                  request.current?.abort();
                  setQuery(event.target.value);
                  setResult(undefined);
                  onChange({ selected: null });
                }}
                onKeyDown={(event) => {
                  if (event.key !== "Enter") return;
                  event.preventDefault();
                  if (result?.status !== "pending") void search();
                }}
                error={invalidCustomer && !showPicker}
                aria-invalid={(invalidCustomer && !showPicker) || undefined}
                className={spaFieldClasses}
              />
              <SpaAction
                secondary
                disabled={result?.status === "pending"}
                onClick={() => void search()}
              >
                {t("spa.live.lookupCustomer")}
              </SpaAction>
            </div>
          </div>
          {result?.status === "pending" && (
            <p role="status">{t("spa.live.loading")}</p>
          )}
          {result?.status === "failed" && (
            <SpaLiveFormError error="lookupError" />
          )}
          {((result?.status === "exact" && !result.customer) ||
            (result?.status === "list" && !result.page.data.length)) && (
            <p
              role="status"
              className="text-sm text-gray-500 dark:text-gray-400"
            >
              {t("spa.flow.noCustomers")}
            </p>
          )}
          {showPicker && (
            <div className="space-y-2">
              <Label htmlFor={`${id}-customer`}>
                {t("spa.flow.chooseCustomer")}
              </Label>
              <Select
                id={`${id}-customer`}
                placeholder={t("spa.flow.chooseCustomer")}
                value={value.selected?.id ?? ""}
                options={result.page.data.map((customer) => ({
                  value: customer.id,
                  label: `${customer.name} · ${customer.phone}`,
                }))}
                onChange={(customerId) =>
                  onChange({
                    selected:
                      result.page.data.find((item) => item.id === customerId) ??
                      null,
                  })
                }
                className={spaFieldClasses}
                error={invalidCustomer}
                required
              />
              <SpaLivePagination
                meta={result.page.meta}
                onChange={(page) => void search(page)}
              />
            </div>
          )}
          {value.selected && (
            <p
              role="status"
              className="rounded-lg border border-gray-200 px-3 py-2 text-sm font-medium break-words text-gray-800 dark:border-gray-700 dark:text-gray-100"
            >
              {value.selected.name} · {value.selected.phone}
            </p>
          )}
        </>
      ) : (
        <>
          <SpaHint>{t("spa.live.inlineCustomerHint")}</SpaHint>
          <div>
            <Label htmlFor={`${id}-name`}>{t("spa.flow.customerName")}</Label>
            <Input
              id={`${id}-name`}
              value={value.name}
              maxLength={100}
              autoComplete="off"
              onChange={(event) => onChange({ name: event.target.value })}
              error={invalid.includes("customer.name")}
              aria-invalid={invalid.includes("customer.name") || undefined}
              className={spaFieldClasses}
              required
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor={`${id}-phone`}>{t("spa.live.phone")}</Label>
            <div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
              <Input
                id={`${id}-phone`}
                type="tel"
                autoComplete="off"
                maxLength={24}
                value={value.phone}
                onChange={(event) => {
                  request.current?.abort();
                  setPhoneCheck(undefined);
                  onChange({ phone: event.target.value, duplicate: null });
                }}
                error={invalid.includes("customer.phone")}
                aria-invalid={invalid.includes("customer.phone") || undefined}
                className={spaFieldClasses}
                required
              />
              <SpaAction
                secondary
                disabled={phoneCheck === "pending"}
                onClick={() => void checkPhone()}
              >
                {t("spa.live.checkPhone")}
              </SpaAction>
            </div>
            {phoneCheck === "pending" && (
              <p role="status">{t("spa.live.loading")}</p>
            )}
            {phoneCheck === "available" && (
              <p role="status">{t("spa.live.noCustomerFound")}</p>
            )}
            {phoneCheck === "failed" && (
              <SpaLiveFormError error="lookupError" />
            )}
          </div>
          {value.duplicate && (
            <div className="space-y-2 rounded-xl border border-warning-200 bg-warning-50 p-3 text-sm text-warning-700 dark:border-warning-800 dark:bg-warning-500/10 dark:text-warning-400">
              <p>
                {t("spa.booking.duplicateHint", {
                  name: value.duplicate.name,
                  phone: value.duplicate.phone,
                })}
              </p>
              <SpaAction
                secondary
                onClick={() => {
                  const duplicate = value.duplicate;
                  if (!duplicate) return;
                  setQuery(duplicate.phone);
                  setResult({ status: "exact", customer: duplicate });
                  onChange({ mode: "existing", selected: duplicate });
                }}
              >
                {t("spa.booking.useExisting")}
              </SpaAction>
            </div>
          )}
        </>
      )}
    </fieldset>
  );
}
