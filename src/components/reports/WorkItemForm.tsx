import Select from "@/components/form/Select";
import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import {
  AlertHexaIcon,
  CheckCircleIcon,
  ChevronDownIcon,
  TimeIcon,
  TrashBinIcon,
} from "@/icons";
import type {
  WorkItemFormData,
  WorkItemField,
  WorkItemValidationErrors,
  WorkStatus,
} from "@/types/reports";
import { cn } from "@/utils";
import { useState } from "react";
import { useTranslation } from "react-i18next";

interface SelectOption {
  value: string;
  label: string;
}

interface WorkItemFormProps {
  index: number;
  item: WorkItemFormData;
  errors?: WorkItemValidationErrors;
  customerOptions: SelectOption[];
  productOptions: SelectOption[];
  canDelete: boolean;
  onChange: (
    id: string,
    changes: Partial<Omit<WorkItemFormData, "id">>,
  ) => void;
  onBlur: (id: string, field: WorkItemField) => void;
  onDelete: (id: string) => void;
}

export default function WorkItemForm({
  index,
  item,
  errors,
  customerOptions,
  productOptions,
  canDelete,
  onChange,
  onBlur,
  onDelete,
}: WorkItemFormProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "dailyReport.workItem",
  });
  const [isRelatedInfoOpen, setIsRelatedInfoOpen] = useState(false);
  const relatedInfoId = `related-info-${item.id}`;

  const statusOptions: Array<{
    value: WorkStatus;
    label: string;
    icon: React.ReactNode;
    activeClassName: string;
  }> = [
    {
      value: "COMPLETED",
      label: t("statuses.completed"),
      icon: <CheckCircleIcon className="size-5" />,
      activeClassName:
        "border-success-500 bg-success-50 text-success-700 dark:border-success-500 dark:bg-success-500/15 dark:text-success-400",
    },
    {
      value: "IN_PROGRESS",
      label: t("statuses.inProgress"),
      icon: <TimeIcon className="size-5" />,
      activeClassName:
        "border-warning-500 bg-warning-50 text-warning-700 dark:border-warning-500 dark:bg-warning-500/15 dark:text-warning-400",
    },
    {
      value: "BLOCKED",
      label: t("statuses.blocked"),
      icon: <AlertHexaIcon className="size-5" />,
      activeClassName:
        "border-error-500 bg-error-50 text-error-700 dark:border-error-500 dark:bg-error-500/15 dark:text-error-400",
    },
  ];

  return (
    <section className="rounded-2xl border border-gray-200 bg-white shadow-theme-xs dark:border-gray-800 dark:bg-white/3">
      <div className="flex items-center justify-between gap-4 border-b border-gray-100 px-5 py-4 sm:px-6 dark:border-gray-800">
        <h2 className="font-semibold text-gray-900 dark:text-white">
          {t("title", { number: index + 1 })}
        </h2>
        {canDelete && (
          <button
            type="button"
            onClick={() => onDelete(item.id)}
            className="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-theme-sm font-medium text-error-600 transition-colors hover:bg-error-50 dark:text-error-400 dark:hover:bg-error-500/10"
          >
            <TrashBinIcon className="size-4.5" />
            {t("delete")}
          </button>
        )}
      </div>

      <div className="space-y-5 p-5 sm:p-6">
        <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
          <div>
            <Label htmlFor={`content-${item.id}`}>
              {t("content.label")} <span className="text-error-500">*</span>
            </Label>
            <TextArea
              id={`content-${item.id}`}
              name={`content-${item.id}`}
              rows={3}
              value={item.content}
              placeholder={t("content.placeholder")}
              error={Boolean(errors?.content)}
              hint={errors?.content}
              onChange={(content) => onChange(item.id, { content })}
              onBlur={() => onBlur(item.id, "content")}
            />
          </div>

          <div>
            <Label htmlFor={`result-${item.id}`}>
              {t("result.label")} <span className="text-error-500">*</span>
            </Label>
            <TextArea
              id={`result-${item.id}`}
              name={`result-${item.id}`}
              rows={3}
              value={item.result}
              placeholder={t("result.placeholder")}
              error={Boolean(errors?.result)}
              hint={errors?.result}
              onChange={(result) => onChange(item.id, { result })}
              onBlur={() => onBlur(item.id, "result")}
            />
          </div>
        </div>

        <fieldset>
          <legend className="mb-2 text-sm font-medium text-gray-700 dark:text-gray-400">
            {t("status.label")} <span className="text-error-500">*</span>
          </legend>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            {statusOptions.map((status) => {
              const isSelected = item.status === status.value;

              return (
                <label
                  key={status.value}
                  className={cn(
                    "flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 text-theme-sm font-medium transition-colors focus-within:ring-3 focus-within:ring-gray-400/20",
                    isSelected
                      ? status.activeClassName
                      : "border-gray-200 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-white/5",
                  )}
                >
                  <input
                    type="radio"
                    name={`status-${item.id}`}
                    value={status.value}
                    checked={isSelected}
                    onChange={() => onChange(item.id, { status: status.value })}
                    onBlur={() => onBlur(item.id, "status")}
                    className="sr-only"
                  />
                  {status.icon}
                  <span>{status.label}</span>
                </label>
              );
            })}
          </div>
          {errors?.status && (
            <p className="mt-2 text-sm text-error-500">{errors.status}</p>
          )}
        </fieldset>

        <div className="border-t border-gray-100 pt-4 dark:border-gray-800">
          <button
            type="button"
            aria-expanded={isRelatedInfoOpen}
            aria-controls={relatedInfoId}
            onClick={() => setIsRelatedInfoOpen((isOpen) => !isOpen)}
            className="flex w-full items-center justify-between gap-4 rounded-lg py-2 text-start text-theme-sm font-semibold text-gray-800 transition-colors hover:text-gray-950 focus:ring-3 focus:ring-brand-500/10 focus:outline-hidden dark:text-white/90 dark:hover:text-white"
          >
            <span>{t("related.title")}</span>
            <ChevronDownIcon
              className={cn(
                "size-5 shrink-0 text-gray-500 transition-transform duration-200 dark:text-gray-400",
                isRelatedInfoOpen && "rotate-180",
              )}
            />
          </button>

          {isRelatedInfoOpen && (
            <div
              id={relatedInfoId}
              className="mt-4 grid grid-cols-1 gap-5 md:grid-cols-2"
            >
              <div>
                <Label htmlFor={`customer-${item.id}`}>
                  {t("related.customer")}
                </Label>
                <Select
                  id={`customer-${item.id}`}
                  name={`customer-${item.id}`}
                  options={customerOptions}
                  value={item.customerId ?? ""}
                  placeholder={t("related.customerPlaceholder")}
                  allowClear
                  onChange={(customerId) =>
                    onChange(item.id, { customerId: customerId || undefined })
                  }
                />
              </div>

              <div>
                <Label htmlFor={`product-${item.id}`}>
                  {t("related.product")}
                </Label>
                <Select
                  id={`product-${item.id}`}
                  name={`product-${item.id}`}
                  options={productOptions}
                  value={item.productId ?? ""}
                  placeholder={t("related.productPlaceholder")}
                  allowClear
                  onChange={(productId) =>
                    onChange(item.id, { productId: productId || undefined })
                  }
                />
              </div>

              <div className="md:col-span-2">
                <Label htmlFor={`note-${item.id}`}>{t("related.note")}</Label>
                <TextArea
                  id={`note-${item.id}`}
                  name={`note-${item.id}`}
                  rows={2}
                  value={item.note ?? ""}
                  placeholder={t("related.notePlaceholder")}
                  onChange={(note) =>
                    onChange(item.id, { note: note || undefined })
                  }
                />
              </div>
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
