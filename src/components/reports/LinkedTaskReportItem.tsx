import { careEvidenceEntries } from "@/utils/careReports";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import CustomerCareEvidence from "./CustomerCareEvidence";
import type {
  WorkItemFormData,
  WorkItemValidationErrors,
} from "@/types/reports";

export default function LinkedTaskReportItem({
  item,
  disabled,
  errors,
  onEdit,
  onDelete,
}: {
  item: WorkItemFormData;
  disabled?: boolean;
  errors?: WorkItemValidationErrors;
  onEdit?: (id: number) => void;
  onDelete: (clientId: string) => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "taskProgress" });
  const link = item.taskLink!;
  const messages = Object.values(errors ?? {}).filter(Boolean);
  return (
    <article
      id={`linked-task-${link.task_id}`}
      className="scroll-mt-24 space-y-3 rounded-xl border border-s-4 border-gray-200 border-s-kps-primary bg-white p-4 dark:border-gray-700 dark:border-s-kps-primary dark:bg-gray-900"
    >
      <div className="flex flex-wrap justify-between gap-2">
        <span className="text-theme-xs font-medium text-kps-primary dark:text-sidebar-selected">
          {t("linked", { id: link.task_id })}
        </span>
        <span className="text-theme-xs text-gray-500 dark:text-gray-400">
          {t(link.pending ? "notSaved" : "saved")}
        </span>
      </div>
      <p className="font-medium text-gray-900 dark:text-white">
        {item.content}
      </p>
      <p className="text-theme-sm whitespace-pre-wrap text-gray-700 dark:text-gray-300">
        {item.result || t("emptyResult")}
      </p>
      {item.issue && (
        <p className="text-theme-sm text-error-600 dark:text-error-400">
          {item.issue}
        </p>
      )}
      {item.evidenceLinks && (
        <p className="text-theme-xs break-all whitespace-pre-wrap text-gray-500 dark:text-gray-400">
          {item.evidenceLinks}
        </p>
      )}
      {link.progress.input.outputs.length > 0 && (
        <p className="text-theme-xs text-gray-600 dark:text-gray-300">
          {t(
            link.progress.input.unit === "khách hàng"
              ? "customers"
              : "products",
          )}
          :{" "}
          {link.progress.input.outputs
            .map((o) => (o.reference ? `${o.name} (${o.reference})` : o.name))
            .join(", ")}
        </p>
      )}
      {messages.length > 0 && (
        <p
          role="alert"
          data-report-error-summary
          tabIndex={-1}
          className="text-theme-sm text-error-600 dark:text-error-400"
        >
          {messages.join(" ")}
        </p>
      )}
      {(link.progress.input.care_entries ||
        link.progress.input.care_work_items) && (
        <CustomerCareEvidence
          taskId={link.task_id}
          entries={careEvidenceEntries(link.progress.input)}
        />
      )}
      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          size="sm"
          variant="outline"
          disabled={disabled}
          onClick={() => onEdit?.(link.task_id)}
        >
          {t("editAbove")}
        </Button>
        <Button
          type="button"
          size="sm"
          variant="outline"
          disabled={disabled}
          onClick={() => onDelete(item.clientId)}
        >
          {t("remove")}
        </Button>
      </div>
    </article>
  );
}
