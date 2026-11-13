import { useState } from "react";
import { useTranslation } from "react-i18next";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import TextArea from "@/components/form/input/TextArea";
import Button from "@/components/ui/button/Button";
import {
  uploadCareEvidence,
  downloadCareEvidence,
} from "@/services/customerCareService";
import { ApiServiceError } from "@/services/apiClient";
import type { CareWorkItem } from "@/types/tasks";

export default function CareWorkEvidenceEditor({
  taskId,
  value,
  disabled,
  onChange,
  onBusy,
}: {
  taskId: number;
  value: CareWorkItem;
  disabled: boolean;
  onChange: (work: CareWorkItem) => void;
  onBusy: (busy: boolean) => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "customerCare" });
  const [error, setError] = useState("");
  async function upload(file?: File) {
    if (!file) return;
    if (
      file.size > 1024 * 1024 ||
      !["image/jpeg", "image/png", "image/webp", "application/pdf"].includes(
        file.type,
      )
    ) {
      setError(t("works.fileLimit"));
      return;
    }
    setError("");
    onBusy(true);
    try {
      const { data } = await uploadCareEvidence(taskId, file);
      onChange({
        ...value,
        attachment_ids: [...value.attachment_ids, data.id],
        attachment_names: { ...value.attachment_names, [data.id]: data.name },
      });
    } catch (cause) {
      setError(
        cause instanceof ApiServiceError
          ? Object.values(cause.validationErrors ?? {}).flat()[0] ||
              t("uploadError")
          : t("uploadError"),
      );
    } finally {
      onBusy(false);
    }
  }
  return (
    <details className="border-t border-gray-200 pt-3 dark:border-gray-700">
      <summary className="cursor-pointer text-theme-xs text-kps-primary dark:text-brand-200">
        {t("works.evidence")}
      </summary>
      <div className="mt-3 space-y-3">
        <div>
          <Label htmlFor={`care-work-links-${value.client_key}`}>
            {t("proofLinks")}
          </Label>
          <TextArea
            id={`care-work-links-${value.client_key}`}
            value={value.evidence_links.join("\n")}
            rows={2}
            placeholder={t("works.proofPlaceholder")}
            disabled={disabled}
            onChange={(text) =>
              onChange({ ...value, evidence_links: text.split(/\r?\n/) })
            }
          />
        </div>
        <div>
          <Label htmlFor={`care-work-file-${value.client_key}`}>
            {t("file")}
          </Label>
          <Input
            id={`care-work-file-${value.client_key}`}
            type="file"
            accept="image/jpeg,image/png,image/webp,application/pdf"
            disabled={disabled || value.attachment_ids.length >= 3}
            hint={t("works.fileLimit")}
            onChange={(e) => {
              void upload(e.target.files?.[0]);
              e.target.value = "";
            }}
          />
        </div>
        {value.attachment_ids.map((id) => (
          <div key={id} className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              disabled={disabled}
              className="text-theme-xs text-kps-primary underline dark:text-brand-200"
              onClick={() => {
                onBusy(true);
                void downloadCareEvidence(
                  taskId,
                  id,
                  value.attachment_names?.[id] || `evidence-${id}`,
                )
                  .catch(() => setError(t("downloadError")))
                  .finally(() => onBusy(false));
              }}
            >
              {value.attachment_names?.[id] || t("fileId", { id })}
            </button>
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={disabled}
              onClick={() =>
                onChange({
                  ...value,
                  attachment_ids: value.attachment_ids.filter((f) => f !== id),
                })
              }
            >
              {t("removeFile")}
            </Button>
          </div>
        ))}
        {error && (
          <p
            role="alert"
            className="text-theme-xs text-error-600 dark:text-error-400"
          >
            {error}
          </p>
        )}
      </div>
    </details>
  );
}
