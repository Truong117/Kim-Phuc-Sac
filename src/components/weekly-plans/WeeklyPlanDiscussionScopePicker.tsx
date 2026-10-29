import { ChevronDownIcon, SearchIcon } from "@/icons";
import { useClickOutside } from "@/hooks/useClickOutside";
import type { WeeklyPlan } from "@/types/weeklyPlanning";
import {
  createWeeklyPlanDiscussionScopes,
  filterWeeklyPlanDiscussionScopes,
  findWeeklyPlanDiscussionScope,
  type WeeklyPlanDiscussionScopeOption,
} from "@/utils/weeklyPlanDiscussionScope";
import {
  useCallback,
  useId,
  useMemo,
  useRef,
  useState,
  type KeyboardEvent,
} from "react";
import { useTranslation } from "react-i18next";

type Props = {
  id?: string;
  plan: Pick<WeeklyPlan, "employee_reviews" | "entries">;
  value: string;
  onChange: (value: string) => void;
  disabled?: boolean;
};

export default function WeeklyPlanDiscussionScopePicker({
  id,
  plan,
  value,
  onChange,
  disabled = false,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.wizard.submit",
  });
  const [isOpen, setIsOpen] = useState(false);
  const [query, setQuery] = useState("");
  const containerRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const listboxId = useId();
  const planGroupId = useId();
  const employeeGroupId = useId();
  const workGroupId = useId();
  const groups = useMemo(
    () => createWeeklyPlanDiscussionScopes(plan, t("commentWholePlan")),
    [plan, t],
  );
  const filteredGroups = useMemo(
    () => filterWeeklyPlanDiscussionScopes(groups, query),
    [groups, query],
  );
  const selected = findWeeklyPlanDiscussionScope(groups, value);
  const hasMatches =
    filteredGroups.employees.length > 0 || filteredGroups.works.length > 0;

  const closePicker = useCallback((restoreFocus = false) => {
    setIsOpen(false);
    setQuery("");
    if (restoreFocus) {
      requestAnimationFrame(() => triggerRef.current?.focus());
    }
  }, []);

  useClickOutside(containerRef, closePicker);

  const selectOption = (option: WeeklyPlanDiscussionScopeOption) => {
    onChange(option.value);
    closePicker(true);
  };

  const handleKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    if (event.key !== "Escape") return;
    event.preventDefault();
    closePicker(true);
  };

  const renderOption = (option: WeeklyPlanDiscussionScopeOption) => {
    const isSelected = option.value === value;
    return (
      <button
        key={option.value}
        type="button"
        role="option"
        aria-selected={isSelected}
        onClick={() => selectOption(option)}
        className={`w-full rounded-lg px-3 py-2 text-start text-theme-sm transition-colors focus-visible:ring-2 focus-visible:ring-kps-primary/40 focus-visible:outline-none ${
          isSelected
            ? "bg-kps-primary/[0.08] font-semibold text-kps-primary dark:bg-kps-primary/20 dark:text-white"
            : "text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
        }`}
      >
        {option.label}
      </button>
    );
  };

  return (
    <div
      ref={containerRef}
      className="relative w-full"
      onKeyDown={handleKeyDown}
    >
      <button
        ref={triggerRef}
        id={id}
        type="button"
        disabled={disabled}
        aria-haspopup="listbox"
        aria-expanded={isOpen}
        aria-controls={isOpen ? listboxId : undefined}
        onClick={() => (isOpen ? closePicker() : setIsOpen(true))}
        className="flex h-11 w-full items-center justify-between gap-3 rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-start text-theme-sm text-gray-800 shadow-theme-xs transition-colors focus:border-kps-primary focus:ring-3 focus:ring-kps-primary/20 focus:outline-hidden disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90"
      >
        <span className="min-w-0 truncate">{selected.label}</span>
        <ChevronDownIcon
          aria-hidden="true"
          className={`size-4 shrink-0 transition-transform ${isOpen ? "rotate-180" : ""}`}
        />
      </button>

      {isOpen && (
        <div className="absolute start-0 end-0 z-99 mt-2 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-theme-lg dark:border-gray-700 dark:bg-gray-900">
          <div className="border-b border-gray-200 p-3 dark:border-gray-700">
            <label className="sr-only" htmlFor={`${listboxId}-search`}>
              {t("commentScopeSearch")}
            </label>
            <div className="relative">
              <SearchIcon
                aria-hidden="true"
                className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-gray-400 dark:text-gray-500"
              />
              <input
                autoFocus
                id={`${listboxId}-search`}
                type="search"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder={t("commentScopeSearch")}
                className="h-10 w-full rounded-lg border border-gray-300 bg-transparent py-2 ps-9 pe-3 text-theme-sm text-gray-800 placeholder:text-gray-400 focus:border-kps-primary focus:ring-3 focus:ring-kps-primary/20 focus:outline-hidden dark:border-gray-700 dark:text-white/90"
              />
            </div>
          </div>

          <div
            id={listboxId}
            role="listbox"
            aria-label={t("commentTarget")}
            className="max-h-80 space-y-3 overflow-y-auto p-3"
          >
            <div role="group" aria-labelledby={planGroupId}>
              <h3
                id={planGroupId}
                className="px-3 pb-1 text-theme-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400"
              >
                {t("commentScopePlanGroup")}
              </h3>
              {renderOption(filteredGroups.plan)}
            </div>

            {filteredGroups.employees.length > 0 && (
              <div role="group" aria-labelledby={employeeGroupId}>
                <h3
                  id={employeeGroupId}
                  className="px-3 pb-1 text-theme-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400"
                >
                  {t("commentScopeEmployeeGroup")}
                </h3>
                <div className="space-y-1">
                  {filteredGroups.employees.map(renderOption)}
                </div>
              </div>
            )}

            {filteredGroups.works.length > 0 && (
              <div role="group" aria-labelledby={workGroupId}>
                <h3
                  id={workGroupId}
                  className="px-3 pb-1 text-theme-xs font-semibold tracking-wide text-gray-500 uppercase dark:text-gray-400"
                >
                  {t("commentScopeWorkGroup")}
                </h3>
                <div className="space-y-1">
                  {filteredGroups.works.map(renderOption)}
                </div>
              </div>
            )}

            {!hasMatches && (
              <p className="px-3 py-2 text-theme-sm text-gray-500 dark:text-gray-400">
                {t("commentScopeEmpty")}
              </p>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
