import ComponentCard from "@/components/common/ComponentCard";
import Button from "@/components/ui/button/Button";
import type { ManagedUser } from "@/types/userManagement";
import { useTranslation } from "react-i18next";
import EmployeeStatusBadge from "./EmployeeStatusBadge";

interface EmployeeAccessStatusProps {
  user: ManagedUser;
  isCurrentUser: boolean;
  isSubmitting: boolean;
  error?: string | null;
  success?: string | null;
  onChangeStatus: () => void;
}

export default function EmployeeAccessStatus({
  user,
  isCurrentUser,
  isSubmitting,
  error,
  success,
  onChangeStatus,
}: EmployeeAccessStatusProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "userManagement.accessStatus",
  });

  return (
    <ComponentCard title={t("title")} desc={t("description")} compact>
      <div>
        <p className="mb-2 text-theme-sm font-medium text-gray-700 dark:text-gray-300">
          {t("current")}
        </p>
        <EmployeeStatusBadge isActive={user.is_active} />
        {isCurrentUser && (
          <p className="mt-2 text-theme-xs text-warning-700 dark:text-warning-400">
            {t("selfNotice")}
          </p>
        )}
      </div>

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <div className="min-w-0 flex-1">
          {error && (
            <p
              role="alert"
              className="inline-flex rounded-lg bg-error-50 px-3 py-2 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
            >
              {error}
            </p>
          )}
          {success && (
            <p
              role="status"
              className="inline-flex rounded-lg bg-success-50 px-3 py-2 text-theme-sm text-success-700 dark:bg-success-500/10 dark:text-success-400"
            >
              {success}
            </p>
          )}
        </div>
        <Button
          type="button"
          variant={user.is_active ? "outline" : "primary"}
          className={
            user.is_active
              ? "w-full !text-error-600 !ring-error-300 hover:!bg-error-50 focus-visible:ring-3 focus-visible:ring-error-300 focus-visible:outline-none sm:w-auto dark:!text-error-400 dark:!ring-error-800 dark:hover:!bg-error-500/10 dark:focus-visible:ring-error-800"
              : "w-full !bg-kps-primary hover:!bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none disabled:!bg-kps-primary sm:w-auto"
          }
          disabled={isCurrentUser || isSubmitting}
          onClick={onChangeStatus}
        >
          {t(user.is_active ? "deactivate" : "activate")}
        </Button>
      </div>
    </ComponentCard>
  );
}
