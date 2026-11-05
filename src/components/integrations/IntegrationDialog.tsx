import { ApiServiceError } from "@/services/apiClient";
import type { IntegrationOwner } from "@/services/integrationService";
import { useRef, useState, type FormEvent } from "react";
import { useTranslation } from "react-i18next";
import { Modal } from "@/components/ui/modal";
import Button from "@/components/ui/button/Button";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import { CloseIcon } from "@/icons";
import {
  normalizedUrl,
  platforms,
  validUrl,
  type IntegrationEntry,
} from "./model";
export type DialogMode = "add" | "edit" | "detail" | "delete";
interface Props {
  mode: DialogMode;
  initial: IntegrationEntry;
  entries: IntegrationEntry[];
  onClose: () => void;
  owners: IntegrationOwner[];
  onSave: (entry: IntegrationEntry) => Promise<void>;
  onDelete: () => Promise<void>;
}
export default function IntegrationDialog({
  mode,
  initial,
  entries,
  onClose,
  owners,
  onSave,
  onDelete,
}: Props) {
  const { t } = useTranslation();
  const [draft, setDraft] = useState(initial);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [failure, setFailure] = useState("");
  const formRef = useRef<HTMLFormElement>(null);
  const failureRef = useRef<HTMLParagraphElement>(null);
  const close = () => {
    if (!busy) onClose();
  };
  function focusError(fields: Record<string, string>) {
    requestAnimationFrame(() => {
      const field = formRef.current?.querySelector<HTMLElement>(
        `[name="${Object.keys(fields)[0]}"]`,
      );
      (field ?? failureRef.current)?.focus();
      (field ?? failureRef.current)?.scrollIntoView({ block: "nearest" });
    });
  }
  async function persist(action: () => Promise<void>) {
    if (busy) return;
    setBusy(true);
    setFailure("");
    try {
      await action();
    } catch (error) {
      const fields =
        error instanceof ApiServiceError
          ? Object.fromEntries(
              Object.entries(error.validationErrors ?? {}).map(
                ([key, values]) => [key, values[0]],
              ),
            )
          : {};
      setErrors(fields);
      setFailure(
        t(
          error instanceof ApiServiceError && error.status === 409
            ? "integrationsUi.stale"
            : "integrationsUi.saveError",
        ),
      );
      focusError(fields);
    } finally {
      setBusy(false);
    }
  }
  const isDrive = initial.kind === "drive";
  const editing = mode === "add" || mode === "edit";
  const title = t(`integrationsUi.dialog.${mode}`, {
    type: t(`integrationsUi.${isDrive ? "folder" : "channel"}`),
  });
  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const value = {
      ...draft,
      name: draft.name.trim(),
      url: draft.url.trim(),
      owner: draft.owner.trim(),
      note: draft.note.trim(),
    };
    const next: Record<string, string> = {};
    if (!value.name) next.name = t("integrationsUi.required");
    if (!value.responsible_user_id)
      next.responsible_user_id = t("integrationsUi.required");
    if (!validUrl(value))
      next.url = t(
        `integrationsUi.${isDrive ? "driveUrlError" : "socialUrlError"}`,
      );
    if (
      entries.some(
        (row) =>
          row.id !== value.id &&
          row.kind === value.kind &&
          normalizedUrl(row.url) === normalizedUrl(value.url),
      )
    )
      next.url = t("integrationsUi.duplicate");
    setErrors(next);
    if (Object.keys(next).length) {
      setFailure(t("integrationsUi.invalidForm"));
      focusError(next);
      return;
    }
    void persist(() => onSave(value));
  }
  return (
    <Modal
      isOpen
      onClose={close}
      showCloseButton={false}
      ariaLabelledBy="integration-dialog-title"
      className="m-4 max-h-[90dvh] max-w-xl overflow-y-auto p-6 sm:p-8"
    >
      <div className="flex items-start justify-between gap-4">
        <div>
          <p className="mb-2 text-xs font-medium uppercase tracking-wider text-kps-primary">
            {t("integrationsUi.previewLabel")}
          </p>
          <h2
            id="integration-dialog-title"
            className="text-xl font-semibold text-gray-900 dark:text-white"
          >
            {title}
          </h2>
        </div>
        <button
          type="button"
          onClick={close}
          aria-label={t("integrationsUi.close")}
          className="rounded-lg p-2 text-gray-500 hover:bg-gray-100 focus-visible:outline-2 focus-visible:outline-kps-primary dark:text-gray-400 dark:hover:bg-gray-800"
        >
          <CloseIcon className="size-5" />
        </button>
      </div>
      {failure && (
        <p
          ref={failureRef}
          role="alert"
          tabIndex={-1}
          className="mt-4 rounded-lg bg-error-50 p-3 text-sm text-error-600 dark:bg-error-500/10 dark:text-error-400"
        >
          {failure}
        </p>
      )}
      {mode === "delete" ? (
        <>
          <p className="mt-5 break-words text-sm text-gray-600 dark:text-gray-300">
            {t("integrationsUi.deleteConfirm", { name: initial.name })}
          </p>
          <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">
            {t("integrationsUi.deleteHint")}
          </p>
          <div className="mt-6 flex justify-end gap-3">
            <Button variant="outline" onClick={close}>
              {t("integrationsUi.cancel")}
            </Button>
            <Button
              variant="destructive"
              disabled={busy}
              onClick={() => void persist(onDelete)}
            >
              {t("integrationsUi.delete")}
            </Button>
          </div>
        </>
      ) : editing ? (
        <form
          ref={formRef}
          className="mt-6 space-y-5"
          onSubmit={submit}
          noValidate
          aria-busy={busy}
        >
          <fieldset disabled={busy} className="space-y-5">
            {!isDrive && (
              <div>
                <Label htmlFor="integration-platform">
                  {t("integrationsUi.platform")}
                </Label>
                <select
                  id="integration-platform"
                  value={draft.platform ?? "tiktok"}
                  onChange={(e) =>
                    setDraft({
                      ...draft,
                      platform: e.target.value as IntegrationEntry["platform"],
                    })
                  }
                  className="h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-sm text-gray-800 focus:outline-kps-primary dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                >
                  {platforms.map((p) => (
                    <option key={p} value={p}>
                      {t(`integrationsUi.platforms.${p}`)}
                    </option>
                  ))}
                </select>
              </div>
            )}
            {(["name", "url"] as const).map((field) => (
              <div key={field}>
                <Label htmlFor={`integration-${field}`}>
                  {t(
                    `integrationsUi.${field === "name" ? (isDrive ? "folderName" : "channelName") : field}`,
                  )}{" "}
                  *
                </Label>
                <Input
                  id={`integration-${field}`}
                  name={field}
                  value={draft[field]}
                  maxLength={field === "url" ? 1000 : 150}
                  onChange={(e) =>
                    setDraft({ ...draft, [field]: e.target.value })
                  }
                  error={!!errors[field]}
                  aria-invalid={!!errors[field]}
                  hint={errors[field]}
                  required
                />
                {field === "url" && (
                  <p className="mt-2 text-xs text-gray-500 dark:text-gray-400">
                    {t(
                      `integrationsUi.${isDrive ? "driveUrlHint" : "socialUrlHint"}`,
                    )}
                  </p>
                )}
              </div>
            ))}
            <div>
              <Label htmlFor="integration-responsible">
                {t("integrationsUi.owner")} *
              </Label>
              <select
                id="integration-responsible"
                name="responsible_user_id"
                value={draft.responsible_user_id}
                onChange={(e) =>
                  setDraft({ ...draft, responsible_user_id: e.target.value })
                }
                aria-invalid={!!errors.responsible_user_id}
                aria-describedby={
                  errors.responsible_user_id
                    ? "integration-responsible-error"
                    : undefined
                }
                className={`h-11 w-full rounded-lg border bg-white px-3 text-sm text-gray-800 focus:outline-kps-primary dark:bg-gray-900 dark:text-gray-100 ${errors.responsible_user_id ? "border-error-500" : "border-gray-300 dark:border-gray-700"}`}
              >
                <option value="">{t("integrationsUi.chooseOwner")}</option>
                {draft.responsible_user_id &&
                  !owners.some(
                    (owner) => owner.id === draft.responsible_user_id,
                  ) && (
                    <option value={draft.responsible_user_id} disabled>
                      {draft.owner} ({t("integrationsUi.inactiveOwner")})
                    </option>
                  )}
                {owners.map((owner) => (
                  <option key={owner.id} value={owner.id}>
                    {owner.name}
                  </option>
                ))}
              </select>
              {errors.responsible_user_id && (
                <p
                  id="integration-responsible-error"
                  className="mt-1 text-xs text-error-500"
                >
                  {errors.responsible_user_id}
                </p>
              )}
            </div>
            <div>
              <Label htmlFor="integration-note">
                {t("integrationsUi.note")}
              </Label>
              <textarea
                id="integration-note"
                value={draft.note}
                maxLength={1000}
                onChange={(e) => setDraft({ ...draft, note: e.target.value })}
                rows={3}
                className="w-full rounded-lg border border-gray-300 bg-transparent p-3 text-sm text-gray-800 focus:outline-kps-primary dark:border-gray-700 dark:text-gray-100"
              />
            </div>
            <label className="flex items-center gap-3 text-sm text-gray-700 dark:text-gray-300">
              <input
                type="checkbox"
                checked={draft.enabled}
                onChange={(e) =>
                  setDraft({ ...draft, enabled: e.target.checked })
                }
                className="size-4 accent-kps-primary"
              />
              {t(`integrationsUi.${isDrive ? "enableDrive" : "enableSocial"}`)}
            </label>
            <p className="rounded-xl bg-gray-50 p-3 text-xs leading-relaxed text-gray-500 dark:bg-gray-800 dark:text-gray-400">
              {t("integrationsUi.formHint")}
            </p>
            <div className="flex justify-end gap-3">
              <Button variant="outline" type="button" onClick={close}>
                {t("integrationsUi.cancel")}
              </Button>
              <Button
                variant="outline"
                type="submit"
                className="!bg-kps-primary !text-white !ring-0 hover:!bg-kps-primary-hover"
              >
                {t(busy ? "integrationsUi.saving" : "integrationsUi.save")}
              </Button>
            </div>
          </fieldset>
        </form>
      ) : (
        <div className="mt-6 space-y-5">
          <dl className="space-y-4">
            {[
              [isDrive ? "folderName" : "channelName", initial.name],
              ...(!isDrive
                ? [
                    [
                      "platform",
                      t(`integrationsUi.platforms.${initial.platform}`),
                    ],
                  ]
                : []),
              ["owner", initial.owner],
              [
                "status",
                t(
                  `integrationsUi.${initial.enabled ? "unverified" : "paused"}`,
                ),
              ],
              ["note", initial.note || t("integrationsUi.noNote")],
            ].map(([label, value]) => (
              <div key={label}>
                <dt className="text-xs text-gray-500 dark:text-gray-400">
                  {t(`integrationsUi.${label}`)}
                </dt>
                <dd className="mt-1 whitespace-pre-wrap break-words text-sm font-medium text-gray-800 dark:text-gray-200">
                  {value}
                </dd>
              </div>
            ))}
          </dl>
          <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <p className="mb-2 text-xs text-gray-500 dark:text-gray-400">
              {t("integrationsUi.url")}
            </p>
            <a
              href={initial.url}
              target="_blank"
              rel="noopener noreferrer"
              className="break-all text-sm text-kps-primary underline"
            >
              {initial.url} ↗
            </a>
          </div>
          <p className="rounded-xl bg-warning-50 p-4 text-sm leading-relaxed text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
            {t(`integrationsUi.${isDrive ? "drivePending" : "socialPending"}`)}
          </p>
          <div className="flex justify-end">
            <Button variant="outline" onClick={close}>
              {t("integrationsUi.close")}
            </Button>
          </div>
        </div>
      )}
    </Modal>
  );
}
