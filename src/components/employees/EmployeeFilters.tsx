import ComponentCard from "@/components/common/ComponentCard";
import Select from "@/components/form/Select";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import Button from "@/components/ui/button/Button";
import type { ReferenceItem, UserListFilters } from "@/types/userManagement";
import { useTranslation } from "react-i18next";

interface EmployeeFiltersProps {
  filters: UserListFilters;
  departments: ReferenceItem[];
  roles: ReferenceItem[];
  isReferenceLoading: boolean;
  onChange: <Key extends keyof UserListFilters>(
    field: Key,
    value: UserListFilters[Key],
  ) => void;
  onReset: () => void;
}

const toOptions = (items: ReferenceItem[]) =>
  items.map((item) => ({ value: String(item.id), label: item.name }));

export default function EmployeeFilters({
  filters,
  departments,
  roles,
  isReferenceLoading,
  onChange,
  onReset,
}: EmployeeFiltersProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "userManagement.filters",
  });
  const statusOptions = [
    { value: "active", label: t("statusOptions.active") },
    { value: "inactive", label: t("statusOptions.inactive") },
  ];

  return (
    <ComponentCard title={t("title")}>
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <div className="md:col-span-2 xl:col-span-1">
          <Label htmlFor="employee-search">{t("search")}</Label>
          <Input
            id="employee-search"
            name="search"
            type="search"
            value={filters.search}
            placeholder={t("searchPlaceholder")}
            onChange={(event) => onChange("search", event.target.value)}
          />
        </div>

        <div>
          <Label htmlFor="employee-department">{t("department")}</Label>
          <Select
            id="employee-department"
            name="department"
            options={toOptions(departments)}
            value={filters.departmentId}
            placeholder={
              isReferenceLoading ? t("loadingOptions") : t("allDepartments")
            }
            allowClear
            disabled={isReferenceLoading}
            onChange={(value) => onChange("departmentId", value)}
          />
        </div>

        <div>
          <Label htmlFor="employee-role">{t("role")}</Label>
          <Select
            id="employee-role"
            name="role"
            options={toOptions(roles)}
            value={filters.roleId}
            placeholder={
              isReferenceLoading ? t("loadingOptions") : t("allRoles")
            }
            allowClear
            disabled={isReferenceLoading}
            onChange={(value) => onChange("roleId", value)}
          />
        </div>

        <div>
          <Label htmlFor="employee-status">{t("status")}</Label>
          <Select
            id="employee-status"
            name="status"
            options={statusOptions}
            value={filters.status}
            placeholder={t("allStatuses")}
            allowClear
            onChange={(value) =>
              onChange("status", value as UserListFilters["status"])
            }
          />
        </div>

        <div className="md:col-span-2 xl:col-span-4 xl:flex xl:justify-end">
          <Button
            type="button"
            variant="outline"
            className="w-full xl:w-auto"
            onClick={onReset}
          >
            {t("reset")}
          </Button>
        </div>
      </div>
    </ComponentCard>
  );
}
