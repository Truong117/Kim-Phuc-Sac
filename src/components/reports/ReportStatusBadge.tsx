import Badge from "@/components/ui/badge/Badge";
import type { ReportOverallStatus } from "@/types/reports";
import { useTranslation } from "react-i18next";

interface ReportStatusBadgeProps {
  status: ReportOverallStatus;
}

const statusColor = {
  COMPLETED: "success",
  IN_PROGRESS: "warning",
  BLOCKED: "error",
} as const;

const statusKey: Record<ReportOverallStatus, string> = {
  COMPLETED: "completed",
  IN_PROGRESS: "inProgress",
  BLOCKED: "blocked",
};

export default function ReportStatusBadge({ status }: ReportStatusBadgeProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "reportHistory.statuses",
  });

  return (
    <Badge size="sm" color={statusColor[status]}>
      {t(statusKey[status])}
    </Badge>
  );
}
