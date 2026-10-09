import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import {
  AlertHexaIcon,
  CheckCircleIcon,
  TimeIcon,
  TrashBinIcon,
} from "@/icons";
import type {
  WorkItemField,
  WorkItemFormData,
  WorkItemValidationErrors,
  WorkStatus,
} from "@/types/reports";
import { cn } from "@/utils";
import { useTranslation } from "react-i18next";

interface WorkItemFormProps {
  index: number;
  item: WorkItemFormData;
  errors?: WorkItemValidationErrors;
  canDelete: boolean;
  onChange: (
    clientId: string,
    changes: Partial<Omit<WorkItemFormData, "clientId">>,
  ) => void;
  onBlur: (clientId: string, field: WorkItemField) => void;
  onDelete: (clientId: string) => void;
}

export default function WorkItemForm({
  index,
  item,
  errors,
  canDelete,
  onChange,
  onBlur,
  onDelete,
}: WorkItemFormProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "dailyReport.workItem",
  });
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
      <div className="flex items-center justify-between gap-4 px-5 pt-4 sm:px-6">
        <h2 className="font-semibold text-gray-900 dark:text-white">
          {t("title", { number: index + 1 })}
        </h2>
        {canDelete && (
          <button
            type="button"
            onClick={() => onDelete(item.clientId)}
            className="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-theme-sm font-semibold text-error-600 transition-colors hover:bg-error-50 focus-visible:ring-2 focus-visible:ring-error-500/30 focus-visible:outline-none dark:text-error-400 dark:hover:bg-error-500/10"
          >
            <TrashBinIcon className="size-4.5" />
            {t("delete")}
          </button>
        )}
      </div>

      <div className="space-y-5 p-5 sm:p-6">
        <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
          <div>
            <Label htmlFor={`content-${item.clientId}`}>
              {t("content.label")} <span className="text-error-500">*</span>
            </Label>
            <TextArea
              id={`content-${item.clientId}`}
              name={`content-${item.clientId}`}
              rows={3}
              value={item.content}
              placeholder={t("content.placeholder")}
              error={Boolean(errors?.content)}
              hint={errors?.content}
              onChange={(content) => onChange(item.clientId, { content })}
              onBlur={() => onBlur(item.clientId, "content")}
            />
          </div>

          <div>
            <Label htmlFor={`result-${item.clientId}`}>
              {t("result.label")} <span className="text-error-500">*</span>
            </Label>
            <TextArea
              id={`result-${item.clientId}`}
              name={`result-${item.clientId}`}
              rows={3}
              value={item.result}
              placeholder={t("result.placeholder")}
              error={Boolean(errors?.result)}
              hint={errors?.result}
              onChange={(result) => onChange(item.clientId, { result })}
              onBlur={() => onBlur(item.clientId, "result")}
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
                    "flex cursor-pointer items-center gap-3 rounded-xl border px-4 py-3 text-theme-sm font-medium transition-colors focus-within:ring-3 focus-within:ring-kps-primary/20",
                    isSelected
                      ? status.activeClassName
                      : "border-gray-200 bg-white text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 dark:hover:bg-white/5",
                  )}
                >
                  <input
                    type="radio"
                    name={`status-${item.clientId}`}
                    value={status.value}
                    checked={isSelected}
                    onChange={() =>
                      onChange(item.clientId, { status: status.value })
                    }
                    onBlur={() => onBlur(item.clientId, "status")}
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

        <div>
          <Label htmlFor={`note-${item.clientId}`}>{t("note.label")}</Label>
          <TextArea
            id={`note-${item.clientId}`}
            name={`note-${item.clientId}`}
            rows={2}
            value={item.note}
            placeholder={t("note.placeholder")}
            onChange={(note) => onChange(item.clientId, { note })}
          />
        </div>
      </div>
    </section>
  );
}
