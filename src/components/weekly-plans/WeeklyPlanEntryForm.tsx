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
  employees: WeeklyPlanEmployee[];
  weekStart: string;
  weekEnd: string;
  reviewerEdit?: boolean;
  isSaving: boolean;
  onSave: (input: WeeklyPlanEntryInput) => void;
  onCancel: () => void;
};

const initialValues = (
  entry: WeeklyPlanEntry | undefined,
  weekStart: string,
  weekEnd: string,
): WeeklyPlanEntryInput => ({
  assignee_id: entry?.assignee?.id ?? 0,
  title: entry?.title ?? "",
  content: entry?.content ?? "",
  expected_result: entry?.expected_result ?? "",
  start_date: entry?.start_date ?? weekStart,
  due_date: entry?.due_date ?? weekEnd,
  priority: entry?.priority ?? "MEDIUM",
  note: entry?.note ?? "",
});

export default function WeeklyPlanEntryForm({
  entry,
  employees,
  weekStart,
  weekEnd,
  reviewerEdit = false,
  isSaving,
  onSave,
  onCancel,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.entryForm",
  });
  const [values, setValues] = useState(() =>
    initialValues(entry, weekStart, weekEnd),
  );
  const [touched, setTouched] = useState<Record<string, boolean>>({});
  const [submitted, setSubmitted] = useState(false);
  const errors = useMemo(() => {
    const result: Record<string, string> = {};
    if (!values.assignee_id) result.assignee = t("assigneeRequired");
    if (!values.title.trim()) result.title = t("titleRequired");
    if (!values.start_date) result.start = t("startRequired");
    if (!values.due_date) result.due = t("dueRequired");
    if (
      values.start_date &&
      values.due_date &&
      compareDateOnly(values.due_date, values.start_date) < 0
    ) {
      result.due = t("dateOrder");
    }
    return result;
  }, [t, values]);
  const visibleError = (field: string) =>
    submitted || touched[field] ? errors[field] : undefined;

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
        <Select
          id="weekly-entry-assignee"
          value={String(values.assignee_id || "")}
          disabled={reviewerEdit}
          placeholder={t("assigneePlaceholder")}
          options={employees.map((employee) => ({
            value: String(employee.id),
            label: employee.name + " — " + employee.email,
          }))}
          error={Boolean(visibleError("assignee"))}
          hint={visibleError("assignee")}
          onBlur={() => setTouched((current) => ({ ...current, assignee: true }))}
          onChange={(value) =>
            setValues((current) => ({
              ...current,
              assignee_id: Number(value),
            }))
          }
        />
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
          onChange={(event) =>
            setValues((current) => ({ ...current, title: event.target.value }))
          }
        />
      </div>
      <div className="grid gap-4 md:grid-cols-2">
        <div>
          <Label htmlFor="weekly-entry-content">{t("content")}</Label>
          <TextArea
            id="weekly-entry-content"
            rows={3}
            value={values.content ?? ""}
            onChange={(value) =>
              setValues((current) => ({ ...current, content: value }))
            }
          />
        </div>
        <div>
          <Label htmlFor="weekly-entry-result">{t("expectedResult")}</Label>
          <TextArea
            id="weekly-entry-result"
            rows={3}
            value={values.expected_result ?? ""}
            onChange={(value) =>
              setValues((current) => ({ ...current, expected_result: value }))
            }
          />
        </div>
      </div>
      <div className="grid gap-4 md:grid-cols-3">
        <div>
          <Label htmlFor="weekly-entry-start">{t("startDate")}</Label>
          <KpsDatePicker
            id="weekly-entry-start"
            value={values.start_date}
            onChange={(value) =>
              setValues((current) => ({ ...current, start_date: value }))
            }
            onBlur={() => setTouched((current) => ({ ...current, start: true }))}
            ariaLabel={t("startDate")}
            placeholder={t("chooseDate")}
            minDate={weekStart}
            maxDate={weekEnd}
            disabled={reviewerEdit}
            required
            error={Boolean(visibleError("start"))}
            hint={visibleError("start")}
          />
        </div>
        <div>
          <Label htmlFor="weekly-entry-due">{t("dueDate")}</Label>
          <KpsDatePicker
            id="weekly-entry-due"
            value={values.due_date}
            onChange={(value) =>
              setValues((current) => ({ ...current, due_date: value }))
            }
            onBlur={() => setTouched((current) => ({ ...current, due: true }))}
            ariaLabel={t("dueDate")}
            placeholder={t("chooseDate")}
            minDate={values.start_date || weekStart}
            maxDate={weekEnd}
            required
            error={Boolean(visibleError("due"))}
            hint={visibleError("due")}
          />
        </div>
        <div>
          <Label htmlFor="weekly-entry-priority">{t("priority")}</Label>
          <Select
            id="weekly-entry-priority"
            value={values.priority}
            options={(["LOW", "MEDIUM", "HIGH", "URGENT"] as TaskPriority[]).map(
              (priority) => ({
                value: priority,
                label: t("priorities." + priority),
              }),
            )}
            onChange={(value) =>
              setValues((current) => ({
                ...current,
                priority: value as TaskPriority,
              }))
            }
          />
        </div>
      </div>
      <div>
        <Label htmlFor="weekly-entry-note">{t("note")}</Label>
        <TextArea
          id="weekly-entry-note"
          rows={2}
          value={values.note ?? ""}
          onChange={(value) =>
            setValues((current) => ({ ...current, note: value }))
          }
        />
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
          {isSaving ? t("saving") : t("save")}
        </Button>
      </div>
    </form>
  );
}
