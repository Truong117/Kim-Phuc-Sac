import KpsDatePicker from "@/components/common/KpsDatePicker";
import Input from "@/components/form/input/InputField";
import TextArea from "@/components/form/input/TextArea";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import type { TaskPriority } from "@/types/tasks";
import type {
  WeeklyPlanEmployee,
  WeeklyPlanEntry,
  WeeklyPlanEntryInput,
} from "@/types/weeklyPlanning";
import { compareDateOnly } from "@/utils/dateOnly";
import { useMemo, useState } from "react";
import { useTranslation } from "react-i18next";

type Props = {
  entry?: WeeklyPlanEntry;
  initialInput?: Partial<WeeklyPlanEntryInput>;
  employees: WeeklyPlanEmployee[];
  weekStart: string;
  weekEnd: string;
  reviewerEdit?: boolean;
  lockAssignee?: boolean;
  lockedAssignee?: Pick<WeeklyPlanEmployee, "id" | "name" | "email">;
  assigneeSourceTitle?: string;
  serverErrors?: Record<string, string[]>;
  serverErrorVersion?: number;
  submitLabel?: string;
  isSaving: boolean;
  onSave: (input: WeeklyPlanEntryInput) => void;
  onCancel: () => void;
};

const initialValues = (
  entry: WeeklyPlanEntry | undefined,
  weekStart: string,
  weekEnd: string,
  initialInput: Partial<WeeklyPlanEntryInput> = {},
): WeeklyPlanEntryInput => ({
  assignee_id: initialInput.assignee_id ?? entry?.assignee?.id ?? 0,
  title: initialInput.title ?? entry?.title ?? "",
  content: initialInput.content ?? entry?.content ?? "",
  expected_result: initialInput.expected_result ?? entry?.expected_result ?? "",
  start_date: initialInput.start_date ?? entry?.start_date ?? weekStart,
  due_date: initialInput.due_date ?? entry?.due_date ?? weekEnd,
  priority: initialInput.priority ?? entry?.priority ?? "MEDIUM",
  note: initialInput.note ?? entry?.note ?? "",
  items:
    initialInput.items ??
    entry?.items.map((item) => ({
      title: item.title,
      content: item.content,
      source_passage_ids: [...item.source_passage_ids],
    })) ??
    [],
});

