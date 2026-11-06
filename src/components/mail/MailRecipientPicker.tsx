import { useState } from "react";
import { useTranslation } from "react-i18next";
import Input from "@/components/form/input/InputField";
import Button from "@/components/ui/button/Button";
import type { MailRecipient } from "@/services/mailService";

interface Props {
  recipients: MailRecipient[];
  selectedIds: string[];
  onChange: (ids: string[]) => void;
  disabled: boolean;
  invalid: boolean;
  mode?: "test" | "internal";
}
export default function MailRecipientPicker({
  recipients,
  selectedIds,
  onChange,
  disabled,
  invalid,
  mode = "test",
}: Props) {
  const { t } = useTranslation();
  const [query, setQuery] = useState("");
  const visible = recipients.filter((recipient) =>
    `${recipient.name} ${recipient.email} ${recipient.department || ""}`
      .toLocaleLowerCase("vi")
      .includes(query.toLocaleLowerCase("vi").trim()),
  );
  const selected = new Set(selectedIds);
  const allowed = (recipient: MailRecipient) =>
    mode === "test" ? recipient.test_allowed : recipient.send_allowed;
  const selectable = visible.filter(allowed);
  return (
    <fieldset
      disabled={disabled}
      aria-invalid={invalid || undefined}
      className={`min-w-0 space-y-3 rounded-lg border p-3 ${invalid ? "border-error-500 dark:border-error-400" : "border-gray-300 dark:border-gray-700"}`}
    >
      <legend
        id={`mail-${mode}-recipients-label`}
        className="px-1 text-sm font-medium text-gray-700 dark:text-gray-300"
      >
        {t(mode === "test" ? "mailUi.recipient" : "mailCompose.recipients")}
      </legend>
      <Input
        aria-label={t("mailUi.searchRecipients")}
        placeholder={t("mailUi.searchRecipients")}
        value={query}
        disabled={disabled}
        onChange={(event) => setQuery(event.target.value)}
      />
      <div className="flex flex-wrap items-center gap-3">
        <Button
          variant="outline"
          size="sm"
          disabled={disabled || selectable.length === 0}
          onClick={() =>
            onChange(
              Array.from(
                new Set([
                  ...selectedIds,
                  ...selectable.map((recipient) => recipient.id),
                ]),
              ).slice(0, 50),
            )
          }
        >
          {t("mailUi.selectVisible")}
        </Button>
        <Button
          variant="outline"
          size="sm"
          disabled={disabled || selectedIds.length === 0}
          onClick={() => onChange([])}
        >
          {t("mailUi.clearSelection")}
        </Button>
        <p
          className="text-sm text-gray-600 dark:text-gray-300"
          aria-live="polite"
        >
          {t("mailUi.selectedCount", { count: selectedIds.length, max: 50 })}
        </p>
      </div>
      <div className="max-h-64 space-y-2 overflow-y-auto">
        {visible.map((recipient) => (
          <label
            key={recipient.id}
            className="flex min-h-11 min-w-0 items-start gap-3 rounded-lg p-2 text-sm text-gray-800 hover:bg-gray-50 dark:text-gray-200 dark:hover:bg-gray-800"
          >
            <input
              type="checkbox"
              checked={selected.has(recipient.id)}
              disabled={
                disabled ||
                !allowed(recipient) ||
                (!selected.has(recipient.id) && selectedIds.length >= 50)
              }
              onChange={(event) =>
                onChange(
                  event.target.checked
                    ? [...selectedIds, recipient.id]
                    : selectedIds.filter((id) => id !== recipient.id),
                )
              }
              className="mt-0.5 size-5 shrink-0 accent-kps-primary"
            />
            <span className="min-w-0 break-all">
              {recipient.name} — {recipient.email}
              {recipient.department ? ` · ${recipient.department}` : ""}
              {!allowed(recipient)
                ? ` (${t(mode === "test" ? "mailUi.notAllowed" : "mailCompose.invalidEmail")})`
                : ""}
            </span>
          </label>
        ))}
        {visible.length === 0 && (
          <p className="text-sm text-gray-500 dark:text-gray-400">
            {t("mailUi.noRecipients")}
          </p>
        )}
      </div>
    </fieldset>
  );
}
