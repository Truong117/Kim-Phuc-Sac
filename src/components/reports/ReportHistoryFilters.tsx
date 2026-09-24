import ComponentCard from "@/components/common/ComponentCard";
import Select from "@/components/form/Select";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import Button from "@/components/ui/button/Button";
import type { ReportHistoryFilterValues } from "@/types/reports";
import { useTranslation } from "react-i18next";

interface FilterOption {
  value: string;
  label: string;
}

interface ReportHistoryFiltersProps {
  filters: ReportHistoryFilterValues;
  employeeOptions: FilterOption[];
  departmentOptions: FilterOption[];
  onChange: <Key extends keyof ReportHistoryFilterValues>(
    field: Key,
    value: ReportHistoryFilterValues[Key],
  ) => void;
  onReset: () => void;
}

export default function ReportHistoryFilters({
  filters,
  employeeOptions,
  departmentOptions,
  onChange,
  onReset,
}: ReportHistoryFiltersProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "reportHistory.filters",
  });
  const statusOptions: FilterOption[] = [
    { value: "COMPLETED", label: t("statusOptions.completed") },
    { value: "IN_PROGRESS", label: t("statusOptions.inProgress") },
    { value: "BLOCKED", label: t("statusOptions.blocked") },
  ];

  return (
    <ComponentCard title={t("title")}>
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
        <div>
          <Label htmlFor="report-from-date">{t("fromDate")}</Label>
          <Input
            id="report-from-date"
            name="fromDate"
            type="date"
            value={filters.fromDate}
            max={filters.toDate || undefined}
            onChange={(event) => onChange("fromDate", event.target.value)}
          />
        </div>

        <div>
          <Label htmlFor="report-to-date">{t("toDate")}</Label>
          <Input
            id="report-to-date"
            name="toDate"
            type="date"
            value={filters.toDate}
            min={filters.fromDate || undefined}
            onChange={(event) => onChange("toDate", event.target.value)}
          />
        </div>

        <div>
          <Label htmlFor="report-employee">{t("employee")}</Label>
          <Select
            id="report-employee"
            name="employee"
            options={employeeOptions}
            value={filters.employeeId}
            placeholder={t("allEmployees")}
            allowClear
            onChange={(value) => onChange("employeeId", value)}
          />
        </div>

        <div>
          <Label htmlFor="report-department">{t("department")}</Label>
          <Select
            id="report-department"
            name="department"
            options={departmentOptions}
            value={filters.department}
            placeholder={t("allDepartments")}
            allowClear
            onChange={(value) => onChange("department", value)}
          />
        </div>

        <div>
          <Label htmlFor="report-status">{t("status")}</Label>
          <Select
            id="report-status"
            name="status"
            options={statusOptions}
            value={filters.status}
            placeholder={t("allStatuses")}
            allowClear
            onChange={(value) =>
              onChange("status", value as ReportHistoryFilterValues["status"])
            }
          />
        </div>

        <div className="md:col-span-2 xl:col-span-4">
          <Label htmlFor="report-search">{t("search")}</Label>
          <Input
            id="report-search"
            name="search"
            type="search"
            value={filters.search}
            placeholder={t("searchPlaceholder")}
            onChange={(event) => onChange("search", event.target.value)}
          />
        </div>

        <div className="flex items-end md:col-span-2 xl:col-span-1">
          <Button
            type="button"
            variant="outline"
            className="w-full"
            onClick={onReset}
          >
            {t("reset")}
          </Button>
        </div>
      </div>
    </ComponentCard>
  );
}
