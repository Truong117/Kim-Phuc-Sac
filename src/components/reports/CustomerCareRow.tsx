import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import Input from "@/components/form/input/InputField";
import TextArea from "@/components/form/input/TextArea";
import Select from "@/components/form/Select";
import Label from "@/components/form/Label";
import type { CareCustomer, CareReportEntry, TaskOutput } from "@/types/tasks";
import { ApiServiceError } from "@/services/apiClient";
import {
  downloadCareEvidence,
  uploadCareEvidence,
  getCareCustomers,
} from "@/services/customerCareService";
import { careEntryName, validCareContact } from "@/utils/careReports";

export default function CustomerCareRow({
  entry,
  index,
  customers,
  legacy,
  taskId,
  disabled,
  onChange,
  onBusy,
}: {
  entry: CareReportEntry;
  index: number;
  customers: CareCustomer[];
  legacy: TaskOutput[];
  taskId: number;
  disabled: boolean;
  onChange: (entry: CareReportEntry) => void;
  onBusy: (busy: boolean) => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "customerCare" });
  const [error, setError] = useState("");
  const [matches, setMatches] = useState<{
    contact: string;
    customers: CareCustomer[];
  }>({ contact: "", customers: [] });
  const contact = entry.new_customer?.contact?.trim() ?? "";
  useEffect(() => {
    if (!contact || !validCareContact(contact)) return;
    const controller = new AbortController();
    const timer = setTimeout(() => {
      getCareCustomers(taskId, contact, 1, controller.signal)
        .then((data) => {
          if (!controller.signal.aborted)
            setMatches({ contact, customers: data.data });
        })
        .catch(() => {
          if (!controller.signal.aborted)
            setMatches({ contact, customers: [] });
        });
    }, 350);
    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [contact, taskId]);
  const id = `care-${taskId}-${entry.client_key}`;
  const options = customers.map((c) => ({
    value: String(c.id),
    label: `${c.name} · ${c.contacts.map((x) => x.channel).join(", ")} · ${c.code}`,
  }));
  if (entry.customer_id && !customers.some((c) => c.id === entry.customer_id))
    options.unshift({
      value: String(entry.customer_id),
      label: entry.name || t("customerCode", { id: entry.customer_id }),
    });
  options.push({ value: "new", label: t("newCustomer") });
  function choose(value: string) {
    setError("");
    const customer = customers.find((c) => c.id === Number(value));
    onChange({
      ...entry,
      customer_id: customer?.id,
      name: customer?.name,
      contact_update: undefined,
      new_customer:
        value === "new"
          ? {
              request_key: crypto.randomUUID(),
              name: "",
              channel: "Zalo",
              contact: "",
            }
          : undefined,
    });
  }
  const channels = [
    "Zalo",
    "Messenger · Page KPS",
    "Messenger · Page Spa",
    t("phoneChannel"),
    t("otherChannel"),
  ].map((s) => ({ value: s, label: s }));
  async function upload(file?: File) {
    if (!file) return;
    if (
      file.size > 1024 * 1024 ||
      !["image/jpeg", "image/png", "image/webp", "application/pdf"].includes(
        file.type,
      )
    ) {
      setError(t("fileLimit"));
      return;
    }
    setError("");
    onBusy(true);
    try {
      const { data } = await uploadCareEvidence(taskId, file);
      onChange({
        ...entry,
        attachment_ids: [...entry.attachment_ids, data.id],
        attachment_names: { ...entry.attachment_names, [data.id]: data.name },
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
    <details
      open
      className="rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900"
    >
      <summary className="flex cursor-pointer flex-wrap items-center gap-3 p-4 text-theme-sm text-gray-900 dark:text-white">
        <span className="flex size-8 items-center justify-center rounded-lg bg-kps-primary/10 text-kps-primary dark:text-sidebar-selected">
          {index + 1}
        </span>
        <span className="flex-1 font-medium">
          {careEntryName(entry) || t("customerNumber", { number: index + 1 })}
        </span>
        <span className="text-theme-xs text-gray-500 dark:text-gray-400">
          {t("activityCount", { count: entry.activities.length })}
        </span>
      </summary>
      <div className="space-y-4 border-t border-gray-100 p-4 dark:border-gray-800">
        <div>
          <Label htmlFor={`${id}-customer`}>{t("customer")}</Label>
          <Select
            id={`${id}-customer`}
            value={entry.new_customer ? "new" : String(entry.customer_id ?? "")}
            options={options}
            placeholder={t("chooseCustomer")}
            disabled={disabled}
            onChange={choose}
          />
        </div>
        {entry.new_customer && (
          <div className="space-y-3 rounded-lg bg-kps-primary/5 p-3 dark:bg-kps-primary/10">
            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <Label htmlFor={`${id}-name`}>{t("name")}</Label>
                <Input
                  id={`${id}-name`}
                  maxLength={200}
                  value={entry.new_customer.name}
                  disabled={disabled}
                  onChange={(e) =>
                    onChange({
                      ...entry,
                      new_customer: {
                        ...entry.new_customer!,
                        name: e.target.value,
                      },
                    })
                  }
                />
              </div>
              <div>
                <Label htmlFor={`${id}-channel`}>{t("channel")}</Label>
                <Input
                  id={`${id}-channel`}
                  list={`${id}-channels`}
                  maxLength={100}
                  value={entry.new_customer.channel}
                  disabled={disabled}
                  onChange={(e) =>
                    onChange({
                      ...entry,
                      new_customer: {
                        ...entry.new_customer!,
                        channel: e.target.value,
                      },
                    })
                  }
                />
                <datalist id={`${id}-channels`}>
                  {channels.map((c) => (
                    <option key={c.value} value={c.value} />
                  ))}
                </datalist>
              </div>
            </div>
            {contact &&
              matches.contact === contact &&
              matches.customers.length > 0 && (
                <div className="space-y-2 text-theme-xs text-warning-700 dark:text-warning-300">
                  <p>{t("existingContact")}</p>
                  {matches.customers.slice(0, 3).map((c) => (
                    <Button
                      key={c.id}
                      type="button"
                      size="sm"
                      variant="outline"
                      disabled={disabled}
                      onClick={() =>
                        onChange({
                          ...entry,
                          customer_id: c.id,
                          name: c.name,
                          new_customer: undefined,
                        })
                      }
                    >
                      {t("useExisting", { name: c.name })}
                    </Button>
                  ))}
                </div>
              )}
            <div>
              <Label htmlFor={`${id}-contact`}>{t("contact")}</Label>
              <Input
                id={`${id}-contact`}
                maxLength={2048}
                value={entry.new_customer.contact ?? ""}
                placeholder={t("contactPlaceholder")}
                disabled={disabled}
                onChange={(e) =>
                  onChange({
                    ...entry,
                    new_customer: {
                      ...entry.new_customer!,
                      contact: e.target.value,
                    },
                  })
                }
              />
            </div>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              {t("automaticCode")}
            </p>
          </div>
        )}
        {entry.customer_id && (
          <>
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              {customers
                .find((c) => c.id === entry.customer_id)
                ?.contacts.map(
                  (c) => `${c.channel}${c.value ? `: ${c.value}` : ""}`,
                )
                .join(" · ")}
            </p>
            <details>
              <summary className="cursor-pointer text-theme-xs text-kps-primary dark:text-sidebar-selected">
                {t("addContact")}
              </summary>
              <div className="mt-3 grid gap-3 sm:grid-cols-2">
                <div>
                  <Label htmlFor={`${id}-extra-channel`}>{t("channel")}</Label>
                  <Input
                    id={`${id}-extra-channel`}
                    maxLength={100}
                    value={entry.contact_update?.channel ?? ""}
                    disabled={disabled}
                    onChange={(e) =>
                      onChange({
                        ...entry,
                        contact_update: {
                          value: entry.contact_update?.value ?? "",
                          channel: e.target.value,
                        },
                      })
                    }
                  />
                </div>
                <div>
                  <Label htmlFor={`${id}-extra-contact`}>{t("contact")}</Label>
                  <Input
                    id={`${id}-extra-contact`}
                    maxLength={2048}
                    value={entry.contact_update?.value ?? ""}
                    disabled={disabled}
                    onChange={(e) =>
                      onChange({
                        ...entry,
                        contact_update: {
                          channel: entry.contact_update?.channel ?? "",
                          value: e.target.value,
                        },
                      })
                    }
                  />
                </div>
              </div>
            </details>
          </>
        )}
        {legacy.length > 0 && (
          <div>
            <Label htmlFor={`${id}-legacy`}>{t("legacy")}</Label>
            <Select
              id={`${id}-legacy`}
              value={String(entry.legacy_output_id ?? "")}
              allowClear
              placeholder={t("noLegacy")}
              disabled={disabled}
              options={legacy.map((o) => ({
                value: String(o.id),
                label: `${o.name} · ${o.reference}`,
              }))}
              onChange={(v) =>
                onChange({
                  ...entry,
                  legacy_output_id: v ? Number(v) : undefined,
                })
              }
            />
          </div>
        )}
        {entry.activities.map((activity, i) => (
          <div
            key={i}
            className="space-y-3 border-t border-gray-100 pt-3 dark:border-gray-800"
          >
            <div className="flex items-center justify-between gap-2">
              <span className="text-theme-xs text-gray-500 dark:text-gray-400">
                {t("activityNumber", { number: i + 1 })}
              </span>
              {i > 0 && (
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={disabled}
                  onClick={() =>
                    onChange({
                      ...entry,
                      activities: entry.activities.filter((_, n) => n !== i),
                    })
                  }
                >
                  {t("removeActivity")}
                </Button>
              )}
            </div>
            <div>
              <Label htmlFor={`${id}-service-${i}`}>{t("service")}</Label>
              <Input
                id={`${id}-service-${i}`}
                list={`${id}-services`}
                maxLength={200}
                value={activity.service}
                disabled={disabled}
                onChange={(e) =>
                  onChange({
                    ...entry,
                    activities: entry.activities.map((a, n) =>
                      n === i ? { ...a, service: e.target.value } : a,
                    ),
                  })
                }
              />
            </div>
            <div>
              <Label htmlFor={`${id}-result-${i}`}>{t("result")}</Label>
              <TextArea
                id={`${id}-result-${i}`}
                rows={2}
                value={activity.result}
                disabled={disabled}
                onChange={(result) =>
                  onChange({
                    ...entry,
                    activities: entry.activities.map((a, n) =>
                      n === i ? { ...a, result } : a,
                    ),
                  })
                }
              />
            </div>
          </div>
        ))}
        <datalist id={`${id}-services`}>
          {["homeCare", "productAdvice", "followUp", "feedback"].map((key) => (
            <option key={key} value={t(`services.${key}`)} />
          ))}
        </datalist>
        <Button
          type="button"
          size="sm"
          variant="outline"
          disabled={disabled || entry.activities.length >= 10}
          onClick={() =>
            onChange({
              ...entry,
              activities: [...entry.activities, { service: "", result: "" }],
            })
          }
        >
          {t("addActivity")}
        </Button>
        <details className="border-t border-gray-100 pt-3 dark:border-gray-800">
          <summary className="cursor-pointer text-theme-xs text-kps-primary dark:text-sidebar-selected">
            {t("evidence")}
          </summary>
          <div className="mt-3 space-y-3">
            <div>
              <Label htmlFor={`${id}-proof`}>{t("proofLinks")}</Label>
              <TextArea
                id={`${id}-proof`}
                rows={2}
                value={entry.evidence_links.join("\n")}
                disabled={disabled}
                onChange={(text) =>
                  onChange({
                    ...entry,
                    evidence_links: text.split(/\r?\n/).map((x) => x.trim()),
                  })
                }
              />
            </div>
            <div>
              <Label htmlFor={`${id}-file`}>{t("file")}</Label>
              <Input
                id={`${id}-file`}
                type="file"
                accept="image/jpeg,image/png,image/webp,application/pdf"
                disabled={disabled || entry.attachment_ids.length >= 3}
                onChange={(e) => {
                  void upload(e.target.files?.[0]);
                  e.target.value = "";
                }}
                hint={t("fileLimit")}
              />
            </div>
            {entry.attachment_ids.map((fileId) => (
              <div
                key={fileId}
                className="flex flex-wrap items-center gap-2 text-theme-xs text-gray-600 dark:text-gray-300"
              >
                <button
                  type="button"
                  disabled={disabled}
                  className="text-kps-primary underline dark:text-sidebar-selected"
                  onClick={() => {
                    onBusy(true);
                    void downloadCareEvidence(
                      taskId,
                      fileId,
                      entry.attachment_names?.[fileId] || `evidence-${fileId}`,
                    )
                      .catch(() => setError(t("downloadError")))
                      .finally(() => onBusy(false));
                  }}
                >
                  {entry.attachment_names?.[fileId] ||
                    t("fileId", { id: fileId })}
                </button>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={disabled}
                  onClick={() =>
                    onChange({
                      ...entry,
                      attachment_ids: entry.attachment_ids.filter(
                        (x) => x !== fileId,
                      ),
                    })
                  }
                >
                  {t("removeFile")}
                </Button>
              </div>
            ))}
          </div>
        </details>
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
