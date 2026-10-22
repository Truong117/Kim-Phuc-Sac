import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import Button from "@/components/ui/button/Button";
import {
  AlertHexaIcon,
  CheckCircleIcon,
  PlusIcon,
  TimeIcon,
  TrashBinIcon,
} from "@/icons";
import type {
  WorkItemFormData,
  WorkItemValidationErrors,
  WorkStatus,
} from "@/types/reports";
import {
  groupWorkItemsByStatus,
  parseQuickReportText,
  type QuickReportDrafts,
} from "@/utils/quickReport";
import { cn } from "@/utils";
import { useTranslation } from "react-i18next";

interface QuickReportEditorProps {
  items: WorkItemFormData[];
  drafts: QuickReportDrafts;
  errors: Record<string, WorkItemValidationErrors>;
  batchError: string | null;
  disabled: boolean;
  onDraftChange: (status: WorkStatus, value: string) => void;
  onAddBatch: (status: WorkStatus) => void;
  onItemChange: (
    clientId: string,
    changes: Partial<Omit<WorkItemFormData, "clientId">>,
  ) => void;
  onItemBlur: (clientId: string, field: "content") => void;
  onItemDelete: (clientId: string) => void;
}

export default function QuickReportEditor({
  items,
  drafts,
  errors,
  batchError,
  disabled,
  onDraftChange,
  onAddBatch,
  onItemChange,
  onItemBlur,
  onItemDelete,
}: QuickReportEditorProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "dailyReport.quick",
  });
  const groups: Array<{
    status: WorkStatus;
    icon: React.ReactNode;
    accentClassName: string;
    iconClassName: string;
  }> = [
    {
      status: "COMPLETED",
      icon: <CheckCircleIcon className="size-5" aria-hidden="true" />,
      accentClassName:
        "border-success-200 bg-success-50/50 dark:border-success-800 dark:bg-success-500/5",
      iconClassName:
        "bg-success-100 text-success-700 dark:bg-success-500/15 dark:text-success-400",
    },
    {
      status: "IN_PROGRESS",
      icon: <TimeIcon className="size-5" aria-hidden="true" />,
      accentClassName:
        "border-warning-200 bg-warning-50/50 dark:border-warning-800 dark:bg-warning-500/5",
      iconClassName:
        "bg-warning-100 text-warning-700 dark:bg-warning-500/15 dark:text-warning-400",
    },
    {
      status: "BLOCKED",
      icon: <AlertHexaIcon className="size-5" aria-hidden="true" />,
      accentClassName:
        "border-error-200 bg-error-50/40 dark:border-error-800 dark:bg-error-500/5",
      iconClassName:
        "bg-error-100 text-error-700 dark:bg-error-500/15 dark:text-error-400",
    },
  ];
  const itemsByStatus = groupWorkItemsByStatus(items);

  return (
    <div className="space-y-5">
      <section className="rounded-xl border border-kps-primary/20 bg-kps-primary/5 px-4 py-3 dark:border-kps-primary/30 dark:bg-kps-primary/10">
        <p className="text-theme-sm font-medium text-gray-800 dark:text-white/90">
          {t("instructions")}
        </p>
        <p className="mt-1 text-theme-xs text-gray-600 dark:text-gray-300">
          {t("arrowHint")}
        </p>
      </section>

      {batchError && (
        <div
          role="alert"
          className="rounded-xl border border-error-200 bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:border-error-800 dark:bg-error-500/10 dark:text-error-300"
        >
          {batchError}
        </div>
      )}

      {groups.map((group) => {
        const groupKey =
          group.status === "COMPLETED"
            ? "completed"
            : group.status === "IN_PROGRESS"
              ? "inProgress"
              : "blocked";
        const groupItems = itemsByStatus[group.status];
        const pendingCount = parseQuickReportText(drafts[group.status]).length;
        const draftId = `quick-draft-${group.status.toLowerCase()}`;

        return (
          <section
            key={group.status}
            className={cn(
              "rounded-2xl border p-4 shadow-theme-xs sm:p-5",
              group.accentClassName,
            )}
          >
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="flex items-center gap-3">
                <span
                  className={cn(
                    "flex size-9 items-center justify-center rounded-lg",
                    group.iconClassName,
                  )}
                >
                  {group.icon}
                </span>
                <div>
                  <h2 className="font-semibold text-gray-900 dark:text-white">
                    {t(`groups.${groupKey}.title`)}
                  </h2>
                  <p className="text-theme-xs text-gray-500 dark:text-gray-400">
                    {t("count", { count: groupItems.length })}
                  </p>
                </div>
              </div>
            </div>

            <div className="mt-4">
              <Label htmlFor={draftId}>{t("inputLabel")}</Label>
              <TextArea
                id={draftId}
                name={draftId}
                rows={4}
                value={drafts[group.status]}
                disabled={disabled}
                placeholder={t(`groups.${groupKey}.placeholder`)}
                onChange={(value) => onDraftChange(group.status, value)}
              />
              <div className="mt-3 flex flex-wrap items-center justify-between gap-3">
                <p className="text-theme-xs text-gray-500 dark:text-gray-400">
                  {t("pendingCount", { count: pendingCount })}
                </p>
                <Button
                  type="button"
                  size="sm"
                  startIcon={<PlusIcon className="size-4.5" />}
                  className="!bg-kps-primary hover:!bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none"
                  disabled={disabled || !drafts[group.status].trim()}
                  onClick={() => onAddBatch(group.status)}
                >
                  {t("add")}
                </Button>
              </div>
            </div>

            {groupItems.length > 0 ? (
              <div className="mt-5 custom-scrollbar max-h-96 space-y-3 overflow-y-auto pe-1">
                {groupItems.map((item, index) => (
                  <article
                    key={item.clientId}
                    className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
                  >
                    <div className="flex items-start justify-between gap-3">
                      <p className="text-theme-xs font-semibold text-gray-500 dark:text-gray-400">
                        {t("itemTitle", { number: index + 1 })}
                      </p>
                      <button
                        type="button"
                        disabled={disabled}
                        onClick={() => onItemDelete(item.clientId)}
                        aria-label={t("deleteItem", { number: index + 1 })}
                        className="inline-flex size-8 shrink-0 items-center justify-center rounded-lg text-error-600 transition-colors hover:bg-error-50 focus-visible:ring-2 focus-visible:ring-error-500/30 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 dark:text-error-400 dark:hover:bg-error-500/10"
                      >
                        <TrashBinIcon className="size-4" aria-hidden="true" />
                      </button>
                    </div>
                    <div className="mt-2 grid grid-cols-1 gap-3 lg:grid-cols-2">
                      <div>
                        <Label htmlFor={`quick-content-${item.clientId}`}>
                          {t("contentLabel")}
                        </Label>
                        <TextArea
                          id={`quick-content-${item.clientId}`}
                          rows={2}
                          value={item.content}
                          disabled={disabled}
                          error={Boolean(errors[item.clientId]?.content)}
                          hint={errors[item.clientId]?.content}
                          onChange={(content) =>
                            onItemChange(item.clientId, { content })
                          }
                          onBlur={() => onItemBlur(item.clientId, "content")}
                        />
                      </div>
                      <div>
                        <Label htmlFor={`quick-result-${item.clientId}`}>
                          {t("resultLabel")}
                        </Label>
                        <TextArea
                          id={`quick-result-${item.clientId}`}
                          rows={2}
                          value={item.result}
                          disabled={disabled}
                          placeholder={t("resultPlaceholder")}
                          onChange={(result) =>
                            onItemChange(item.clientId, { result })
                          }
                        />
                      </div>
                    </div>
                    {item.note.trim() && (
                      <p className="mt-3 text-theme-xs text-gray-500 dark:text-gray-400">
                        {t("notePreserved")}
                      </p>
                    )}
                  </article>
                ))}
              </div>
            ) : (
              <p className="mt-5 rounded-xl border border-dashed border-gray-300 bg-white/60 px-4 py-4 text-center text-theme-xs text-gray-500 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-400">
                {t("emptyGroup")}
              </p>
            )}
          </section>
        );
      })}
    </div>
  );
}
