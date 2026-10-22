import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import { useState } from "react";
import { useTranslation } from "react-i18next";

type Props = {
  headers: string[];
  isSaving: boolean;
  onSubmit: (mapping: Record<string, string>) => void;
};

const fields = [
  "employee_identifier",
  "employee_name",
  "department_name",
  "title",
  "expected_result",
  "start_date",
  "due_date",
  "priority",
  "note",
] as const;

export default function WeeklyPlanMappingPanel({
  headers,
  isSaving,
  onSubmit,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.mapping",
  });
  const [mapping, setMapping] = useState<Record<string, string>>({});
  const [submitted, setSubmitted] = useState(false);
  const invalid = !mapping.title || !mapping.due_date;

  return (
    <form
      className="rounded-xl border border-warning-200 bg-warning-50/60 p-4 dark:border-warning-800 dark:bg-warning-500/10"
      noValidate
      onSubmit={(event) => {
        event.preventDefault();
        setSubmitted(true);
        if (invalid) return;
        onSubmit(mapping);
      }}
    >
      <h3 className="font-semibold text-gray-900 dark:text-white">{t("title")}</h3>
      <p className="mt-1 text-theme-sm text-gray-600 dark:text-gray-300">
        {t("description")}
      </p>
      <div className="mt-4 grid gap-3 md:grid-cols-2">
        {fields.map((field) => (
          <div key={field}>
            <Label htmlFor={"weekly-map-" + field}>{t("fields." + field)}</Label>
            <Select
              id={"weekly-map-" + field}
              value={mapping[field] ?? ""}
              allowClear
              placeholder={t("notMapped")}
              options={headers.map((header) => ({ value: header, label: header }))}
              error={
                submitted &&
                (field === "title" || field === "due_date") &&
                !mapping[field]
              }
              onChange={(value) =>
                setMapping((current) => ({ ...current, [field]: value }))
              }
            />
          </div>
        ))}
      </div>
      <div className="mt-4 flex justify-end">
        <Button
          type="submit"
          disabled={isSaving}
          className="bg-kps-primary hover:bg-kps-primary-hover"
        >
          {isSaving ? t("processing") : t("apply")}
        </Button>
      </div>
    </form>
  );
}
