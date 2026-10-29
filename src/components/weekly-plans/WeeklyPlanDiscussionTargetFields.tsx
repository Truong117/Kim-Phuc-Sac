import Label from "@/components/form/Label";
import { ChevronDownIcon, SearchIcon } from "@/icons";
import { useClickOutside } from "@/hooks/useClickOutside";
import type { WeeklyPlan } from "@/types/weeklyPlanning";
import {
  createWeeklyPlanDiscussionEmployeeOptions,
  createWeeklyPlanDiscussionWorkOptions,
  filterWeeklyPlanDiscussionOptions,
  isWeeklyPlanDiscussionEmployeeConsistent,
  isWeeklyPlanDiscussionEntryConsistent,
  type WeeklyPlanDiscussionOption,
  type WeeklyPlanDiscussionTarget,
} from "@/utils/weeklyPlanDiscussionTarget";
import {
  useCallback,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  type KeyboardEvent,
} from "react";
import { useTranslation } from "react-i18next";

type TargetPickerProps = {
  id: string;
  label: string;
  searchPlaceholder: string;
  emptyLabel: string;
  allOptionLabel: string;
  options: WeeklyPlanDiscussionOption[];
  value: number | null;
  onChange: (value: number | null) => void;
  disabled: boolean;
};

function TargetPicker({
  id,
  label,
  searchPlaceholder,
  emptyLabel,
  allOptionLabel,
  options,
  value,
  onChange,
  disabled,
}: TargetPickerProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [query, setQuery] = useState("");
  const containerRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const listboxId = useId();
  const filteredOptions = useMemo(
    () => filterWeeklyPlanDiscussionOptions(options, query),
    [options, query],
  );
  const selectedLabel =
    value === null
      ? allOptionLabel
      : (options.find((option) => option.value === value)?.label ??
        allOptionLabel);

  const closePicker = useCallback((restoreFocus = false) => {
    setIsOpen(false);
    setQuery("");
    if (restoreFocus) {
      requestAnimationFrame(() => triggerRef.current?.focus());
    }
  }, []);

  useClickOutside(containerRef, closePicker);

  const selectOption = (nextValue: number | null) => {
    onChange(nextValue);
    closePicker(true);
  };

  const handleKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
    if (event.key !== "Escape") return;
    event.preventDefault();
    closePicker(true);
  };

  const renderOption = (
    option: WeeklyPlanDiscussionOption | null,
    optionLabel: string,
  ) => {
    const optionValue = option?.value ?? null;
    const isSelected = optionValue === value;
    return (
      <button
        key={optionValue ?? "all"}
        type="button"
        role="option"
        aria-selected={isSelected}
        onClick={() => selectOption(optionValue)}
        className={`w-full rounded-lg px-3 py-2 text-start text-theme-sm transition-colors focus-visible:ring-2 focus-visible:ring-kps-primary/40 focus-visible:outline-none ${
          isSelected
            ? "bg-kps-primary/[0.08] font-semibold text-kps-primary dark:bg-kps-primary/20 dark:text-white"
            : "text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
        }`}
      >
        {optionLabel}
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
        <span className="min-w-0 truncate">{selectedLabel}</span>
        <ChevronDownIcon
          aria-hidden="true"
          className={`size-4 shrink-0 transition-transform ${isOpen ? "rotate-180" : ""}`}
        />
      </button>

      {isOpen && (
        <div className="absolute start-0 end-0 z-99 mt-2 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-theme-lg dark:border-gray-700 dark:bg-gray-900">
          <div className="border-b border-gray-200 p-3 dark:border-gray-700">
            <label className="sr-only" htmlFor={`${listboxId}-search`}>
              {searchPlaceholder}
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
                placeholder={searchPlaceholder}
                className="h-10 w-full rounded-lg border border-gray-300 bg-transparent py-2 ps-9 pe-3 text-theme-sm text-gray-800 placeholder:text-gray-400 focus:border-kps-primary focus:ring-3 focus:ring-kps-primary/20 focus:outline-hidden dark:border-gray-700 dark:text-white/90"
              />
            </div>
          </div>

          <div
            id={listboxId}
            role="listbox"
            aria-label={label}
            className="max-h-72 space-y-1 overflow-y-auto p-3"
          >
            {renderOption(null, allOptionLabel)}
            {filteredOptions.map((option) =>
              renderOption(option, option.label),
            )}
            {filteredOptions.length === 0 && (
              <p className="px-3 py-2 text-theme-sm text-gray-500 dark:text-gray-400">
                {emptyLabel}
              </p>
            )}
          </div>
        </div>
      )}
    </div>
  );
}

type Props = {
  idPrefix: string;
  plan: Pick<WeeklyPlan, "employee_reviews" | "entries">;
  value: WeeklyPlanDiscussionTarget;
  onChange: (value: WeeklyPlanDiscussionTarget) => void;
  disabled?: boolean;
};

export default function WeeklyPlanDiscussionTargetFields({
  idPrefix,
  plan,
  value,
  onChange,
  disabled = false,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.discussion",
  });
  const employeeOptions = useMemo(
    () => createWeeklyPlanDiscussionEmployeeOptions(plan),
    [plan],
  );
  const workOptions = useMemo(
    () =>
      value.selectedEmployeeId === null
        ? []
        : createWeeklyPlanDiscussionWorkOptions(plan, value.selectedEmployeeId),
    [plan, value.selectedEmployeeId],
  );

  useEffect(() => {
    if (!isWeeklyPlanDiscussionEmployeeConsistent(plan, value)) {
      onChange({ selectedEmployeeId: null, selectedEntryId: null });
      return;
    }
    if (isWeeklyPlanDiscussionEntryConsistent(plan, value)) return;
    onChange({ ...value, selectedEntryId: null });
  }, [onChange, plan, value]);

  return (
    <div className="space-y-4">
      <div>
        <Label htmlFor={`${idPrefix}-employee`}>{t("employeeLabel")}</Label>
        <TargetPicker
          id={`${idPrefix}-employee`}
          label={t("employeeLabel")}
          searchPlaceholder={t("employeeSearch")}
          emptyLabel={t("noEmployees")}
          allOptionLabel={t("wholePlan")}
          options={employeeOptions}
          value={value.selectedEmployeeId}
          disabled={disabled}
          onChange={(selectedEmployeeId) =>
            onChange({ selectedEmployeeId, selectedEntryId: null })
          }
        />
      </div>

      {value.selectedEmployeeId !== null && (
        <div>
          <Label htmlFor={`${idPrefix}-work`}>{t("workLabel")}</Label>
          <TargetPicker
            id={`${idPrefix}-work`}
            label={t("workLabel")}
            searchPlaceholder={t("workSearch")}
            emptyLabel={t("noWorks")}
            allOptionLabel={t("allEmployeeWorksOption")}
            options={workOptions}
            value={value.selectedEntryId}
            disabled={disabled}
            onChange={(selectedEntryId) =>
              onChange({ ...value, selectedEntryId })
            }
          />
        </div>
      )}
    </div>
  );
}
