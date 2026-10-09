import Badge from "@/components/ui/badge/Badge";
import { useTranslation } from "react-i18next";

interface EmployeeStatusBadgeProps {
  isActive: boolean;
}

export default function EmployeeStatusBadge({
  isActive,
}: EmployeeStatusBadgeProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "userManagement.statuses",
  });

  return (
    <Badge color={isActive ? "success" : "light"} size="sm">
      {t(isActive ? "active" : "inactive")}
    </Badge>
  );
}
