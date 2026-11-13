import { Fragment, type ReactNode } from "react";
import ReportIssueSummary from "./ReportIssueSummary";
import Button from "@/components/ui/button/Button";
import { PlusIcon } from "@/icons";
import type {
  FacebookReportPage,
  WorkStatus,
  WorkItemField,
  WorkItemFormData,
  WorkItemValidationErrors,
} from "@/types/reports";
import { useTranslation } from "react-i18next";
import WorkItemForm from "./WorkItemForm";
import LinkedTaskReportItem from "./LinkedTaskReportItem";

interface WorkItemListProps {
  onEditTask?: (id: number) => void;
  reportDate?: string;
  facebookPages?: FacebookReportPage[];
  disabled?: boolean;
  planSection?: ReactNode;
  items: WorkItemFormData[];
  errors: Record<string, WorkItemValidationErrors>;
  onItemChange: (
    clientId: string,
    changes: Partial<Omit<WorkItemFormData, "clientId">>,
  ) => void;
  onItemBlur: (clientId: string, field: WorkItemField) => void;
  onItemAdd: (status: WorkStatus) => void;
  onItemDelete: (clientId: string) => void;
}

export default function WorkItemList({
  items,
  facebookPages,
  reportDate,
  disabled,
  planSection,
  errors,
  onItemChange,
  onItemBlur,
  onItemAdd,
  onItemDelete,
  onEditTask,
}: WorkItemListProps) {
  const { t } = useTranslation("common", { keyPrefix: "dailyReport" });
  const hasReachedLimit = items.length >= 50;

  return (
    <div className="space-y-5">
      {items.length === 0 && (
        <div className="rounded-2xl border border-dashed border-gray-300 bg-white px-5 py-8 text-center text-theme-sm text-gray-500 dark:border-gray-700 dark:bg-white/3 dark:text-gray-400">
          {t("detailedEmpty")}
        </div>
      )}
      {(["COMPLETED", "IN_PROGRESS", "BLOCKED"] as const).map((status) => (
        <Fragment key={status}>
          {status === "BLOCKED" && planSection}
          <section className="space-y-4 rounded-2xl border border-gray-200 p-4 dark:border-gray-800">
            <h2 className="font-semibold text-gray-900 dark:text-white">
              {t(
                `quick.groups.${status === "COMPLETED" ? "completed" : status === "IN_PROGRESS" ? "inProgress" : "blocked"}.title`,
              )}
            </h2>
            {items
              .filter(
                (item) =>
                  item.status === status ||
                  (!item.status && status === "COMPLETED"),
              )
              .map((item) =>
                item.taskLink ? (
                  <LinkedTaskReportItem
                    key={item.clientId}
                    item={item}
                    disabled={disabled}
                    errors={errors[item.clientId]}
                    onEdit={onEditTask}
                    onDelete={onItemDelete}
                  />
                ) : (
                  <WorkItemForm
                    facebookPages={facebookPages}
                    reportDate={reportDate}
                    disabled={disabled}
                    key={item.clientId}
                    index={items.indexOf(item)}
                    item={item}
                    errors={errors[item.clientId]}
                    canDelete
                    onChange={onItemChange}
                    onBlur={onItemBlur}
                    onDelete={onItemDelete}
                  />
                ),
              )}
            <Button
              type="button"
              variant="outline"
              size="sm"
              startIcon={<PlusIcon className="size-5" />}
              disabled={hasReachedLimit}
              onClick={() => onItemAdd(status)}
            >
              {t("addWorkItem")}
            </Button>
            {status === "BLOCKED" && <ReportIssueSummary items={items} />}
          </section>
        </Fragment>
      ))}
      {hasReachedLimit && (
        <p className="text-theme-xs text-gray-500 dark:text-gray-400">
          {t("workItemLimit")}
        </p>
      )}
    </div>
  );
}
