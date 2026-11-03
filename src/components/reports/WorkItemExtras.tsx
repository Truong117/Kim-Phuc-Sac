import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import type {
  WorkItemField,
  WorkItemFormData,
  WorkItemValidationErrors,
} from "@/types/reports";
import { useTranslation } from "react-i18next";

type Props = {
  item: WorkItemFormData;
  errors?: WorkItemValidationErrors;
  disabled?: boolean;
  onChange: (
    clientId: string,
    changes: Partial<Omit<WorkItemFormData, "clientId">>,
  ) => void;
  onBlur?: (clientId: string, field: WorkItemField) => void;
};
export default function WorkItemExtras({
  item,
  errors,
  disabled,
  onChange,
  onBlur,
}: Props) {
  const { t } = useTranslation("common", { keyPrefix: "dailyReport.v3" });
  return (
    <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
      <div>
        <Label htmlFor={`issue-${item.clientId}`}>{t("issueLabel")}</Label>
        <TextArea
          id={`issue-${item.clientId}`}
          value={item.issue ?? ""}
          rows={2}
          disabled={disabled}
          placeholder={t("issuePlaceholder")}
          error={Boolean(errors?.issue)}
          hint={errors?.issue}
          onChange={(issue) => onChange(item.clientId, { issue })}
          onBlur={() => onBlur?.(item.clientId, "issue")}
        />
      </div>
      <div>
        <Label htmlFor={`evidence-${item.clientId}`}>
          {t("evidenceLabel")}
        </Label>
        <TextArea
          id={`evidence-${item.clientId}`}
          value={item.evidenceLinks ?? ""}
          rows={2}
          disabled={disabled}
          placeholder={t("evidencePlaceholder")}
          error={Boolean(errors?.evidenceLinks)}
          hint={errors?.evidenceLinks}
          onChange={(evidenceLinks) =>
            onChange(item.clientId, { evidenceLinks })
          }
          onBlur={() => onBlur?.(item.clientId, "evidenceLinks")}
        />
        <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
          {t("unverified")}
        </p>
      </div>
    </div>
  );
}