export default function WeeklyPlanEntryForm({
  entry,
  initialInput,
  employees,
  weekStart,
  weekEnd,
  reviewerEdit = false,
  lockAssignee = false,
  lockedAssignee,
  assigneeSourceTitle,
  serverErrors = {},
  serverErrorVersion = 0,
  submitLabel,
  isSaving,
  onSave,
  onCancel,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.entryForm",
  });
  const [values, setValues] = useState(() =>
    initialValues(entry, weekStart, weekEnd, initialInput),
  );
  const [touched, setTouched] = useState<Record<string, boolean>>({});
  const [submitted, setSubmitted] = useState(false);
  const [clearedServerErrors, setClearedServerErrors] = useState<
    Record<string, number>
  >({});
  const errors = useMemo(() => {
    const result: Record<string, string> = {};
    if (
      !values.assignee_id ||
      (lockAssignee && lockedAssignee?.id !== values.assignee_id)
    ) {
      result.assignee_id = lockAssignee
        ? t("mappedAssigneeUnavailable")
        : t("assigneeRequired");
    }
    if (!values.title.trim()) result.title = t("titleRequired");
    if (!values.start_date) result.start_date = t("startRequired");
    if (!values.due_date) result.due_date = t("dueRequired");
    if (
      values.start_date &&
      values.due_date &&
      compareDateOnly(values.due_date, values.start_date) < 0
    ) {
      result.due_date = t("dateOrder");
    }
    return result;
  }, [lockAssignee, lockedAssignee?.id, t, values]);
  const visibleError = (field: string) =>
    (submitted || touched[field] ? errors[field] : undefined) ??
    (clearedServerErrors[field] === serverErrorVersion
      ? undefined
      : (serverErrors[field]?.[0] ?? serverErrors[`entries.0.${field}`]?.[0]));
  const clearServerError = (field: string) =>
    setClearedServerErrors((current) => ({
      ...current,
      [field]: serverErrorVersion,
    }));

  return (
    <form
      className="space-y-4"
      noValidate
      onSubmit={(event) => {
        event.preventDefault();
        setSubmitted(true);
        if (Object.keys(errors).length > 0) return;
        onSave({
          ...values,
          content: values.content?.trim() || null,
          expected_result: values.expected_result?.trim() || null,
          note: values.note?.trim() || null,
        });
      }}
    >
      <div>
        <Label htmlFor="weekly-entry-assignee">{t("assignee")}</Label>
        {lockAssignee ? (
          <div
            id="weekly-entry-assignee"
            role="textbox"
            aria-readonly="true"
            className="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/60"
          >
            {lockedAssignee ? (
              <>
                <p className="text-theme-sm font-semibold text-gray-900 dark:text-white">
                  {lockedAssignee.name}
                </p>
                <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                  {lockedAssignee.email}
                </p>
                {assigneeSourceTitle && (
                  <p className="mt-2 text-theme-xs font-medium text-kps-primary dark:text-sidebar-selected">
                    {t("lockedAssigneeSource", {
                      source: assigneeSourceTitle,
                    })}
                  </p>
                )}
              </>
            ) : (
              <p className="text-theme-xs text-error-600 dark:text-error-400">
                {t("mappedAssigneeUnavailable")}
              </p>
            )}
          </div>
        ) : (
          <Select
            id="weekly-entry-assignee"
            value={String(values.assignee_id || "")}
            disabled={reviewerEdit}
            placeholder={t("assigneePlaceholder")}
            options={employees.map((employee) => ({
              value: String(employee.id),
              label: employee.name + " — " + employee.email,
            }))}
            error={Boolean(visibleError("assignee_id"))}
            hint={visibleError("assignee_id")}
            onBlur={() =>
              setTouched((current) => ({ ...current, assignee_id: true }))
            }
            onChange={(value) => {
              clearServerError("assignee_id");
              setValues((current) => ({
                ...current,
                assignee_id: Number(value),
              }));
            }}
          />
        )}
      </div>
      <div>
        <Label htmlFor="weekly-entry-title">{t("title")}</Label>
        <Input
          id="weekly-entry-title"
          value={values.title}
          maxLength={255}
          error={Boolean(visibleError("title"))}
          hint={visibleError("title")}
          onBlur={() => setTouched((current) => ({ ...current, title: true }))}
          onChange={(event) => {
            clearServerError("title");
            setValues((current) => ({ ...current, title: event.target.value }));
          }}
        />
      </div>
      <div className="grid gap-4 md:grid-cols-2">
        <div>
          <Label htmlFor="weekly-entry-content">{t("content")}</Label>
          <TextArea
            id="weekly-entry-content"
            rows={3}
            value={values.content ?? ""}
            onChange={(value) => {
              clearServerError("content");
              setValues((current) => ({ ...current, content: value }));
            }}
            error={Boolean(visibleError("content"))}
            hint={visibleError("content")}
          />
        </div>
        <div>
          <Label htmlFor="weekly-entry-result">{t("expectedResult")}</Label>
          <TextArea
            id="weekly-entry-result"
            rows={3}
            value={values.expected_result ?? ""}
            onChange={(value) => {
              clearServerError("expected_result");
              setValues((current) => ({ ...current, expected_result: value }));
            }}
            error={Boolean(visibleError("expected_result"))}
            hint={visibleError("expected_result")}
          />
        </div>
      </div>
      <div className="grid gap-4 md:grid-cols-3">
        <div>
          <Label htmlFor="weekly-entry-start">{t("startDate")}</Label>
          <KpsDatePicker
            id="weekly-entry-start"
            value={values.start_date}
            onChange={(value) => {
              clearServerError("start_date");
              setValues((current) => ({ ...current, start_date: value }));
            }}
            onBlur={() =>
              setTouched((current) => ({ ...current, start_date: true }))
            }
            ariaLabel={t("startDate")}
            placeholder={t("chooseDate")}
            minDate={weekStart}
            maxDate={weekEnd}
            disabled={reviewerEdit}
            required
            error={Boolean(visibleError("start_date"))}
            hint={visibleError("start_date")}
          />
        </div>
        <div>
          <Label htmlFor="weekly-entry-due">{t("dueDate")}</Label>
          <KpsDatePicker
            id="weekly-entry-due"
            value={values.due_date}
            onChange={(value) => {
              clearServerError("due_date");
              setValues((current) => ({ ...current, due_date: value }));
            }}
            onBlur={() =>
              setTouched((current) => ({ ...current, due_date: true }))
            }
            ariaLabel={t("dueDate")}
            placeholder={t("chooseDate")}
            minDate={values.start_date || weekStart}
            maxDate={weekEnd}
            required
            error={Boolean(visibleError("due_date"))}
            hint={visibleError("due_date")}
          />
        </div>
        <div>
          <Label htmlFor="weekly-entry-priority">{t("priority")}</Label>
          <Select
            id="weekly-entry-priority"
            value={values.priority}
            options={(
              ["LOW", "MEDIUM", "HIGH", "URGENT"] as TaskPriority[]
            ).map((priority) => ({
              value: priority,
              label: t("priorities." + priority),
            }))}
            onChange={(value) => {
              clearServerError("priority");
              setValues((current) => ({
                ...current,
                priority: value as TaskPriority,
              }));
            }}
            error={Boolean(visibleError("priority"))}
            hint={visibleError("priority")}
          />
        </div>
      </div>
      <div>
        <Label htmlFor="weekly-entry-note">{t("note")}</Label>
        <TextArea
          id="weekly-entry-note"
          rows={2}
          value={values.note ?? ""}
          onChange={(value) => {
            clearServerError("note");
            setValues((current) => ({ ...current, note: value }));
          }}
          error={Boolean(visibleError("note"))}
          hint={visibleError("note")}
        />
      </div>
      <div className="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <p className="font-semibold text-gray-900 dark:text-white">
              {t("itemsTitle")}
            </p>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              {t("itemsDescription")}
            </p>
          </div>
          <Button
            type="button"
            size="sm"
            variant="outline"
            onClick={() =>
              setValues((current) => ({
                ...current,
                items: [...(current.items ?? []), { title: "", content: null }],
              }))
            }
          >
            {t("addItem")}
          </Button>
        </div>
        {(values.items ?? []).map((item, index) => (
          <div
            key={`entry-item-${index}`}
            className="rounded-lg bg-gray-50 p-3 dark:bg-gray-800/60"
          >
            <div className="grid gap-3 md:grid-cols-2">
              <div>
                <Label htmlFor={`weekly-entry-item-title-${index}`}>
                  {t("itemTitle", { index: index + 1 })}
                </Label>
                <Input
                  id={`weekly-entry-item-title-${index}`}
                  value={item.title}
                  maxLength={255}
                  onChange={(event) =>
                    setValues((current) => ({
                      ...current,
                      items: (current.items ?? []).map(
                        (currentItem, itemIndex) =>
                          itemIndex === index
                            ? { ...currentItem, title: event.target.value }
                            : currentItem,
                      ),
                    }))
                  }
                />
              </div>
              <div>
                <Label htmlFor={`weekly-entry-item-content-${index}`}>
                  {t("itemContent")}
                </Label>
                <TextArea
                  id={`weekly-entry-item-content-${index}`}
                  rows={2}
                  value={item.content ?? ""}
                  onChange={(content) =>
                    setValues((current) => ({
                      ...current,
                      items: (current.items ?? []).map(
                        (currentItem, itemIndex) =>
                          itemIndex === index
                            ? { ...currentItem, content }
                            : currentItem,
                      ),
                    }))
                  }
                />
              </div>
            </div>
            <div className="mt-2 flex flex-wrap justify-end gap-2">
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={index === 0}
                onClick={() =>
                  setValues((current) => {
                    const items = [...(current.items ?? [])];
                    [items[index - 1], items[index]] = [
                      items[index],
                      items[index - 1],
                    ];
                    return { ...current, items };
                  })
                }
              >
                {t("moveUp")}
              </Button>
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={index === (values.items?.length ?? 0) - 1}
                onClick={() =>
                  setValues((current) => {
                    const items = [...(current.items ?? [])];
                    [items[index], items[index + 1]] = [
                      items[index + 1],
                      items[index],
                    ];
                    return { ...current, items };
                  })
                }
              >
                {t("moveDown")}
              </Button>
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() =>
                  setValues((current) => ({
                    ...current,
                    items: (current.items ?? []).filter(
                      (_, itemIndex) => itemIndex !== index,
                    ),
                  }))
                }
              >
                {t("removeItem")}
              </Button>
            </div>
          </div>
        ))}
      </div>
      <div className="flex justify-end gap-3">
        <Button type="button" variant="outline" onClick={onCancel}>
          {t("cancel")}
        </Button>
        <Button
          type="submit"
          disabled={isSaving}
          className="bg-kps-primary hover:bg-kps-primary-hover"
        >
          {isSaving ? t("saving") : (submitLabel ?? t("save"))}
        </Button>
      </div>
    </form>
  );
}
