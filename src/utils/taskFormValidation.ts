import type { TaskFormValues, TaskPriority } from "@/types/tasks";
import { compareDateOnly, isDateOnly } from "@/utils/dateOnly";

export type TaskFormField = keyof TaskFormValues;

export type TaskFormValidationError =
  | "titleRequired"
  | "assigneeRequired"
  | "priorityRequired"
  | "startDateInvalid"
  | "dueDateRequired"
  | "dueDateInvalid"
  | "dueDateBeforeStart";

export type TaskFormClientErrors = Partial<
  Record<TaskFormField, TaskFormValidationError>
>;

export type TaskFormTouchedFields = Partial<Record<TaskFormField, boolean>>;

const priorities = new Set<TaskPriority>(["LOW", "MEDIUM", "HIGH", "URGENT"]);

export const validateTaskForm = (
  values: TaskFormValues,
): TaskFormClientErrors => {
  const errors: TaskFormClientErrors = {};

  if (!values.title.trim()) errors.title = "titleRequired";
  if (!values.assigneeId) errors.assigneeId = "assigneeRequired";
  if (!priorities.has(values.priority)) errors.priority = "priorityRequired";

  if (values.startDate && !isDateOnly(values.startDate)) {
    errors.startDate = "startDateInvalid";
  }

  if (!values.dueDate) {
    errors.dueDate = "dueDateRequired";
  } else if (!isDateOnly(values.dueDate)) {
    errors.dueDate = "dueDateInvalid";
  } else if (
    values.startDate &&
    isDateOnly(values.startDate) &&
    compareDateOnly(values.dueDate, values.startDate) < 0
  ) {
    errors.dueDate = "dueDateBeforeStart";
  }

  return errors;
};

export const hasTaskFormErrors = (errors: TaskFormClientErrors) =>
  Object.keys(errors).length > 0;

export const shouldShowTaskFormError = (
  field: TaskFormField,
  errors: TaskFormClientErrors,
  touchedFields: TaskFormTouchedFields,
  submitAttempted: boolean,
) =>
  submitAttempted ||
  touchedFields[field] ||
  (field === "dueDate" &&
    errors.dueDate === "dueDateBeforeStart" &&
    touchedFields.startDate);
