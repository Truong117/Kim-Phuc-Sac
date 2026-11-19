import { useEffect, useRef, useState, type FormEvent } from "react";
import { useTranslation } from "react-i18next";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import Input from "@/components/form/input/InputField";
import TextArea from "@/components/form/input/TextArea";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import { ApiServiceError } from "@/services/apiClient";
import {
  crmChannels,
  getCrmAssignees,
  type CrmFormValues,
  type CrmOpportunity,
  type CrmPerson,
  type CrmStage,
} from "@/services/crmService";
import type { CrmAction } from "@/components/crm/CrmCustomerDetails";
import { isDateOnlySelectable } from "@/utils/dateOnly";

const transitions: Record<CrmStage, CrmStage[]> = {
  NEW: ["CONSULTING", "LOST"],
  CONSULTING: ["WAITING", "WON", "LOST"],
  WAITING: ["CONSULTING", "WON", "LOST"],
  WON: [],
  LOST: [],
};

export default function CrmForm({
  action,
  opportunity,
  today,
  onClose,
  onSave,
}: {
  action: CrmAction;
  opportunity?: CrmOpportunity;
  today: string;
  onClose: () => void;
  onSave: (values: CrmFormValues, requestKey: string) => Promise<void>;
}) {
  const { t } = useTranslation("common", { keyPrefix: "crm" });
  const [form, setForm] = useState<CrmFormValues>({
    name: "",
    channel: "",
    contact: "",
    title: "",
    result: "",
    services: "",
    dueOn: "",
    membership: "",
    stage: "",
    resolution: "",
  });
  const [assigneeQuery, setAssigneeQuery] = useState("");
  const [assignees, setAssignees] = useState<CrmPerson[]>([]);
  const [error, setError] = useState("");
  const [dueDateError, setDueDateError] = useState(false);
  const [busy, setBusy] = useState(false);
  const operation = useRef<{ key: string; contents: string } | null>(null);
  const pending = useRef(false);

  const change = <Field extends keyof CrmFormValues>(
    field: Field,
    value: CrmFormValues[Field],
  ) => setForm((current) => ({ ...current, [field]: value }));

  useEffect(() => {
    if (action !== "assignment") return;
    const controller = new AbortController();
    getCrmAssignees(assigneeQuery, controller.signal)
      .then(setAssignees)
      .catch((failure: unknown) => {
        if (!controller.signal.aborted)
          setError(
            failure instanceof ApiServiceError && failure.status > 0
              ? failure.message
              : t("loadError"),
          );
      });
    return () => controller.abort();
  }, [action, assigneeQuery, t]);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (pending.current) return;
    if (
      action === "FOLLOW_UP" &&
      !isDateOnlySelectable(form.dueOn, { minDate: today })
    ) {
      setDueDateError(true);
      event.currentTarget.querySelector<HTMLButtonElement>("#crm-due")?.focus();
      return;
    }
    const contents = JSON.stringify(form);
    if (operation.current?.contents !== contents) {
      operation.current = { key: crypto.randomUUID(), contents };
    }
    pending.current = true;
    setBusy(true);
    setError("");
    try {
      await onSave(form, operation.current.key);
    } catch (failure) {
      setError(
        failure instanceof ApiServiceError && failure.status > 0
          ? failure.message
          : t("saveError"),
      );
    } finally {
      pending.current = false;
      setBusy(false);
    }
  };

  const close = () => {
    if (!pending.current) onClose();
  };

  return (
    <Modal
      isOpen
      onClose={close}
      ariaLabelledBy="crm-form-title"
      className="max-w-lg p-5 kps-ai-theme"
    >
      <h2
        id="crm-form-title"
        className="pe-12 text-xl font-semibold text-gray-900 dark:text-white"
      >
        {t("actions." + action)}
      </h2>
      <form className="mt-5 space-y-4" onSubmit={(event) => void submit(event)}>
        {action === "new" && (
          <>
            <div>
              <Label htmlFor="crm-name">{t("name")}</Label>
              <Input
                id="crm-name"
                autoComplete="off"
                required
                maxLength={200}
                value={form.name}
                onChange={(event) => change("name", event.target.value)}
              />
            </div>
            <div>
              <Label htmlFor="crm-channel">{t("source")}</Label>
              <Select
                id="crm-channel"
                required
                options={crmChannels.map((channel) => ({
                  value: channel,
                  label: t("channels." + channel),
                }))}
                placeholder={t("choose")}
                value={form.channel}
                onChange={(value) =>
                  change("channel", value as CrmFormValues["channel"])
                }
              />
            </div>
            <div>
              <Label htmlFor="crm-contact">{t("contact")}</Label>
              <Input
                id="crm-contact"
                autoComplete="off"
                placeholder={t("contactHint")}
                maxLength={2048}
                value={form.contact}
                onChange={(event) => change("contact", event.target.value)}
              />
            </div>
          </>
        )}
        {(action === "OPPORTUNITY" || action === "FOLLOW_UP") && (
          <div>
            <Label htmlFor="crm-title">
              {t(action === "OPPORTUNITY" ? "need" : "nextAction")}
            </Label>
            <Input
              id="crm-title"
              required
              maxLength={200}
              value={form.title}
              onChange={(event) => change("title", event.target.value)}
            />
          </div>
        )}
        {action === "FOLLOW_UP" && (
          <div>
            <Label htmlFor="crm-due">{t("due")}</Label>
            <KpsDatePicker
              id="crm-due"
              value={form.dueOn}
              onChange={(date) => {
                change("dueOn", date);
                setDueDateError(false);
              }}
              ariaLabel={t("due")}
              placeholder={t("duePlaceholder")}
              minDate={today}
              preferredDate={today}
              required
              disabled={busy}
              error={dueDateError}
              hint={dueDateError ? t("dueError") : undefined}
            />
          </div>
        )}
        {action === "CONSULTATION" && (
          <>
            <div>
              <Label htmlFor="crm-services">{t("services")}</Label>
              <TextArea
                id="crm-services"
                required
                maxLength={2100}
                placeholder={t("servicesHint")}
                value={form.services}
                onChange={(value) => change("services", value)}
              />
            </div>
            <div>
              <Label htmlFor="crm-result">{t("result")}</Label>
              <TextArea
                id="crm-result"
                required
                maxLength={1000}
                value={form.result}
                onChange={(value) => change("result", value)}
              />
            </div>
          </>
        )}
        {action === "assignment" && (
          <>
            <div>
              <Label htmlFor="crm-assignee-search">{t("assigneeSearch")}</Label>
              <Input
                id="crm-assignee-search"
                value={assigneeQuery}
                maxLength={100}
                onChange={(event) => setAssigneeQuery(event.target.value)}
              />
            </div>
            <div>
              <Label htmlFor="crm-assignee">{t("owner")}</Label>
              <Select
                id="crm-assignee"
                required
                placeholder={t("choose")}
                options={assignees.map((person) => ({
                  value: String(person.id),
                  label: person.name,
                }))}
                value={form.membership}
                onChange={(value) => change("membership", value)}
              />
            </div>
            <div>
              <Label htmlFor="crm-reason">{t("handoffReason")}</Label>
              <TextArea
                id="crm-reason"
                required
                maxLength={300}
                value={form.result}
                onChange={(value) => change("result", value)}
              />
            </div>
          </>
        )}
        {action === "stage" && opportunity && (
          <>
            <div>
              <Label htmlFor="crm-stage">{t("stage")}</Label>
              <Select
                id="crm-stage"
                required
                placeholder={t("choose")}
                options={transitions[opportunity.stage].map((stage) => ({
                  value: stage,
                  label: t("stages." + stage),
                }))}
                value={form.stage}
                onChange={(value) => change("stage", value as CrmStage)}
              />
            </div>
            {(form.stage === "WON" || form.stage === "LOST") && (
              <div>
                <Label htmlFor="crm-resolution">
                  {t(form.stage === "WON" ? "orderReference" : "lostReason")}
                </Label>
                <Input
                  id="crm-resolution"
                  required
                  maxLength={200}
                  value={form.resolution}
                  onChange={(event) => change("resolution", event.target.value)}
                />
              </div>
            )}
          </>
        )}
        {error && (
          <p
            role="alert"
            className="text-sm text-error-600 dark:text-error-300"
          >
            {error}
          </p>
        )}
        <div className="flex justify-end gap-2">
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={busy}
            onClick={close}
          >
            {t("cancel")}
          </Button>
          <Button type="submit" size="sm" disabled={busy}>
            {t(busy ? "saving" : "save")}
          </Button>
        </div>
      </form>
    </Modal>
  );
}
