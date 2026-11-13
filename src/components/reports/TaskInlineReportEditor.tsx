import { useRef, useState } from "react";
import { focusFirstReportError } from "@/utils/reportValidation";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import Label from "@/components/form/Label";
import TaskOutputEditor from "@/components/tasks/TaskOutputEditor";
import TextArea from "@/components/form/input/TextArea";
import Select from "@/components/form/Select";
import WorkItemExtras from "./WorkItemExtras";
import CustomerCareReportEditor from "./CustomerCareReportEditor";
import {
  blankCareWork,
  careWorksFromLegacy,
  careWorkOutputs,
  careWorkText,
  careWorkError,
} from "@/utils/careReports";
import {
  hasValidEvidenceLinks,
  parseEvidenceLinks,
} from "@/utils/reportEvidence";
import {
  namedVideoSuggestions,
  outputIdentity,
  taskOutputIdentity,
  needsOutputReference,
} from "@/utils/taskProgress";
import type {
  ManagedTask,
  TaskQuantityUnit,
  TaskProgressInput,
} from "@/types/tasks";
import type {
  FacebookReportPage,
  WorkItemFormData,
  WorkStatus,
} from "@/types/reports";

export default function TaskInlineReportEditor({
  task,
  value,
  canSync,
  disabled: externallyDisabled,
  reportDate,
  facebookPages,
  onChange,
  onApply,
  onCancel,
}: {
  task: ManagedTask;
  value: WorkItemFormData;
  canSync: boolean;
  disabled: boolean;
  reportDate: string;
  facebookPages: FacebookReportPage[];
  onChange: (item: WorkItemFormData) => void;
  onApply: (item: WorkItemFormData) => void;
  onCancel: () => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "taskProgress" });
  const editorRef = useRef<HTMLDivElement>(null);
  const [error, setErrorMessage] = useState("");
  const setError = (message: string) => {
    setErrorMessage(message);
    if (message) focusFirstReportError(editorRef.current);
  };
  const [notice, setNotice] = useState("");
  const [uploading, setUploading] = useState(false);
  const disabled = externallyDisabled || uploading;
  const input = value.taskLink!.progress.input;
  const customerForm =
    input.unit === "khách hàng" &&
    (input.care_work_items !== undefined ||
      input.care_entries !== undefined ||
      input.outputs.length === 0);
  const careWorks =
    input.care_work_items ?? careWorksFromLegacy(input.care_entries ?? []);
  const change = (changes: Partial<WorkItemFormData>) => {
    setError("");
    setNotice("");
    onChange({ ...value, ...changes });
  };
  const progress = (patch: Partial<TaskProgressInput>) =>
    change({
      taskLink: {
        ...value.taskLink!,
        progress: {
          ...value.taskLink!.progress,
          input: { ...input, ...patch },
        },
      },
    });
  function suggest() {
    const names = namedVideoSuggestions(`${value.content}\n${value.result}`);
    const outputs = [...input.outputs];
    for (const name of names) {
      if (outputs.some((o) => outputIdentity(o.name) === outputIdentity(name)))
        continue;
      const known = task.progress?.outputs.find(
        (o) =>
          o.unit === "video" && outputIdentity(o.name) === outputIdentity(name),
      );
      outputs.push({
        ...(known?.id ? { id: known.id } : {}),
        name,
        completed: true,
      });
    }
    if (outputs.length > 50) {
      setError(t("outputLimit"));
      return;
    }
    progress({ unit: "video", outputs });
    setNotice(
      t(names.length ? "suggested" : "noSuggestion", { count: names.length }),
    );
  }
  function apply() {
    if (disabled) return;
    const result = customerForm ? careWorkText(careWorks) : value.result;
    const nextInput = customerForm
      ? {
          ...input,
          care_entries: undefined,
          care_work_items: careWorks,
          outputs: careWorkOutputs(careWorks, task),
        }
      : input;
    if (customerForm) {
      const error = careWorkError(careWorks);
      if (error) {
        setError(t(`careWorkError.${error}`));
        return;
      }
    }
    if (
      !value.content.trim() ||
      !value.status ||
      (value.status === "COMPLETED" &&
        (!result.trim() ||
          !hasValidEvidenceLinks(value.evidenceLinks ?? ""))) ||
      ((value.status === "BLOCKED" || input.status === "BLOCKED") &&
        !value.issue?.trim())
    ) {
      setError(t("required"));
      return;
    }
    const names = input.outputs.map((o) => taskOutputIdentity(o, input.unit));
    if (
      input.outputs.length > 50 ||
      names.some((n) => !n) ||
      input.outputs.some(
        (o) =>
          !o.name.trim() ||
          o.name.length > 200 ||
          (needsOutputReference(input.unit) &&
            (!o.reference?.trim() || o.reference.length > 200)),
      ) ||
      new Set(names).size !== names.length ||
      (input.outputs.length > 0 && !input.unit)
    ) {
      setError(t("invalidOutputs"));
      return;
    }
    if (
      value.content.length > 5000 ||
      result.length > 5000 ||
      parseEvidenceLinks(value.evidenceLinks ?? "").length > 10
    ) {
      setError(t("tooLong"));
      return;
    }
    onApply({
      ...value,
      result,
      taskLink: {
        ...value.taskLink!,
        pending: true,
        progress: {
          ...value.taskLink!.progress,
          source_result: result,
          input: {
            ...nextInput,
            expected_revision: task.progress!.revision,
            status: canSync ? input.status : "KEEP",
          },
        },
      },
    });
  }
  return (
    <div
      ref={editorRef}
      className="mt-4 space-y-4 rounded-xl bg-kps-primary/5 p-4 dark:bg-kps-primary/10"
    >
      <details className="text-theme-xs text-gray-600 dark:text-gray-300">
        <summary className="cursor-pointer font-medium text-kps-primary dark:text-sidebar-selected">
          {t("requirements")}
        </summary>
        <p className="mt-2 whitespace-pre-wrap">
          {task.description || t("noDescription")}
        </p>
        <p className="mt-1">{t("taskDue", { date: task.due_date })}</p>
      </details>
      {error && (
        <p
          role="alert"
          tabIndex={-1}
          data-report-error-summary
          className="text-theme-sm text-error-600 dark:text-error-400"
        >
          {error}
        </p>
      )}
      {notice && (
        <p
          role="status"
          className="text-theme-xs text-gray-600 dark:text-gray-300"
        >
          {notice}
        </p>
      )}
      {!customerForm && (
        <>
          <div>
            <Label htmlFor={`task-report-title-${task.id}`}>
              {t("todayWork")}
            </Label>
            <TextArea
              id={`task-report-title-${task.id}`}
              rows={2}
              value={value.content}
              disabled={disabled}
              onChange={(content) => change({ content })}
            />
          </div>
          <div>
            <Label htmlFor={`task-report-result-${task.id}`}>
              {t("todayResult")}
            </Label>
            <TextArea
              id={`task-report-result-${task.id}`}
              rows={3}
              value={value.result}
              disabled={disabled}
              onChange={(result) => change({ result })}
            />
          </div>
        </>
      )}
      <div className="grid gap-4 sm:grid-cols-2">
        <div>
          <Label htmlFor={`task-report-status-${task.id}`}>
            {t("reportGroup")}
          </Label>
          <Select
            id={`task-report-status-${task.id}`}
            value={value.status}
            disabled={disabled}
            options={(
              ["COMPLETED", "IN_PROGRESS", "BLOCKED"] as WorkStatus[]
            ).map((status) => ({
              value: status,
              label: t(`dailyStatus.${status}`),
            }))}
            onChange={(status) => change({ status: status as WorkStatus })}
          />
        </div>
        <div>
          <Label htmlFor={`task-report-whole-${task.id}`}>
            {t("wholeStatus")}
          </Label>
          <Select
            id={`task-report-whole-${task.id}`}
            value={input.status}
            disabled={disabled || !canSync}
            options={[
              { value: "KEEP", label: t("keepStatus") },
              ...(["IN_PROGRESS", "BLOCKED", "COMPLETED"] as const).map(
                (status) => ({
                  value: status,
                  label: t(`wholeState.${status}`),
                }),
              ),
            ]}
            onChange={(status) =>
              progress({ status: status as TaskProgressInput["status"] })
            }
          />
        </div>
      </div>
      <p className="text-theme-xs text-gray-500 dark:text-gray-400">
        {t("partialHint")}
      </p>
      <WorkItemExtras
        item={value}
        reportDate={reportDate}
        facebookPages={facebookPages}
        disabled={disabled}
        onChange={(_, patch) => change(patch)}
      />
      <div className="space-y-3 border-t border-gray-200 pt-4 dark:border-gray-700">
        <div className="grid gap-3 sm:grid-cols-2">
          <div>
            <Label htmlFor={`task-unit-${task.id}`}>{t("unit")}</Label>
            <Select
              id={`task-unit-${task.id}`}
              disabled={
                disabled ||
                Boolean(task.quantity_unit) ||
                input.outputs.length > 0
              }
              value={input.unit ?? ""}
              placeholder={t("chooseUnit")}
              options={(
                [
                  "video",
                  "ảnh",
                  "bài viết",
                  "sản phẩm",
                  "khách hàng",
                  "lượt chăm sóc",
                ] as TaskQuantityUnit[]
              ).map((unit) => ({ value: unit, label: t(`units.${unit}`) }))}
              onChange={(unit) =>
                progress({
                  unit: unit as TaskQuantityUnit,
                  ...(unit === "khách hàng"
                    ? {
                        care_work_items: [blankCareWork()],
                        care_entries: undefined,
                      }
                    : { care_entries: undefined, care_work_items: undefined }),
                })
              }
            />
          </div>
          <p className="self-end text-theme-xs text-gray-500 dark:text-gray-400">
            {t(customerForm ? "careProductHint" : "productHint")}
          </p>
        </div>
        {customerForm ? (
          <CustomerCareReportEditor
            task={task}
            value={careWorks}
            disabled={disabled}
            onBusy={setUploading}
            onChange={(works) => {
              change({
                result: careWorkText(works),
                taskLink: {
                  ...value.taskLink!,
                  progress: {
                    ...value.taskLink!.progress,
                    input: {
                      ...input,
                      care_entries: undefined,
                      care_work_items: works,
                      outputs: careWorkOutputs(works, task),
                    },
                  },
                },
              });
            }}
          />
        ) : (
          <TaskOutputEditor
            unit={input.unit}
            outputs={input.outputs}
            existingOutputs={task.progress?.outputs}
            disabled={disabled}
            onChange={(outputs) => progress({ outputs })}
            extraAction={
              input.unit === "video" ? (
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={disabled}
                  onClick={suggest}
                >
                  {t("detectNames")}
                </Button>
              ) : undefined
            }
          />
        )}
        {input.unit === "khách hàng" && !customerForm && (
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={disabled}
            onClick={() =>
              progress({
                care_work_items: [blankCareWork()],
                care_entries: undefined,
                outputs: [],
              })
            }
          >
            {t("useCustomerForm")}
          </Button>
        )}
      </div>
      <div className="flex flex-wrap items-center gap-3">
        <Button
          type="button"
          size="sm"
          disabled={disabled}
          onClick={apply}
          className="!bg-kps-primary hover:!bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none disabled:!bg-kps-primary"
        >
          {t("apply")}
        </Button>
        <Button
          type="button"
          size="sm"
          variant="outline"
          disabled={disabled}
          onClick={onCancel}
        >
          {t("cancel")}
        </Button>
        <span className="text-theme-xs text-gray-500 dark:text-gray-400">
          {t("applyHint")}
        </span>
      </div>
    </div>
  );
}
