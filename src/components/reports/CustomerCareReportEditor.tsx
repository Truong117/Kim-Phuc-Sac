import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import CustomerCareRow from "./CustomerCareRow";
import {
  getCareCustomers,
  type CareCustomerPage,
} from "@/services/customerCareService";
import {
  blankCareEntry,
  careEntryIdentity,
  careEntryName,
} from "@/utils/careReports";
import type { CareReportEntry, ManagedTask } from "@/types/tasks";

export default function CustomerCareReportEditor({
  task,
  value,
  disabled,
  onChange,
  onBusy,
  onCountPending,
}: {
  task: ManagedTask;
  value: CareReportEntry[];
  disabled: boolean;
  onChange: (entries: CareReportEntry[]) => void;
  onBusy: (busy: boolean) => void;
  onCountPending?: (pending: boolean) => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "customerCare" });
  const [quantity, setQuantity] = useState(String(Math.max(1, value.length)));
  const [trimCount, setTrimCount] = useState<number | null>(null);
  const [error, setError] = useState("");
  const [search, setSearch] = useState(""),
    [query, setQuery] = useState({ search: "", page: 1 });
  const [directory, setDirectory] = useState<CareCustomerPage | null>(null),
    [loadError, setLoadError] = useState(false);
  const [shared, setShared] = useState(false),
    [service, setService] = useState(""),
    [result, setResult] = useState("");
  useEffect(() => {
    const controller = new AbortController();
    getCareCustomers(task.id, query.search, query.page, controller.signal)
      .then((data) => {
        if (!controller.signal.aborted) {
          setDirectory(data);
          setLoadError(false);
        }
      })
      .catch(() => {
        if (!controller.signal.aborted) {
          setDirectory(null);
          setLoadError(true);
        }
      });
    return () => controller.abort();
  }, [task.id, query]);
  function resize(n: number) {
    const next = value.slice(0, n);
    while (next.length < n) next.push(blankCareEntry());
    onChange(next);
    setQuantity(String(n));
    setTrimCount(null);
    setError("");
    onCountPending?.(false);
  }
  function applyCount() {
    const n = Number(quantity);
    if (!Number.isInteger(n) || n < 1 || n > 20) {
      setError(t("countLimit"));
      return;
    }
    if (
      n < value.length &&
      value
        .slice(n)
        .some(
          (e) =>
            e.customer_id ||
            e.new_customer ||
            e.activities.some((a) => a.result || a.service) ||
            e.attachment_ids.length ||
            e.evidence_links.some(Boolean),
        )
    ) {
      setTrimCount(n);
      return;
    }
    resize(n);
  }
  const ids = value.map(careEntryIdentity).filter(Boolean),
    unique = new Set(ids);
  const duplicate = ids.length !== unique.size;
  const outputs = task.progress?.outputs ?? [];
  const added = value.filter(
    (e) =>
      careEntryName(e) &&
      careEntryIdentity(e) &&
      !outputs.some(
        (o) =>
          o.completed &&
          (e.legacy_output_id
            ? o.id === e.legacy_output_id
            : e.customer_id && o.care_customer_id === e.customer_id),
      ),
  ).length;
  const prefix = `care-editor-${task.id}`;
  return (
    <section className="space-y-4" aria-label={t("title")}>
      <div className="space-y-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900">
        <h3 className="font-medium text-gray-900 dark:text-white">
          {t("title")}
        </h3>
        <div className="flex flex-wrap items-end gap-3">
          <div className="w-32">
            <Label htmlFor={`${prefix}-count`}>{t("count")}</Label>
            <Input
              id={`${prefix}-count`}
              type="number"
              min={1}
              max={20}
              step={1}
              value={quantity}
              disabled={disabled}
              onChange={(e) => {
                setQuantity(e.target.value);
                onCountPending?.(Number(e.target.value) !== value.length);
              }}
            />
          </div>
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={disabled}
            onClick={applyCount}
          >
            {t("updateList")}
          </Button>
        </div>
        {Number(quantity) !== value.length && (
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">
            {t("applyCountHint")}
          </p>
        )}
        {trimCount !== null && (
          <div
            role="alert"
            className="space-y-2 text-theme-xs text-warning-700 dark:text-warning-300"
          >
            <p>{t("trimWarning", { count: value.length - trimCount })}</p>
            <div className="flex gap-2">
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={disabled}
                onClick={() => resize(trimCount)}
              >
                {t("confirmTrim")}
              </Button>
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={disabled}
                onClick={() => {
                  setTrimCount(null);
                  setQuantity(String(value.length));
                  onCountPending?.(false);
                }}
              >
                {t("keepList")}
              </Button>
            </div>
          </div>
        )}
        <label className="flex items-center gap-2 text-theme-sm text-gray-700 dark:text-gray-300">
          <input
            type="checkbox"
            checked={shared}
            disabled={disabled}
            onChange={(e) => setShared(e.target.checked)}
            className="size-4 accent-kps-primary"
          />
          {t("sameContent")}
        </label>
        {shared && (
          <div className="space-y-3 rounded-lg bg-kps-primary/5 p-3 dark:bg-kps-primary/10">
            <div>
              <Label htmlFor={`${prefix}-shared-service`}>{t("service")}</Label>
              <Input
                id={`${prefix}-shared-service`}
                maxLength={200}
                value={service}
                disabled={disabled}
                onChange={(e) => setService(e.target.value)}
              />
            </div>
            <div>
              <Label htmlFor={`${prefix}-shared-result`}>{t("result")}</Label>
              <TextArea
                id={`${prefix}-shared-result`}
                value={result}
                disabled={disabled}
                onChange={setResult}
              />
            </div>
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={disabled}
              onClick={() =>
                onChange(
                  value.map((e) => ({
                    ...e,
                    activities: [{ service, result }, ...e.activities.slice(1)],
                  })),
                )
              }
            >
              {t("copyCommon")}
            </Button>
          </div>
        )}
      </div>
      <div className="flex flex-wrap items-end gap-3">
        <div className="min-w-0 flex-1">
          <Label htmlFor={`${prefix}-search`}>{t("search")}</Label>
          <Input
            id={`${prefix}-search`}
            maxLength={200}
            value={search}
            placeholder={t("searchPlaceholder")}
            disabled={disabled}
            onChange={(e) => setSearch(e.target.value)}
          />
        </div>
        <Button
          type="button"
          size="sm"
          variant="outline"
          disabled={disabled}
          onClick={() => setQuery({ search, page: 1 })}
        >
          {t("searchButton")}
        </Button>
      </div>
      {loadError && (
        <p
          role="alert"
          className="text-theme-xs text-error-600 dark:text-error-400"
        >
          {t("loadError")}
        </p>
      )}
      {directory && directory.meta.last_page > 1 && (
        <div className="flex flex-wrap items-center gap-3 text-theme-xs text-gray-600 dark:text-gray-300">
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={disabled || query.page <= 1}
            onClick={() => setQuery({ ...query, page: query.page - 1 })}
          >
            {t("previous")}
          </Button>
          <span>
            {t("page", {
              page: directory.meta.current_page,
              total: directory.meta.last_page,
            })}
          </span>
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={disabled || query.page >= directory.meta.last_page}
            onClick={() => setQuery({ ...query, page: query.page + 1 })}
          >
            {t("next")}
          </Button>
        </div>
      )}
      {value.map((entry, index) => (
        <CustomerCareRow
          key={entry.client_key}
          taskId={task.id}
          entry={entry}
          index={index}
          customers={directory?.data ?? []}
          legacy={outputs.filter(
            (o) => o.unit === "khách hàng" && !o.care_customer_id,
          )}
          disabled={disabled}
          onBusy={onBusy}
          onChange={(next) =>
            onChange(
              value.map((e) => (e.client_key === next.client_key ? next : e)),
            )
          }
        />
      ))}
      <Button
        type="button"
        size="sm"
        variant="outline"
        disabled={disabled || value.length >= 20}
        onClick={() => resize(value.length + 1)}
      >
        {t("addCustomer")}
      </Button>
      <div
        aria-live="polite"
        className="space-y-1 rounded-xl bg-kps-primary/5 p-4 text-theme-sm text-gray-700 dark:bg-kps-primary/10 dark:text-gray-300"
      >
        <p className="font-medium">
          {t("summary", { count: unique.size, rows: value.length })}
        </p>
        <p>{duplicate ? t("duplicate") : t("added", { count: added })}</p>
        <p className="text-theme-xs text-gray-500 dark:text-gray-400">
          {t("uniqueRule")}
        </p>
      </div>
      {error && (
        <p
          role="alert"
          className="text-theme-sm text-error-600 dark:text-error-400"
        >
          {error}
        </p>
      )}
    </section>
  );
}
