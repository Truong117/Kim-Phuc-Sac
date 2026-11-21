import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import TextArea from "@/components/form/input/TextArea";
import Button from "@/components/ui/button/Button";
import type { WorkStatus } from "@/types/reports";
import {
  amendmentFields,
  newAmendmentItemErrors,
  type NewAmendmentItemDraft,
} from "@/utils/lockedReportAmendment";
import { useTranslation } from "react-i18next";

type Props = {
  draft: NewAmendmentItemDraft;
  number: number;
  showErrors: boolean;
  disabled: boolean;
  onChange: (patch: Partial<Omit<NewAmendmentItemDraft, "key">>) => void;
  onRemove: () => void;
};

export default function LockedReportNewItemFields({
  draft,
  number,
  showErrors,
  disabled,
  onChange,
  onRemove,
}: Props) {
  const { t } = useTranslation("common", { keyPrefix: "reportDetail" });
  const baseId = `locked-amendment-new-${draft.key}`;
  const completed = draft.status === "COMPLETED";
  const errors = showErrors ? newAmendmentItemErrors(draft) : {};
  const statuses = [
    { value: "IN_PROGRESS", label: t("lockedAmendment.inProgress") },
    { value: "COMPLETED", label: t("lockedAmendment.completed") },
    { value: "BLOCKED", label: t("lockedAmendment.blocked") },
  ] satisfies { value: WorkStatus; label: string }[];
  return (
    <fieldset
      disabled={disabled}
      className="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700"
    >
      <legend className="px-2 font-medium text-gray-900 dark:text-white">
        {t("lockedAmendment.newItem", { number })}
      </legend>
      <div>
        <Label htmlFor={`${baseId}-status`}>
          {t("lockedAmendment.status")}
        </Label>
        <Select
          id={`${baseId}-status`}
          options={statuses}
          value={draft.status}
          onChange={(value) => {
            const status = statuses.find((option) => option.value === value);
            if (status) onChange({ status: status.value });
          }}
        />
      </div>
      {amendmentFields.map((field) => {
        const id = `${baseId}-${field}`;
        const label = t(`items.${field}`);
        const invalid =
          (field === "content" && Boolean(errors.content)) ||
          (field === "result" && Boolean(errors.result));
        return (
          <div key={field}>
            <Label htmlFor={id}>{label}</Label>
            <TextArea
              id={id}
              rows={2}
              maxLength={5000}
              required={
                field === "content" || (field === "result" && completed)
              }
              value={draft[field]}
              placeholder={label}
              error={invalid}
              hint={
                invalid
                  ? t(
                      field === "content"
                        ? "lockedAmendment.contentMissing"
                        : "lockedAmendment.resultMissing",
                    )
                  : undefined
              }
              onChange={(value) => onChange({ [field]: value })}
            />
          </div>
        );
      })}
      <div>
        <Label htmlFor={`${baseId}-evidence`}>{t("items.evidence")}</Label>
        <TextArea
          id={`${baseId}-evidence`}
          rows={2}
          required={completed}
          value={draft.evidence}
          placeholder="https://"
          error={Boolean(errors.evidence)}
          hint={t(
            errors.evidence === "required"
              ? "lockedAmendment.evidenceMissing"
              : errors.evidence === "invalid"
                ? "lockedAmendment.evidenceInvalid"
                : "lockedAmendment.evidenceHint",
          )}
          onChange={(evidence) => onChange({ evidence })}
        />
      </div>
      <Button type="button" size="sm" variant="outline" onClick={onRemove}>
        {t("lockedAmendment.removeNewItem")}
      </Button>
    </fieldset>
  );
}
