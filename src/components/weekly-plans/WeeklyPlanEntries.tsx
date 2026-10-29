import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import WeeklyPlanStatusBadge from "@/components/weekly-plans/WeeklyPlanStatusBadge";
import { ChevronDownIcon, SearchIcon } from "@/icons";
import type { WeeklyPlanEntry, WeeklyPlanReview } from "@/types/weeklyPlanning";
import { formatDateOnly } from "@/utils/dateOnly";
import { useMemo, useState } from "react";
import { useTranslation } from "react-i18next";

type Props = {
  entries: WeeklyPlanEntry[];
  reviews: WeeklyPlanReview[];
  canEdit: boolean;
  canReview: boolean;
  busy: boolean;
  onEdit: (entry: WeeklyPlanEntry) => void;
  onDelete: (entry: WeeklyPlanEntry) => void;
  onReview: (
    assigneeId: number,
    status: "ACCEPTED" | "CHANGES_REQUESTED",
  ) => void;
};

type EmployeeGroup = {
  key: string;
  assigneeId: number | null;
  name: string;
  departments: string[];
  entries: WeeklyPlanEntry[];
};

const needsMoreInformation = (entry: WeeklyPlanEntry) =>
  entry.validation_status === "INVALID" ||
  !entry.expected_result?.trim() ||
  !entry.start_date ||
  !entry.due_date;

