import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import Select from "@/components/form/Select";
import { CloseIcon, PlusIcon, SearchIcon } from "@/icons";
import {
  getCareCustomers,
  type CareCustomerPage,
} from "@/services/customerCareService";
import {
  careEntryIdentity,
  careEntryName,
  careContactIdentity,
  validCareContact,
} from "@/utils/careReports";
import type {
  CareCustomer,
  CareCustomerSelection,
  TaskOutput,
} from "@/types/tasks";

type Choice = {
  key: string;
  name: string;
  detail: string;
  selection: CareCustomerSelection;
};
export default function CareWorkCustomers({
  taskId,
  workKey,
  value,
  drafts,
  legacy,
  disabled,
  onChange,
  onBusy,
}: {
  taskId: number;
  workKey: string;
  value: CareCustomerSelection[];
  drafts: CareCustomerSelection[];
  legacy: TaskOutput[];
  disabled: boolean;
  onChange: (customers: CareCustomerSelection[]) => void;
  onBusy: (busy: boolean) => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "customerCare" });
  const [open, setOpen] = useState(false),
    [newOpen, setNewOpen] = useState(false),
    [search, setSearch] = useState(""),
    [query, setQuery] = useState({ search: "", page: 1 });
  const [directory, setDirectory] = useState<CareCustomerPage | null>(null),
    [loadError, setLoadError] = useState(false),
    [error, setError] = useState("");
  const [name, setName] = useState(""),
    [channel, setChannel] = useState(""),
    [other, setOther] = useState(""),
    [contact, setContact] = useState(""),
    [matches, setMatches] = useState<CareCustomer[]>([]);
  const id = `care-work-customers-${workKey}`;
  useEffect(() => {
    if (!open) return;
    const controller = new AbortController();
    getCareCustomers(taskId, query.search, query.page, controller.signal)
      .then((d) => {
        if (!controller.signal.aborted) {
          setDirectory(d);
          setLoadError(false);
        }
      })
      .catch(() => {
        if (!controller.signal.aborted) setLoadError(true);
      });
    return () => controller.abort();
  }, [open, query, taskId]);
  const candidates = new Map<string, Choice>();
  for (const c of [...drafts, ...value]) {
    const key = careEntryIdentity(c);
    if (key)
      candidates.set(key, {
        key,
        name: careEntryName(c),
        detail: c.new_customer?.channel ?? "",
        selection: c,
      });
  }
  for (const c of directory?.data ?? [])
    candidates.set(`KH-${c.id}`, {
      key: `KH-${c.id}`,
      name: c.name,
      detail: c.contacts.map((v) => v.channel).join(" · "),
      selection: {
        client_key: `customer-${c.id}`,
        customer_id: c.id,
        name: c.name,
      },
    });
  const choices = [...candidates.values()].filter(
    (c) =>
      !query.search ||
      careEntryName(c.selection)
        .toLocaleLowerCase("vi")
        .includes(query.search.toLocaleLowerCase("vi")) ||
      (directory?.data ?? []).some((d) => `KH-${d.id}` === c.key),
  );
  const selected = new Set(value.map(careEntryIdentity));
  function choose(c: Choice, checked: boolean) {
    setError("");
    onChange(
      checked
        ? [
            ...value,
            {
              client_key: crypto.randomUUID(),
              ...(c.selection.customer_id
                ? { customer_id: c.selection.customer_id, name: c.name }
                : {}),
              ...(c.selection.new_customer
                ? { new_customer: { ...c.selection.new_customer } }
                : {}),
            },
          ]
        : value.filter((v) => careEntryIdentity(v) !== c.key),
    );
  }
  function pickKnown(c: CareCustomer) {
    if (!selected.has(`KH-${c.id}`))
      onChange([
        ...value,
        { client_key: crypto.randomUUID(), customer_id: c.id, name: c.name },
      ]);
    setNewOpen(false);
    setMatches([]);
    setError("");
  }
  async function create() {
    const source =
      channel === "other"
        ? other.trim()
        : channel === "phone"
          ? t("phoneChannel")
          : channel;
    if (!name.trim() || !source || !validCareContact(contact)) {
      setError(t("works.newCustomerRequired"));
      return;
    }
    const duplicate = contact.trim()
      ? drafts.find(
          (c) =>
            c.new_customer?.contact &&
            careContactIdentity(c.new_customer.contact) ===
              careContactIdentity(contact),
        )
      : undefined;
    if (duplicate) {
      const key = careEntryIdentity(duplicate);
      if (!selected.has(key))
        choose(
          {
            key,
            name: careEntryName(duplicate),
            detail: duplicate.new_customer!.channel,
            selection: duplicate,
          },
          true,
        );
      setNewOpen(false);
      return;
    }
    setError("");
    setMatches([]);
    onBusy(true);
    try {
      if (contact.trim()) {
        const data = await getCareCustomers(taskId, contact.trim(), 1);
        const found = data.data.filter((c) =>
          c.contacts.some(
            (v) =>
              v.value &&
              careContactIdentity(v.value) === careContactIdentity(contact),
          ),
        );
        if (found.length) {
          setMatches(found);
          setError(t("existingContact"));
          return;
        }
      }
      onChange([
        ...value,
        {
          client_key: crypto.randomUUID(),
          new_customer: {
            request_key: crypto.randomUUID(),
            name: name.trim(),
            channel: source,
            contact: contact.trim(),
          },
        },
      ]);
      setName("");
      setChannel("");
      setOther("");
      setContact("");
      setNewOpen(false);
    } catch {
      setError(t("loadError"));
    } finally {
      onBusy(false);
    }
  }
  const sources = [
    { value: "Zalo", label: "Zalo" },
    { value: "Messenger · Page KPS", label: "Messenger · Page KPS" },
    { value: "Messenger · Page Spa", label: "Messenger · Page Spa" },
    { value: "phone", label: t("phoneChannel") },
    { value: "other", label: t("otherChannel") },
  ];
  return (
    <section className="min-w-0 space-y-3" aria-labelledby={`${id}-label`}>
      <div className="flex min-h-8 items-center justify-between gap-2">
        <h4
          id={`${id}-label`}
          className="font-medium text-gray-800 dark:text-white/90"
        >
          {t("works.customers")}
        </h4>
        <span className="rounded-md bg-brand-50 px-2 py-0.5 text-theme-xs text-kps-primary dark:bg-kps-primary/15 dark:text-brand-200">
          {value.length}
        </span>
      </div>
      <div className="space-y-2">
        {value.map((c) => (
          <div
            key={careEntryIdentity(c)}
            className="flex items-center gap-3 rounded-lg bg-gray-50 px-3 py-2 text-theme-sm text-gray-700 dark:bg-gray-800/70 dark:text-gray-200"
          >
            <span
              aria-hidden="true"
              className="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-50 text-theme-xs text-kps-primary dark:bg-kps-primary/20 dark:text-brand-200"
            >
              {careEntryName(c)
                .trim()
                .split(/\s+/)
                .at(-1)
                ?.slice(0, 1)
                .toLocaleUpperCase("vi")}
            </span>
            <span className="min-w-0 flex-1 break-words">
              <span className="block">{careEntryName(c)}</span>
              <span className="block text-theme-xs text-gray-500 dark:text-gray-400">
                {c.customer_id
                  ? `KH-${c.customer_id}`
                  : t("works.newCustomerTag")}
                {candidates.get(careEntryIdentity(c))?.detail
                  ? ` · ${candidates.get(careEntryIdentity(c))!.detail}`
                  : ""}
              </span>
            </span>
            <button
              type="button"
              disabled={disabled}
              aria-label={t("works.removeCustomer", { name: careEntryName(c) })}
              className="flex size-11 shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-brand-50 disabled:opacity-50 dark:text-gray-400 dark:hover:bg-gray-700"
              onClick={() =>
                onChange(
                  value.filter(
                    (v) => careEntryIdentity(v) !== careEntryIdentity(c),
                  ),
                )
              }
            >
              <CloseIcon className="size-4" aria-hidden="true" />
            </button>
          </div>
        ))}
        {!value.length && (
          <p className="py-2 text-theme-xs text-gray-500 dark:text-gray-400">
            {t("works.customerEmpty")}
          </p>
        )}
      </div>
      <div>
        <button
          type="button"
          disabled={disabled}
          aria-expanded={open}
          aria-controls={`${id}-menu`}
          className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-theme-sm font-medium text-gray-700 hover:bg-brand-50 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 dark:hover:bg-gray-700"
          onClick={() => setOpen((v) => !v)}
        >
          <PlusIcon className="size-4" aria-hidden="true" />
          {t("works.chooseCustomers")}
        </button>
      </div>
      <p className="text-theme-xs text-gray-500 dark:text-gray-400">
        {t("works.customerScope")}
      </p>
      {open && (
        <div
          id={`${id}-menu`}
          className="space-y-3 rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900"
        >
          <div>
            <Label htmlFor={`${id}-search`}>{t("search")}</Label>
            <div className="flex items-center gap-2">
              <div className="min-w-0 flex-1">
                <Input
                  id={`${id}-search`}
                  maxLength={200}
                  value={search}
                  disabled={disabled}
                  placeholder={t("searchPlaceholder")}
                  onChange={(e) => setSearch(e.target.value)}
                  onKeyDown={(e) => {
                    if (e.key === "Enter") {
                      e.preventDefault();
                      setQuery({ search: search.trim(), page: 1 });
                    }
                  }}
                />
              </div>
              <button
                type="button"
                aria-label={t("searchButton")}
                disabled={disabled}
                onClick={() => setQuery({ search: search.trim(), page: 1 })}
                className="flex size-11 shrink-0 items-center justify-center rounded-lg border border-gray-300 text-kps-primary hover:bg-brand-50 disabled:opacity-50 dark:border-gray-700 dark:text-brand-200 dark:hover:bg-gray-800"
              >
                <SearchIcon className="size-4" aria-hidden="true" />
              </button>
            </div>
          </div>
          {loadError && (
            <p
              role="alert"
              className="text-theme-xs text-error-600 dark:text-error-400"
            >
              {t("loadError")}
            </p>
          )}
          {!directory && !loadError && (
            <p
              role="status"
              className="text-theme-xs text-gray-500 dark:text-gray-400"
            >
              {t("works.customerLoading")}
            </p>
          )}
          {directory && !choices.length && !loadError && (
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              {t("works.customerNoResults")}
            </p>
          )}
          <div className="custom-scrollbar max-h-60 space-y-1 overflow-y-auto">
            {choices.map((c) => (
              <label
                key={c.key}
                className={`flex min-h-11 items-center gap-3 rounded-lg px-2 py-2 text-theme-sm text-gray-700 dark:text-gray-200 ${selected.has(c.key) ? "bg-brand-50 dark:bg-kps-primary/15" : "hover:bg-gray-50 dark:hover:bg-gray-800"}`}
              >
                <input
                  type="checkbox"
                  className="size-4 accent-kps-primary"
                  checked={selected.has(c.key)}
                  disabled={
                    disabled || (!selected.has(c.key) && value.length >= 20)
                  }
                  onChange={(e) => choose(c, e.target.checked)}
                />
                <span className="min-w-0 flex-1">
                  <span className="break-words">
                    {c.selection.customer_id
                      ? `KH-${c.selection.customer_id} · `
                      : ""}
                    {c.name}
                  </span>
                  <span className="block text-theme-xs text-gray-500 dark:text-gray-400">
                    {c.detail}
                  </span>
                </span>
              </label>
            ))}
          </div>
          {directory && directory.meta.last_page > 1 && (
            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={disabled || query.page <= 1}
                onClick={() => setQuery({ ...query, page: query.page - 1 })}
              >
                {t("previous")}
              </Button>
              <span className="text-theme-xs text-gray-600 dark:text-gray-300">
                {directory.meta.current_page}/{directory.meta.last_page}
              </span>
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={disabled || query.page >= directory.meta.last_page}
                onClick={() => setQuery({ ...query, page: query.page + 1 })}
              >
                {t("next")}
              </Button>
            </div>
          )}
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={disabled || value.length >= 20}
            onClick={() => {
              setNewOpen((v) => !v);
              setError("");
              setMatches([]);
            }}
          >
            {t("works.newCustomerAction")}
          </Button>
          {newOpen && (
            <div className="space-y-3 rounded-lg bg-brand-50 p-3 dark:bg-kps-primary/10">
              <div className="grid gap-3">
                <div>
                  <Label htmlFor={`${id}-new-name`}>{t("name")}</Label>
                  <Input
                    id={`${id}-new-name`}
                    maxLength={200}
                    disabled={disabled}
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                  />
                </div>
                <div>
                  <Label htmlFor={`${id}-new-source`}>{t("channel")}</Label>
                  <Select
                    id={`${id}-new-source`}
                    options={sources}
                    placeholder={t("works.chooseSource")}
                    value={channel}
                    disabled={disabled}
                    onChange={setChannel}
                  />
                </div>
              </div>
              {channel === "other" && (
                <div>
                  <Label htmlFor={`${id}-other`}>
                    {t("works.otherSource")}
                  </Label>
                  <Input
                    id={`${id}-other`}
                    maxLength={100}
                    value={other}
                    disabled={disabled}
                    onChange={(e) => setOther(e.target.value)}
                  />
                </div>
              )}
              <div>
                <Label htmlFor={`${id}-new-contact`}>{t("contact")}</Label>
                <Input
                  id={`${id}-new-contact`}
                  maxLength={2048}
                  value={contact}
                  disabled={disabled}
                  onChange={(e) => setContact(e.target.value)}
                  placeholder={t("contactPlaceholder")}
                />
              </div>
              <p className="text-theme-xs text-gray-500 dark:text-gray-400">
                {t("automaticCode")}
              </p>
              {error && (
                <p
                  role="alert"
                  className="text-theme-xs text-error-600 dark:text-error-400"
                >
                  {error}
                </p>
              )}
              {matches.map((c) => (
                <Button
                  key={c.id}
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={disabled}
                  onClick={() => pickKnown(c)}
                >
                  {t("useExisting", { name: c.name })}
                </Button>
              ))}
              <Button
                type="button"
                size="sm"
                disabled={disabled}
                onClick={() => void create()}
              >
                {t("works.createAndSelect")}
              </Button>
            </div>
          )}
          <div>
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={disabled}
              onClick={() => setOpen(false)}
            >
              {t("works.closeCustomers")}
            </Button>
          </div>
        </div>
      )}
      {value.length > 0 && (
        <details className="border-t border-gray-200 pt-3 dark:border-gray-700">
          <summary className="cursor-pointer text-theme-xs text-kps-primary dark:text-brand-200">
            {t("works.customerNotes")}
          </summary>
          <div className="mt-3 space-y-4">
            {value.map((c) => {
              const field = `care-note-${workKey}-${c.client_key}`;
              const change = (patch: Partial<CareCustomerSelection>) =>
                onChange(
                  value.map((v) =>
                    v.client_key === c.client_key ? { ...c, ...patch } : v,
                  ),
                );
              return (
                <div key={c.client_key} className="space-y-3">
                  <div>
                    <Label htmlFor={field}>{careEntryName(c)}</Label>
                    <Input
                      id={field}
                      maxLength={300}
                      disabled={disabled}
                      value={c.note ?? ""}
                      placeholder={t("works.notePlaceholder")}
                      onChange={(e) => change({ note: e.target.value })}
                    />
                  </div>
                  {legacy.length > 0 && (
                    <div>
                      <Label htmlFor={`${field}-legacy`}>{t("legacy")}</Label>
                      <Select
                        id={`${field}-legacy`}
                        value={String(c.legacy_output_id ?? "")}
                        placeholder={t("noLegacy")}
                        allowClear
                        disabled={disabled}
                        options={legacy.map((o) => ({
                          value: String(o.id),
                          label: `${o.name} · ${o.reference}`,
                        }))}
                        onChange={(v) =>
                          change({
                            legacy_output_id: v ? Number(v) : undefined,
                          })
                        }
                      />
                    </div>
                  )}
                  {c.customer_id && (
                    <details>
                      <summary className="cursor-pointer text-theme-xs text-kps-primary dark:text-brand-200">
                        {t("addContact")}
                      </summary>
                      <div className="mt-3 grid gap-3 sm:grid-cols-2">
                        <div>
                          <Label htmlFor={`${field}-channel`}>
                            {t("channel")}
                          </Label>
                          <Input
                            id={`${field}-channel`}
                            maxLength={100}
                            disabled={disabled}
                            value={c.contact_update?.channel ?? ""}
                            onChange={(e) =>
                              change({
                                contact_update: {
                                  channel: e.target.value,
                                  value: c.contact_update?.value ?? "",
                                },
                              })
                            }
                          />
                        </div>
                        <div>
                          <Label htmlFor={`${field}-contact`}>
                            {t("contact")}
                          </Label>
                          <Input
                            id={`${field}-contact`}
                            maxLength={2048}
                            disabled={disabled}
                            value={c.contact_update?.value ?? ""}
                            onChange={(e) =>
                              change({
                                contact_update: {
                                  channel: c.contact_update?.channel ?? "",
                                  value: e.target.value,
                                },
                              })
                            }
                          />
                        </div>
                      </div>
                    </details>
                  )}
                </div>
              );
            })}
          </div>
        </details>
      )}
    </section>
  );
}
