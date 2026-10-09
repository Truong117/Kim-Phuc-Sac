import ComponentCard from "@/components/common/ComponentCard";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import type {
  ReportHistoryFilterValues,
  ReportReferences,
} from "@/types/reports";
import { useTranslation } from "react-i18next";

interface ReportHistoryFiltersProps {
  filters: ReportHistoryFilterValues;
  references: ReportReferences;
  isReferenceLoading: boolean;
  onChange: <Key extends keyof ReportHistoryFilterValues>(
    field: Key,
    value: ReportHistoryFilterValues[Key],
  ) => void;
  onReset: () => void;
}

const mapOptions = (options: ReportReferences["employees"]["options"]) =>
  options.map((option) => ({ value: String(option.id), label: option.name }));

export default function ReportHistoryFilters({
  filters,
  references,
  isReferenceLoading,
  onChange,
  onReset,
}: ReportHistoryFiltersProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "reportHistory.filters",
  });
  const statusOptions = [
    { value: "COMPLETED", label: t("statusOptions.completed") },
    { value: "IN_PROGRESS", label: t("statusOptions.inProgress") },
    { value: "BLOCKED", label: t("statusOptions.blocked") },
  ];

  return (
    <ComponentCard title={t("title")} compact>
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
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

        {references.employees.visible && (
          <div>
            <Label htmlFor="report-employee">{t("employee")}</Label>
            <Select
              id="report-employee"
              name="employee"
              options={mapOptions(references.employees.options)}
              value={filters.employeeId}
              placeholder={
                isReferenceLoading ? t("loadingOptions") : t("allEmployees")
              }
              disabled={isReferenceLoading}
              allowClear
              onChange={(value) => onChange("employeeId", value)}
            />
          </div>
        )}

        {references.departments.visible && (
          <div>
            <Label htmlFor="report-department">{t("department")}</Label>
            <Select
              id="report-department"
              name="department"
              options={mapOptions(references.departments.options)}
              value={filters.departmentId}
              placeholder={
                isReferenceLoading ? t("loadingOptions") : t("allDepartments")
              }
              disabled={isReferenceLoading}
              allowClear
              onChange={(value) => onChange("departmentId", value)}
            />
          </div>
        )}

        {references.locations.visible && (
          <div>
            <Label htmlFor="report-location">{t("location")}</Label>
            <Select
              id="report-location"
              name="location"
              options={mapOptions(references.locations.options)}
              value={filters.locationId}
              placeholder={
                isReferenceLoading ? t("loadingOptions") : t("allLocations")
              }
              disabled={isReferenceLoading}
              allowClear
              onChange={(value) => onChange("locationId", value)}
            />
          </div>
        )}

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

        <div className="md:col-span-2 xl:col-span-3">
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

        <div className="flex items-end">
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