export default function WeeklyPlanEntries({
  entries,
  reviews,
  canEdit,
  canReview,
  busy,
  onEdit,
  onDelete,
  onReview,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.detail",
  });
  const [query, setQuery] = useState("");
  const [employeeFilter, setEmployeeFilter] = useState("all");
  const [validationFilter, setValidationFilter] = useState("all");
  const [completenessFilter, setCompletenessFilter] = useState("all");
  const [collapsedKeys, setCollapsedKeys] = useState<Set<string>>(new Set());

  const groups = useMemo(
    () =>
      Object.values(
        entries.reduce<Record<string, EmployeeGroup>>((result, entry) => {
          const key = entry.assignee?.id
            ? `user:${entry.assignee.id}`
            : "unresolved";
          result[key] ??= {
            key,
            assigneeId: entry.assignee?.id ?? null,
            name: entry.assignee?.name ?? t("unresolvedEmployee"),
            departments: [],
            entries: [],
          };
          if (
            entry.department_name &&
            !result[key].departments.includes(entry.department_name)
          ) {
            result[key].departments.push(entry.department_name);
          }
          result[key].entries.push(entry);
          return result;
        }, {}),
      ),
    [entries, t],
  );

  const normalizedQuery = query.trim().toLocaleLowerCase("vi");
  const visibleGroups = useMemo(
    () =>
      groups
        .filter(
          (group) => employeeFilter === "all" || employeeFilter === group.key,
        )
        .map((group) => ({
          ...group,
          entries: group.entries.filter((entry) => {
            const searchText = [
              group.name,
              entry.employee_name,
              entry.title,
              entry.content,
              entry.expected_result,
            ]
              .filter(Boolean)
              .join(" ")
              .toLocaleLowerCase("vi");
            const matchesCompleteness =
              completenessFilter === "all" ||
              (completenessFilter === "missing-result" &&
                !entry.expected_result?.trim()) ||
              (completenessFilter === "missing-date" &&
                (!entry.start_date || !entry.due_date));

            return (
              (!normalizedQuery || searchText.includes(normalizedQuery)) &&
              (validationFilter === "all" ||
                entry.validation_status === validationFilter) &&
              matchesCompleteness
            );
          }),
        }))
        .filter((group) => group.entries.length > 0),
    [
      completenessFilter,
      employeeFilter,
      groups,
      normalizedQuery,
      validationFilter,
    ],
  );

  const acceptedEmployees = reviews.filter(
    (review) => review.status === "ACCEPTED",
  ).length;
  const attentionCount = entries.filter(needsMoreInformation).length;
  const hasFilters =
    normalizedQuery !== "" ||
    employeeFilter !== "all" ||
    validationFilter !== "all" ||
    completenessFilter !== "all";

  const toggleGroup = (key: string) => {
    setCollapsedKeys((current) => {
      const next = new Set(current);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  };

  return (
    <div className="space-y-4">
      <dl className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {[
          [
            t("metrics.employees"),
            groups.filter((group) => group.assigneeId !== null).length,
          ],
          [t("metrics.entries"), entries.length],
          [t("metrics.needsAttention"), attentionCount],
          [t("metrics.acceptedEmployees"), acceptedEmployees],
        ].map(([label, value]) => (
          <div
            key={String(label)}
            className="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800/60"
          >
            <dt className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
              {label}
            </dt>
            <dd className="mt-1 text-xl font-bold text-gray-900 dark:text-white">
              {value}
            </dd>
          </div>
        ))}
      </dl>

      {entries.length > 0 && (
        <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
          <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
            <div className="xl:col-span-2">
              <Label htmlFor="weekly-entry-search">
                {t("filters.searchLabel")}
              </Label>
              <div className="relative">
                <SearchIcon
                  aria-hidden="true"
                  className="pointer-events-none absolute start-3 top-1/2 size-5 -translate-y-1/2 text-gray-400"
                />
                <Input
                  id="weekly-entry-search"
                  value={query}
                  placeholder={t("filters.searchPlaceholder")}
                  className="ps-10"
                  onChange={(event) => setQuery(event.target.value)}
                />
              </div>
            </div>
            <div>
              <Label htmlFor="weekly-entry-employee-filter">
                {t("filters.employee")}
              </Label>
              <Select
                id="weekly-entry-employee-filter"
                value={employeeFilter}
                onChange={setEmployeeFilter}
                options={[
                  { value: "all", label: t("filters.allEmployees") },
                  ...groups.map((group) => ({
                    value: group.key,
                    label: group.name,
                  })),
                ]}
              />
            </div>
            <div>
              <Label htmlFor="weekly-entry-validation-filter">
                {t("filters.validation")}
              </Label>
              <Select
                id="weekly-entry-validation-filter"
                value={validationFilter}
                onChange={setValidationFilter}
                options={[
                  { value: "all", label: t("filters.allValidation") },
                  { value: "VALID", label: t("validation.VALID") },
                  { value: "INVALID", label: t("validation.INVALID") },
                ]}
              />
            </div>
            <div>
              <Label htmlFor="weekly-entry-completeness-filter">
                {t("filters.completeness")}
              </Label>
              <Select
                id="weekly-entry-completeness-filter"
                value={completenessFilter}
                onChange={setCompletenessFilter}
                options={[
                  { value: "all", label: t("filters.allCompleteness") },
                  {
                    value: "missing-result",
                    label: t("filters.missingResult"),
                  },
                  { value: "missing-date", label: t("filters.missingDate") },
                ]}
              />
            </div>
          </div>
          <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              {t("filters.resultCount", {
                groups: visibleGroups.length,
                entries: visibleGroups.reduce(
                  (total, group) => total + group.entries.length,
                  0,
                ),
              })}
            </p>
            <div className="flex flex-wrap gap-2">
              {hasFilters && (
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => {
                    setQuery("");
                    setEmployeeFilter("all");
                    setValidationFilter("all");
                    setCompletenessFilter("all");
                  }}
                >
                  {t("filters.clear")}
                </Button>
              )}
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() => setCollapsedKeys(new Set())}
              >
                {t("expandAll")}
              </Button>
              <Button
                type="button"
                size="sm"
                variant="outline"
                onClick={() =>
                  setCollapsedKeys(
                    new Set(visibleGroups.map((group) => group.key)),
                  )
                }
              >
                {t("collapseAll")}
              </Button>
            </div>
          </div>
        </div>
      )}

      {entries.length === 0 ? (
        <p className="rounded-xl bg-gray-50 px-4 py-8 text-center text-theme-sm text-gray-500 dark:bg-gray-800/50 dark:text-gray-400">
          {t("noEntries")}
        </p>
      ) : visibleGroups.length === 0 ? (
        <p className="rounded-xl bg-gray-50 px-4 py-8 text-center text-theme-sm text-gray-500 dark:bg-gray-800/50 dark:text-gray-400">
          {t("noFilteredEntries")}
        </p>
      ) : (
        visibleGroups.map((group) => {
          const review = reviews.find(
            (item) => item.assignee.id === group.assigneeId,
          );
          const isCollapsed = collapsedKeys.has(group.key);
          const validCount = group.entries.filter(
            (entry) => entry.validation_status === "VALID",
          ).length;
          const groupAttentionCount =
            group.entries.filter(needsMoreInformation).length;
          const regionId = `weekly-employee-group-${group.key.replace(":", "-")}`;

          return (
            <section
              key={group.key}
              className={`overflow-hidden rounded-xl border ${groupAttentionCount > 0 ? "border-warning-300 dark:border-warning-700" : "border-gray-200 dark:border-gray-700"}`}
            >
              <div className="flex flex-col gap-3 bg-white p-4 sm:flex-row sm:items-center sm:justify-between dark:bg-gray-900">
                <button
                  type="button"
                  aria-expanded={!isCollapsed}
                  aria-controls={regionId}
                  onClick={() => toggleGroup(group.key)}
                  className="flex min-w-0 flex-1 items-center gap-3 rounded-lg text-start focus-visible:ring-2 focus-visible:ring-kps-primary/40 focus-visible:outline-none"
                >
                  <ChevronDownIcon
                    aria-hidden="true"
                    className={`size-5 shrink-0 text-gray-500 transition-transform ${isCollapsed ? "-rotate-90 rtl:rotate-90" : ""}`}
                  />
                  <span className="min-w-0">
                    <span className="flex flex-wrap items-center gap-2">
                      <span className="font-semibold text-gray-900 dark:text-white">
                        {group.name}
                      </span>
                      {group.assigneeId === null && (
                        <span className="rounded-full bg-warning-50 px-2 py-0.5 text-theme-xs font-semibold text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                          {t("needsAssignment")}
                        </span>
                      )}
                      {review && (
                        <WeeklyPlanStatusBadge status={review.status} />
                      )}
                    </span>
                    <span className="mt-1 block text-theme-xs text-gray-500 dark:text-gray-400">
                      {group.departments.length > 0
                        ? group.departments.join(", ")
                        : t("noDepartment")}
                    </span>
                  </span>
                </button>
                <div className="flex flex-wrap items-center gap-2 text-theme-xs">
                  <span className="rounded-full bg-gray-100 px-2.5 py-1 font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                    {t("groupCounts.total", { count: group.entries.length })}
                  </span>
                  <span className="rounded-full bg-success-50 px-2.5 py-1 font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">
                    {t("groupCounts.valid", { count: validCount })}
                  </span>
                  {groupAttentionCount > 0 && (
                    <span className="rounded-full bg-warning-50 px-2.5 py-1 font-semibold text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                      {t("groupCounts.missing", { count: groupAttentionCount })}
                    </span>
                  )}
                </div>
              </div>

              {!isCollapsed && (
                <div
                  id={regionId}
                  className="space-y-3 border-t border-gray-200 bg-gray-50/60 p-4 dark:border-gray-700 dark:bg-gray-800/30"
                >
                  {group.entries.map((entry) => (
                    <article
                      key={entry.id}
                      className="rounded-xl bg-white p-4 shadow-theme-xs dark:bg-gray-900"
                    >
                      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div className="min-w-0 flex-1">
                          <div className="flex flex-wrap items-center gap-2">
                            <h4 className="font-semibold text-gray-900 dark:text-white">
                              {entry.title || t("untitled")}
                            </h4>
                            <span
                              className={`rounded-full px-2 py-0.5 text-theme-xs font-semibold ${entry.validation_status === "VALID" ? "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400" : "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400"}`}
                            >
                              {t("validation." + entry.validation_status)}
                            </span>
                            {entry.source_row && (
                              <span className="rounded-full bg-gray-100 px-2 py-0.5 text-theme-xs font-medium text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                {t("sourceRow", { row: entry.source_row })}
                              </span>
                            )}
                            <span className="rounded-full bg-gray-100 px-2 py-0.5 text-theme-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                              {t("priorities." + (entry.priority ?? "MEDIUM"))}
                            </span>
                          </div>
                          {group.assigneeId === null && entry.employee_name && (
                            <p className="mt-1 text-theme-xs text-warning-700 dark:text-warning-400">
                              {t("sourceEmployeeName", {
                                name: entry.employee_name,
                              })}
                            </p>
                          )}
                          {entry.content && (
                            <p className="mt-2 text-theme-sm whitespace-pre-wrap text-gray-700 dark:text-gray-300">
                              {entry.content}
                            </p>
                          )}
                          <dl className="mt-3 grid gap-3 text-theme-sm md:grid-cols-2">
                            <div>
                              <dt className="font-medium text-gray-500 dark:text-gray-400">
                                {t("expectedResult")}
                              </dt>
                              <dd className="mt-1 text-gray-700 dark:text-gray-300">
                                {entry.expected_result || t("missingValue")}
                              </dd>
                            </div>
                            <div>
                              <dt className="font-medium text-gray-500 dark:text-gray-400">
                                {t("schedule")}
                              </dt>
                              <dd className="mt-1 text-gray-700 dark:text-gray-300">
                                {entry.start_date && entry.due_date
                                  ? `${formatDateOnly(entry.start_date)} – ${formatDateOnly(entry.due_date)}`
                                  : t("missingValue")}
                              </dd>
                            </div>
                          </dl>
                          {entry.note && (
                            <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
                              {entry.note}
                            </p>
                          )}
                          <details className="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800/60">
                            <summary className="cursor-pointer text-theme-xs font-semibold text-gray-700 dark:text-gray-300">
                              {t("items", { count: entry.items.length })}
                            </summary>
                            {entry.items.length > 0 ? (
                              <ol className="mt-2 list-decimal space-y-2 ps-5">
                                {entry.items.map((item) => (
                                  <li
                                    key={item.id}
                                    className="text-theme-sm text-gray-700 dark:text-gray-300"
                                  >
                                    <span className="font-medium">
                                      {item.title}
                                    </span>
                                    {item.content && (
                                      <p className="mt-1 text-theme-xs whitespace-pre-wrap text-gray-500 dark:text-gray-400">
                                        {item.content}
                                      </p>
                                    )}
                                  </li>
                                ))}
                              </ol>
                            ) : (
                              <p className="mt-2 text-theme-xs text-gray-500 dark:text-gray-400">
                                {t("noItems")}
                              </p>
                            )}
                          </details>
                          {entry.source_passages.length > 0 && (
                            <details className="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800/60">
                              <summary className="cursor-pointer text-theme-xs font-semibold text-gray-700 dark:text-gray-300">
                                {t("sourceReferences")}
                              </summary>
                              <ul className="mt-2 space-y-2">
                                {entry.source_passages.map((passage) => (
                                  <li
                                    key={passage.id}
                                    className="text-theme-xs whitespace-pre-wrap text-gray-600 dark:text-gray-400"
                                  >
                                    {passage.text}
                                  </li>
                                ))}
                              </ul>
                            </details>
                          )}
                          {entry.validation_errors.length > 0 && (
                            <div className="mt-3 rounded-lg bg-warning-50 px-3 py-2 dark:bg-warning-500/10">
                              <p className="text-theme-xs font-semibold text-warning-700 dark:text-warning-400">
                                {t("validationIssues")}
                              </p>
                              <ul className="mt-1 list-disc space-y-1 ps-5 text-theme-xs text-warning-700 dark:text-warning-400">
                                {entry.validation_errors.map((message) => (
                                  <li key={message}>{message}</li>
                                ))}
                              </ul>
                            </div>
                          )}
                        </div>
                        {(canEdit || canReview) && (
                          <div className="flex shrink-0 gap-2">
                            <Button
                              type="button"
                              size="sm"
                              variant="outline"
                              disabled={busy}
                              onClick={() => onEdit(entry)}
                            >
                              {t("edit")}
                            </Button>
                            {canEdit && (
                              <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={busy}
                                onClick={() => onDelete(entry)}
                              >
                                {t("delete")}
                              </Button>
                            )}
                          </div>
                        )}
                      </div>
                    </article>
                  ))}
                  {canReview && group.assigneeId && (
                    <div className="flex flex-wrap justify-end gap-2 border-t border-gray-200 pt-3 dark:border-gray-700">
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        disabled={busy}
                        onClick={() =>
                          onReview(
                            group.assigneeId as number,
                            "CHANGES_REQUESTED",
                          )
                        }
                      >
                        {t("requestChanges")}
                      </Button>
                      <Button
                        type="button"
                        size="sm"
                        disabled={busy}
                        className="bg-kps-primary hover:bg-kps-primary-hover"
                        onClick={() =>
                          onReview(group.assigneeId as number, "ACCEPTED")
                        }
                      >
                        {t("acceptEmployee")}
                      </Button>
                    </div>
                  )}
                </div>
              )}
            </section>
          );
        })
      )}
    </div>
  );
}
