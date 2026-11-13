import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import {
  getCareServices,
  type CareServicePage,
} from "@/services/customerCareService";
import { careServiceIdentity } from "@/utils/careReports";
import type { CareServiceOption } from "@/types/tasks";

export default function CareServicePicker({
  taskId,
  workKey,
  value,
  known,
  disabled,
  onChange,
}: {
  taskId: number;
  workKey: string;
  value: CareServiceOption[];
  known: CareServiceOption[];
  disabled: boolean;
  onChange: (services: CareServiceOption[]) => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "customerCare.works" });
  const [open, setOpen] = useState(false),
    [search, setSearch] = useState(""),
    [query, setQuery] = useState({ search: "", page: 1 });
  const [data, setData] = useState<CareServicePage | null>(null),
    [error, setError] = useState(false),
    [notice, setNotice] = useState("");
  const id = `care-service-${workKey}`;
  useEffect(() => {
    if (!open) return;
    const controller = new AbortController();
    getCareServices(taskId, query.search, query.page, controller.signal)
      .then((d) => {
        if (!controller.signal.aborted) {
          setData(d);
          setError(false);
        }
      })
      .catch(() => {
        if (!controller.signal.aborted) setError(true);
      });
    return () => controller.abort();
  }, [open, taskId, query]);
  const options = [
    ...new Map(
      [...known, ...value, ...(data?.data ?? [])].map((s) => [
        careServiceIdentity(s.name),
        s,
      ]),
    ).values(),
  ].filter(
    (s) =>
      !query.search ||
      careServiceIdentity(s.name).includes(careServiceIdentity(query.search)),
  );
  const selected = new Set(value.map((s) => careServiceIdentity(s.name)));
  function choose(s: CareServiceOption, checked: boolean) {
    setNotice("");
    onChange(
      checked
        ? [...value, s]
        : value.filter(
            (v) => careServiceIdentity(v.name) !== careServiceIdentity(s.name),
          ),
    );
  }
  function create() {
    const name = search.trim();
    if (!name || name.length > 200) {
      setNotice(t("serviceRequired"));
      return;
    }
    const key = careServiceIdentity(name),
      existing = options.find((s) => careServiceIdentity(s.name) === key);
    if (!selected.has(key)) onChange([...value, existing ?? { name }]);
    setNotice(t(existing ? "serviceReused" : "serviceDraft"));
  }
  return (
    <div className="space-y-3">
      <div>
        <Label htmlFor={`${id}-toggle`}>{t("services")}</Label>
        <button
          id={`${id}-toggle`}
          type="button"
          disabled={disabled}
          aria-expanded={open}
          aria-controls={`${id}-menu`}
          onClick={() => setOpen((v) => !v)}
          className="flex w-full items-center justify-between gap-3 rounded-lg border border-gray-300 bg-white px-4 py-3 text-start text-theme-sm text-gray-800 disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
        >
          <span>
            {t(value.length ? "selectedServices" : "chooseServices", {
              count: value.length,
            })}
          </span>
          <span aria-hidden="true">⌄</span>
        </button>
      </div>
      {value.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {value.map((s) => (
            <span
              key={careServiceIdentity(s.name)}
              className="inline-flex items-center gap-2 rounded-lg bg-kps-primary/10 px-3 py-2 text-theme-xs text-gray-800 dark:text-gray-200"
            >
              <span>{s.name}</span>
              <button
                type="button"
                disabled={disabled}
                aria-label={t("removeService", { name: s.name })}
                onClick={() => choose(s, false)}
                className="flex size-6 items-center justify-center text-kps-primary disabled:opacity-50 dark:text-sidebar-selected"
              >
                ×
              </button>
            </span>
          ))}
        </div>
      )}
      {open && (
        <div
          id={`${id}-menu`}
          className="space-y-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700"
        >
          <div>
            <Label htmlFor={`${id}-search`}>{t("serviceSearch")}</Label>
            <Input
              id={`${id}-search`}
              maxLength={200}
              value={search}
              disabled={disabled}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={disabled}
            onClick={() => setQuery({ search: search.trim(), page: 1 })}
          >
            {t("search")}
          </Button>
          {error && (
            <p
              role="alert"
              className="text-theme-xs text-error-600 dark:text-error-400"
            >
              {t("serviceLoadError")}
            </p>
          )}
          <div className="space-y-1">
            {options.map((s) => {
              const key = careServiceIdentity(s.name);
              return (
                <label
                  key={key}
                  className="flex min-h-11 items-center gap-3 text-theme-sm text-gray-700 dark:text-gray-300"
                >
                  <input
                    type="checkbox"
                    className="size-4 accent-kps-primary"
                    checked={selected.has(key)}
                    disabled={
                      disabled || (!selected.has(key) && value.length >= 10)
                    }
                    onChange={(e) => choose(s, e.target.checked)}
                  />
                  {s.name}
                </label>
              );
            })}
          </div>
          {data && data.meta.last_page > 1 && (
            <div className="flex flex-wrap items-center gap-2">
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={disabled || query.page <= 1}
                onClick={() => setQuery({ ...query, page: query.page - 1 })}
              >
                {t("previous")}
              </Button>
              <span className="text-theme-xs text-gray-500 dark:text-gray-400">
                {data.meta.current_page}/{data.meta.last_page}
              </span>
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={disabled || query.page >= data.meta.last_page}
                onClick={() => setQuery({ ...query, page: query.page + 1 })}
              >
                {t("next")}
              </Button>
            </div>
          )}
          <div className="flex flex-wrap gap-2">
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={disabled || value.length >= 10}
              onClick={create}
            >
              {t("createService")}
            </Button>
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={disabled}
              onClick={() => setOpen(false)}
            >
              {t("done")}
            </Button>
          </div>
          {notice && (
            <p
              role="status"
              className="text-theme-xs text-gray-600 dark:text-gray-300"
            >
              {notice}
            </p>
          )}
        </div>
      )}
    </div>
  );
}
