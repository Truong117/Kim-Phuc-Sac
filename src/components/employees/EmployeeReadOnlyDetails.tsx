import ComponentCard from "@/components/common/ComponentCard";
import EmployeeStatusBadge from "@/components/employees/EmployeeStatusBadge";
import type { ManagedUser } from "@/types/userManagement";
import { useTranslation } from "react-i18next";

interface EmployeeReadOnlyDetailsProps {
  user: ManagedUser;
}

export default function EmployeeReadOnlyDetails({
  user,
}: EmployeeReadOnlyDetailsProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "userManagement.readOnly",
  });

  const rows = [
    { label: t("name"), value: user.name },
    { label: t("email"), value: user.email },
    {
      label: t("department"),
      value: user.department?.name ?? t("notAssigned"),
    },
    {
      label: t("location"),
      value: user.location?.name ?? t("notAssigned"),
    },
    { label: t("role"), value: user.role?.name ?? t("notAssigned") },
  ];

  return (
    <ComponentCard title={t("title")} desc={t("description")} compact>
      <dl className="grid grid-cols-1 gap-x-6 gap-y-5 md:grid-cols-2">
        {rows.map((row) => (
          <div key={row.label}>
            <dt className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
              {row.label}
            </dt>
            <dd className="mt-1 text-theme-sm font-medium break-words text-gray-900 dark:text-white">
              {row.value}
            </dd>
          </div>
        ))}
        <div>
          <dt className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
            {t("status")}
          </dt>
          <dd className="mt-2">
            <EmployeeStatusBadge isActive={user.is_active} />
          </dd>
        </div>
      </dl>
    </ComponentCard>
  );
}
