import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import type { TaskListFilters, TaskReferences } from "@/types/tasks";
import { useTranslation } from "react-i18next";

interface TaskFiltersProps {
  filters: TaskListFilters;
  references: TaskReferences;
  isLoading?: boolean;
  onChange: <Key extends keyof TaskListFilters>(
    field: Key,
    value: TaskListFilters[Key],
  ) => void;
  onReset: () => void;
}

export default function TaskFilters({
  filters,
  references,
  isLoading = false,
  onChange,
  onReset,
}: TaskFiltersProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "taskManagement.filters",
  });
  const hasFilters = Object.entries(filters).some(([, value]) => Boolean(value));

  return (
    <section className="rounded-2xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/3 sm:p-5">
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div className="md:col-span-2">
          <Label htmlFor="task-search">{t("searchLabel")}</Label>
          <Input
            id="task-search"
            value={filters.search}
            onChange={(event) => onChange("search", event.target.value)}
            placeholder={t("searchPlaceholder")}
          />
        </div>
        <div>
          <Label htmlFor="task-status-filter">{t("status")}</Label>
          <Select
            id="task-status-filter"
            value={filters.status}
            allowClear
            placeholder={t("allStatuses")}
            onChange={(value) =>
              onChange("status", value as TaskListFilters["status"])
            }
            options={[
              ["TODO", t("statusOptions.TODO")],
              ["IN_PROGRESS", t("statusOptions.IN_PROGRESS")],
              ["BLOCKED", t("statusOptions.BLOCKED")],
              ["COMPLETED", t("statusOptions.COMPLETED")],
            ].map(([value, label]) => ({ value, label }))}
          />
        </div>
        <div>
          <Label htmlFor="task-priority-filter">{t("priority")}</Label>
          <Select
            id="task-priority-filter"
            value={filters.priority}
            allowClear
            placeholder={t("allPriorities")}
            onChange={(value) =>
              onChange("priority", value as TaskListFilters["priority"])
            }
            options={[
              ["LOW", t("priorityOptions.LOW")],
              ["MEDIUM", t("priorityOptions.MEDIUM")],
              ["HIGH", t("priorityOptions.HIGH")],
              ["URGENT", t("priorityOptions.URGENT")],
            ].map(([value, label]) => ({ value, label }))}
          />
        </div>

        {references.employees.visible && (
          <div>
            <Label htmlFor="task-assignee-filter">{t("assignee")}</Label>
            <Select
              id="task-assignee-filter"
              value={filters.assigneeId}
              allowClear
              disabled={isLoading}
              placeholder={t("allAssignees")}
              onChange={(value) => onChange("assigneeId", value)}
              options={references.employees.options.map((option) => ({
                value: String(option.id),
                label: option.name,
              }))}
            />
          </div>
        )}
        {references.departments.visible && (
          <div>
            <Label htmlFor="task-department-filter">{t("department")}</Label>
            <Select
              id="task-department-filter"
              value={filters.departmentId}
              allowClear
              disabled={isLoading}
              placeholder={t("allDepartments")}
              onChange={(value) => onChange("departmentId", value)}
              options={references.departments.options.map((option) => ({
                value: String(option.id),
                label: option.name,
              }))}
            />
          </div>
        )}
        {references.locations.visible && (
          <div>
            <Label htmlFor="task-location-filter">{t("location")}</Label>
            <Select
              id="task-location-filter"
              value={filters.locationId}
              allowClear
              disabled={isLoading}
              placeholder={t("allLocations")}
              onChange={(value) => onChange("locationId", value)}
              options={references.locations.options.map((option) => ({
                value: String(option.id),
                label: option.name,
              }))}
            />
          </div>
        )}
        <div>
          <Label htmlFor="task-due-from">{t("dueFrom")}</Label>
          <Input
            id="task-due-from"
            type="date"
            value={filters.dueFrom}
            onChange={(event) => onChange("dueFrom", event.target.value)}
          />
        </div>
        <div>
          <Label htmlFor="task-due-to">{t("dueTo")}</Label>
          <Input
            id="task-due-to"
            type="date"
            min={filters.dueFrom || undefined}
            value={filters.dueTo}
            onChange={(event) => onChange("dueTo", event.target.value)}
          />
        </div>
      </div>

      <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
        <label className="inline-flex cursor-pointer items-center gap-2 text-theme-sm font-medium text-gray-700 dark:text-gray-300">
          <input
            type="checkbox"
            checked={filters.overdue}
            onChange={(event) => onChange("overdue", event.target.checked)}
            className="size-4 rounded border-gray-300 text-kps-primary focus:ring-kps-primary/30 dark:border-gray-700 dark:bg-gray-900"
          />
          {t("overdueOnly")}
        </label>
        <button
          type="button"
          disabled={!hasFilters}
          onClick={onReset}
          className="rounded-lg px-3 py-2 text-theme-sm font-semibold text-kps-primary transition hover:bg-kps-primary/10 focus-visible:ring-2 focus-visible:ring-kps-primary/30 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-40 dark:text-sidebar-selected"
        >
          {t("reset")}
        </button>
      </div>
    </section>
  );
}
