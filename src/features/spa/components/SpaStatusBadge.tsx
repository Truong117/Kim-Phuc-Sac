import Badge from "@/components/ui/badge/Badge";
import { useTranslation } from "react-i18next";
import type { SpaAppointmentStatus, SpaTaskStatus } from "../types";

type SpaStatusBadgeProps =
  | { kind: "appointment"; status: SpaAppointmentStatus }
  | { kind: "task"; status: SpaTaskStatus };

const colors = {
  booked: "light",
  arrived: "info",
  cancelled: "light",
  assigned: "warning",
  in_progress: "info",
  completed: "success",
} as const;

export default function SpaStatusBadge({ kind, status }: SpaStatusBadgeProps) {
  const { t } = useTranslation();
  return (
    <Badge color={colors[status]} size="sm">
      {t(`spa.status.${kind}.${status}`)}
    </Badge>
  );
}
