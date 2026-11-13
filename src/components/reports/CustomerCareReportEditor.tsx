import { useState } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import CareServicePicker from "./CareServicePicker";
import CareWorkCustomers from "./CareWorkCustomers";
import CareWorkEvidenceEditor from "./CareWorkEvidenceEditor";
import { blankCareWork, careSelections } from "@/utils/careReports";
import type { CareWorkItem, ManagedTask } from "@/types/tasks";
import { PlusIcon, TrashBinIcon } from "@/icons";

export default function CustomerCareReportEditor({
  task,
  value,
  disabled,
  onChange,
  onBusy,
  errorWorkKey,
}: {
  task: ManagedTask;
  value: CareWorkItem[];
  disabled: boolean;
  onChange: (works: CareWorkItem[]) => void;
  onBusy: (busy: boolean) => void;
  errorWorkKey?: string;
}) {
  const { t } = useTranslation("common", { keyPrefix: "customerCare.works" });
  const [removeKey, setRemoveKey] = useState<string | null>(null);
  const [collapsed, setCollapsed] = useState<Set<string>>(() => new Set());
  const selections = careSelections(value),
    outputs = task.progress?.outputs ?? [];
  const added = selections.filter(
    (c) =>
      !outputs.some(
        (o) =>
          o.completed &&
          (c.legacy_output_id
            ? o.id === c.legacy_output_id
            : c.customer_id && o.care_customer_id === c.customer_id),
      ),
  ).length;
  const patch = (work: CareWorkItem) =>
    onChange(value.map((w) => (w.client_key === work.client_key ? work : w)));
  function remove(w: CareWorkItem) {
    setCollapsed((previous) => {
      const next = new Set(previous);
      next.delete(w.client_key);
      return next;
    });
    if (
      w.services.length ||
      w.customers.length ||
      w.result.trim() ||
      w.evidence_links.some(Boolean) ||
      w.attachment_ids.length
    ) {
      setRemoveKey(w.client_key);
      return;
    }
    onChange(value.filter((v) => v.client_key !== w.client_key));
  }
  return (
    <section className="space-y-4" aria-label={t("title")}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h3 className="font-medium text-gray-900 dark:text-white">
          {t("title")}
        </h3>
        <span className="text-theme-xs text-gray-500 dark:text-gray-400">
          {t("workCount", { count: value.length })}
        </span>
      </div>
      {value.map((work, index) => {
        const invalid = errorWorkKey === work.client_key;
        const open = invalid || !collapsed.has(work.client_key);
        const bodyId = `care-work-body-${work.client_key}`;
        const toggle = () =>
          setCollapsed((previous) => {
            const next = new Set(previous);
            if (open) next.add(work.client_key);
            else next.delete(work.client_key);
            return next;
          });
        return (
          <article
            key={work.client_key}
            aria-label={t("work", { number: index + 1 })}
            tabIndex={invalid ? -1 : undefined}
            aria-invalid={invalid || undefined}
            className={`overflow-hidden rounded-xl border bg-white dark:bg-gray-900 ${invalid ? "border-error-500 dark:border-error-400" : "border-gray-200 dark:border-gray-700"}`}
          >
            <header className="flex flex-wrap items-center gap-3 bg-brand-50 px-4 py-3 dark:bg-kps-primary/15">
              <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-kps-primary text-theme-xs text-white dark:bg-kps-primary dark:text-white">
                {index + 1}
              </span>
              <div className="min-w-0 flex-1">
                <button
                  type="button"
                  disabled={disabled}
                  onClick={toggle}
                  aria-expanded={open}
                  aria-controls={bodyId}
                  className="min-h-6 text-start font-medium text-gray-900 disabled:opacity-50 dark:text-white"
                >
                  {t("work", { number: index + 1 })}
                </button>
                <p className="text-theme-xs text-gray-500 dark:text-gray-400">
                  {t("workSummary", {
                    services: work.services.length,
                    customers: work.customers.length,
                  })}
                </p>
              </div>
              <button
                type="button"
                disabled={disabled}
                onClick={toggle}
                aria-expanded={open}
                aria-controls={bodyId}
                className="min-h-11 text-theme-xs text-kps-primary disabled:opacity-50 dark:text-brand-200"
              >
                {t(open ? "collapse" : "expand")}
              </button>
              <button
                type="button"
                disabled={disabled}
                aria-label={t("removeWorkNumber", { number: index + 1 })}
                className="flex size-11 shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-error-50 hover:text-error-600 disabled:opacity-50 dark:text-gray-400 dark:hover:bg-error-500/10 dark:hover:text-error-400"
                onClick={() => remove(work)}
              >
                <TrashBinIcon className="size-4" aria-hidden="true" />
              </button>
            </header>
            <div id={bodyId} hidden={!open} className="space-y-4 p-4">
              {removeKey === work.client_key && (
                <div
                  role="alert"
                  className="space-y-2 rounded-lg bg-warning-50 p-3 text-theme-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-300"
                >
                  <p>{t("removeWarning")}</p>
                  <div className="flex flex-wrap gap-2">
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      disabled={disabled}
                      onClick={() => {
                        onChange(
                          value.filter((w) => w.client_key !== work.client_key),
                        );
                        setRemoveKey(null);
                      }}
                    >
                      {t("confirmRemove")}
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      disabled={disabled}
                      onClick={() => setRemoveKey(null)}
                    >
                      {t("keepWork")}
                    </Button>
                  </div>
                </div>
              )}
              <div className="grid items-start gap-5 md:grid-cols-2">
                <CareServicePicker
                  active={open}
                  taskId={task.id}
                  workKey={work.client_key}
                  value={work.services}
                  known={value.flatMap((w) => w.services)}
                  disabled={disabled}
                  onChange={(services) => patch({ ...work, services })}
                />
                <CareWorkCustomers
                  taskId={task.id}
                  workKey={work.client_key}
                  value={work.customers}
                  drafts={selections}
                  legacy={outputs.filter(
                    (o) => o.unit === "khách hàng" && !o.care_customer_id,
                  )}
                  disabled={disabled}
                  onBusy={onBusy}
                  onChange={(customers) => patch({ ...work, customers })}
                />
              </div>
              <div>
                <Label htmlFor={`care-work-result-${work.client_key}`}>
                  {t("result")}
                </Label>
                <TextArea
                  id={`care-work-result-${work.client_key}`}
                  rows={2}
                  maxLength={1000}
                  placeholder={t("resultPlaceholder")}
                  error={invalid && !work.result.trim()}
                  value={work.result}
                  disabled={disabled}
                  onChange={(result) => patch({ ...work, result })}
                />
              </div>
              <CareWorkEvidenceEditor
                taskId={task.id}
                value={work}
                disabled={disabled}
                onChange={patch}
                onBusy={onBusy}
              />
            </div>
          </article>
        );
      })}
      <Button
        type="button"
        size="sm"
        variant="outline"
        startIcon={<PlusIcon className="size-4" aria-hidden="true" />}
        disabled={disabled || value.length >= 20}
        onClick={() => onChange([...value, blankCareWork()])}
      >
        {t("addWork")}
      </Button>
      <div
        aria-live="polite"
        className="space-y-2 border-t border-gray-200 pt-4 text-theme-sm text-gray-700 dark:border-gray-700 dark:text-gray-300"
      >
        <div className="flex flex-wrap justify-between gap-2">
          <span className="font-medium">
            {t("uniqueCustomers", { count: selections.length })}
          </span>
          <span className="text-success-700 dark:text-success-400">
            {t("addedCustomers", { count: added })}
          </span>
        </div>
        <p className="text-theme-xs text-gray-500 dark:text-gray-400">
          {t("uniqueRule")}
        </p>
      </div>
    </section>
  );
}
