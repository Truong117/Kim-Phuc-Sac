import FacebookEvidencePicker from "./FacebookEvidencePicker";
import { parseEvidenceLinks } from "@/utils/reportEvidence";
import EvidenceLinkChecks from "./EvidenceLinkChecks";
import ReportFieldRow from "./ReportFieldRow";
import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import type {
  WorkItemField,
  WorkItemFormData,
  WorkItemValidationErrors,
} from "@/types/reports";
import { useTranslation } from "react-i18next";

type Props = {
  reportDate?: string;
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
  reportDate,
  errors,
  disabled,
  onChange,
  onBlur,
}: Props) {
  const { t } = useTranslation("common", { keyPrefix: "dailyReport.v3" });
  return (
    <ReportFieldRow>
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
            onChange(item.clientId, {
              evidenceLinks,
              facebookSelections: (item.facebookSelections ?? []).filter(
                (row) =>
                  parseEvidenceLinks(evidenceLinks).includes(row.source_url),
              ),
            })
          }
          onBlur={() => onBlur?.(item.clientId, "evidenceLinks")}
        />
        <FacebookEvidencePicker
          value={item.evidenceLinks ?? ""}
          selections={item.facebookSelections ?? []}
          reportDate={reportDate}
          disabled={disabled}
          onChange={(evidenceLinks, facebookSelections) =>
            onChange(item.clientId, { evidenceLinks, facebookSelections })
          }
        />
        <EvidenceLinkChecks
          value={item.evidenceLinks ?? ""}
          confirmedUrls={(item.facebookSelections ?? []).map(
            (row) => row.source_url,
          )}
        />
        <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
          {t("unverified")}
        </p>
      </div>
    </ReportFieldRow>
  );
}
