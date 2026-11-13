import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import { PlusIcon, SearchIcon } from "@/icons";
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
  active,
  disabled,
  onChange,
}: {
  taskId: number;
  workKey: string;
  value: CareServiceOption[];
  known: CareServiceOption[];
  active: boolean;
  disabled: boolean;
  onChange: (services: CareServiceOption[]) => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "customerCare.works" });
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState({ search: "", page: 1 });
  const [data, setData] = useState<CareServicePage | null>(null);
  const [error, setError] = useState(false),
    [notice, setNotice] = useState("");
  const id = `care-service-${workKey}`;
  useEffect(() => {
    if (!active) return;
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
  }, [active, taskId, query]);
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
    setSearch("");
    setQuery({ search: "", page: 1 });
  }
  return (
    <section className="min-w-0 space-y-3" aria-labelledby={`${id}-label`}>
      <div className="flex min-h-8 items-center justify-between gap-2">
        <h4
          id={`${id}-label`}
          className="font-medium text-gray-800 dark:text-white/90"
        >
          {t("services")}
        </h4>
        <span className="rounded-md bg-brand-50 px-2 py-0.5 text-theme-xs text-kps-primary dark:bg-kps-primary/15 dark:text-brand-200">
          {value.length}
        </span>
      </div>
      <div
        className="custom-scrollbar max-h-64 space-y-1 overflow-y-auto"
        role="group"
        aria-labelledby={`${id}-label`}
      >
        {options.map((s) => {
          const key = careServiceIdentity(s.name);
          return (
            <label
              key={key}
              className={`flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-theme-sm text-gray-700 dark:text-gray-200 ${selected.has(key) ? "bg-brand-50 dark:bg-kps-primary/15" : "hover:bg-gray-50 dark:hover:bg-gray-800"}`}
            >
              <input
                type="checkbox"
                className="size-4 shrink-0 accent-kps-primary"
                checked={selected.has(key)}
                disabled={
                  disabled || (!selected.has(key) && value.length >= 10)
                }
                onChange={(e) => choose(s, e.target.checked)}
              />
              <span className="min-w-0 break-words">{s.name}</span>
            </label>
          );
        })}
        {!data && !error && (
          <p
            role="status"
            className="text-theme-xs text-gray-500 dark:text-gray-400"
          >
            {t("serviceLoading")}
          </p>
        )}
        {data && !options.length && !error && (
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">
            {t("serviceEmpty")}
          </p>
        )}
      </div>
      {query.search && value.length > 0 && (
        <p className="text-theme-xs text-kps-primary dark:text-brand-200">
          {t("selectedNames", { names: value.map((s) => s.name).join("; ") })}
        </p>
      )}
      <div>
        <Label className="sr-only" htmlFor={`${id}-search`}>
          {t("serviceSearch")}
        </Label>
        <div className="flex items-center gap-2">
          <div className="min-w-0 flex-1">
            <Input
              id={`${id}-search`}
              maxLength={200}
              value={search}
              placeholder={t("servicePlaceholder")}
              disabled={disabled}
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
            aria-label={t("search")}
            disabled={disabled}
            onClick={() => setQuery({ search: search.trim(), page: 1 })}
            className="flex size-11 shrink-0 items-center justify-center rounded-lg border border-gray-300 text-kps-primary hover:bg-brand-50 disabled:opacity-50 dark:border-gray-700 dark:text-brand-200 dark:hover:bg-gray-800"
          >
            <SearchIcon className="size-4" aria-hidden="true" />
          </button>
        </div>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-2">
        <button
          type="button"
          className="inline-flex min-h-11 items-center gap-2 text-theme-xs font-medium text-kps-primary disabled:opacity-50 dark:text-brand-200"
          disabled={disabled || value.length >= 10}
          onClick={create}
        >
          <PlusIcon className="size-4" aria-hidden="true" />
          {t("createService")}
        </button>
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
      </div>
      {error && (
        <p
          role="alert"
          className="text-theme-xs text-error-600 dark:text-error-400"
        >
          {t("serviceLoadError")}
        </p>
      )}
      {notice && (
        <p
          role="status"
          className="text-theme-xs text-gray-600 dark:text-gray-300"
        >
          {notice}
        </p>
      )}
    </section>
  );
}
