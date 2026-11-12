import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import TextArea from "@/components/form/input/TextArea";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import Button from "@/components/ui/button/Button";
import type { ValidationErrors } from "@/services/apiClient";
import type {
  AssignableEmployee,
  TaskFormValues,
  TaskPriority,
  TaskQuantityUnit,
} from "@/types/tasks";
import {
  hasTaskFormErrors,
  shouldShowTaskFormError,
  validateTaskForm,
  type TaskFormField,
  type TaskFormTouchedFields,
} from "@/utils/taskFormValidation";
import { useMemo, useState } from "react";
import { useTranslation } from "react-i18next";

interface TaskFormProps {
  values: TaskFormValues;
  employees: AssignableEmployee[];
  validationErrors?: ValidationErrors;
  isSubmitting: boolean;
  submitLabel: string;
  onChange: <Key extends keyof TaskFormValues>(
    field: Key,
    value: TaskFormValues[Key],
  ) => void;
  onSubmit: (event: React.FormEvent<HTMLFormElement>) => void;
  onCancel?: () => void;
}

const firstError = (errors: ValidationErrors | undefined, key: string) =>
  errors?.[key]?.[0];

export default function TaskForm({
  values,
  employees,
  validationErrors,
  isSubmitting,
  submitLabel,
  onChange,
  onSubmit,
  onCancel,
}: TaskFormProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "taskManagement.form",
  });
  const { t: progressT } = useTranslation("common", {
    keyPrefix: "taskProgress",
  });
  const selectedEmployee = useMemo(
    () =>
      employees.find((employee) => String(employee.id) === values.assigneeId),
    [employees, values.assigneeId],
  );
  const [touchedFields, setTouchedFields] = useState<TaskFormTouchedFields>({});
  const [submitAttempted, setSubmitAttempted] = useState(false);
  const clientErrors = useMemo(() => validateTaskForm(values), [values]);

  const markTouched = (field: TaskFormField) => {
    setTouchedFields((current) =>
      current[field] ? current : { ...current, [field]: true },
    );
  };

  const fieldError = (
    field: TaskFormField,
    backendKey: string,
  ): string | undefined => {
    if (
      !shouldShowTaskFormError(
        field,
        clientErrors,
        touchedFields,
        submitAttempted,
      )
    ) {
      return undefined;
    }

    return (
      firstError(validationErrors, backendKey) ||
      (clientErrors[field] ? t(clientErrors[field]) : undefined)
    );
  };

  const titleError = fieldError("title", "title");
  const descriptionError = fieldError("description", "description");
  const assigneeError = fieldError("assigneeId", "assignee_id");
  const priorityError = fieldError("priority", "priority");
  const startDateError = fieldError("startDate", "start_date");
  const dueDateError = fieldError("dueDate", "due_date");

  const handleSubmit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setSubmitAttempted(true);

    if (hasTaskFormErrors(clientErrors)) return;
    onSubmit(event);
  };

  const changeField = <Key extends TaskFormField>(
    field: Key,
    value: TaskFormValues[Key],
  ) => {
    onChange(field, value);
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-5" noValidate>
      <div>
        <Label htmlFor="task-title">{t("title")}</Label>
        <Input
          id="task-title"
          value={values.title}
          maxLength={255}
          required
          error={Boolean(titleError)}
          hint={titleError}
          onChange={(event) => changeField("title", event.target.value)}
          onBlur={() => markTouched("title")}
          placeholder={t("titlePlaceholder")}
        />
      </div>

      <div>
        <Label htmlFor="task-description">{t("description")}</Label>
        <TextArea
          id="task-description"
          rows={4}
          value={values.description}
          error={Boolean(descriptionError)}
          hint={descriptionError}
          onChange={(value) => changeField("description", value)}
          onBlur={() => markTouched("description")}
          placeholder={t("descriptionPlaceholder")}
        />
      </div>

      <div className="grid gap-5 md:grid-cols-2">
        <div>
          <Label htmlFor="task-assignee">{t("assignee")}</Label>
          <Select
            id="task-assignee"
            value={values.assigneeId}
            required
            error={Boolean(assigneeError)}
            hint={assigneeError}
            placeholder={t("assigneePlaceholder")}
            onChange={(value) => changeField("assigneeId", value)}
            onBlur={() => markTouched("assigneeId")}
            options={employees.map((employee) => ({
              value: String(employee.id),
              label: employee.name,
            }))}
          />
          {selectedEmployee && (
            <p className="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
              {selectedEmployee.department?.name ?? t("noDepartment")}
              {selectedEmployee.location
                ? ` · ${selectedEmployee.location.name}`
                : ""}
            </p>
          )}
        </div>
        <div>
          <Label htmlFor="task-priority">{t("priority")}</Label>
          <Select
            id="task-priority"
            value={values.priority}
            required
            error={Boolean(priorityError)}
            hint={priorityError}
            onChange={(value) => changeField("priority", value as TaskPriority)}
            onBlur={() => markTouched("priority")}
            options={(
              ["LOW", "MEDIUM", "HIGH", "URGENT"] as TaskPriority[]
            ).map((priority) => ({
              value: priority,
              label: t(`priorityOptions.${priority}`),
            }))}
          />
        </div>
        <div>
          <Label htmlFor="task-start-date">{t("startDate")}</Label>
          <KpsDatePicker
            id="task-start-date"
            value={values.startDate}
            onChange={(date) => changeField("startDate", date)}
            onBlur={() => markTouched("startDate")}
            ariaLabel={t("startDateAria")}
            placeholder={t("startDatePlaceholder")}
            clearable
            error={Boolean(startDateError)}
            hint={startDateError}
          />
        </div>
        <div>
          <Label htmlFor="task-due-date">{t("dueDate")}</Label>
          <KpsDatePicker
            id="task-due-date"
            value={values.dueDate}
            onChange={(date) => changeField("dueDate", date)}
            onBlur={() => markTouched("dueDate")}
            ariaLabel={t("dueDateAria")}
            placeholder={t("dueDatePlaceholder")}
            minDate={values.startDate || undefined}
            required
            error={Boolean(dueDateError)}
            hint={dueDateError}
          />
        </div>
      </div>

      <fieldset className="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
        <legend className="px-2 text-theme-sm font-medium text-gray-800 dark:text-gray-200">
          {progressT("goalTitle")}
        </legend>
        <p className="text-theme-xs text-gray-500 dark:text-gray-400">
          {progressT("goalHint")}
        </p>
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <Label htmlFor="task-target-quantity">
              {progressT("targetQuantity")}
            </Label>
            <Input
              id="task-target-quantity"
              type="number"
              min="1"
              max="1000000"
              value={values.targetQuantity ?? ""}
              disabled={isSubmitting}
              error={Boolean(fieldError("targetQuantity", "target_quantity"))}
              hint={fieldError("targetQuantity", "target_quantity")}
              onChange={(e) => changeField("targetQuantity", e.target.value)}
              onBlur={() => markTouched("targetQuantity")}
            />
          </div>
          <div>
            <Label htmlFor="task-quantity-unit">{progressT("unit")}</Label>
            <Select
              id="task-quantity-unit"
              value={values.quantityUnit ?? ""}
              disabled={isSubmitting}
              placeholder={progressT("chooseUnit")}
              error={Boolean(fieldError("quantityUnit", "quantity_unit"))}
              hint={fieldError("quantityUnit", "quantity_unit")}
              options={(
                ["video", "ảnh", "bài viết", "sản phẩm"] as TaskQuantityUnit[]
              ).map((unit) => ({
                value: unit,
                label: progressT(`units.${unit}`),
              }))}
              onChange={(unit) =>
                changeField("quantityUnit", unit as TaskQuantityUnit)
              }
              onBlur={() => markTouched("quantityUnit")}
            />
          </div>
        </div>
      </fieldset>
      <div className="flex flex-col-reverse gap-3 pt-1 sm:flex-row sm:justify-end">
        {onCancel && (
          <Button type="button" variant="outline" onClick={onCancel}>
            {t("cancel")}
          </Button>
        )}
        <Button
          type="submit"
          disabled={isSubmitting}
          className="!bg-kps-primary hover:!bg-kps-primary-hover disabled:!bg-kps-primary"
        >
          {isSubmitting ? t("saving") : submitLabel}
        </Button>
      </div>
    </form>
  );
}
