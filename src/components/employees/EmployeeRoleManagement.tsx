import ComponentCard from "@/components/common/ComponentCard";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import type { ManagedUser, ReferenceItem } from "@/types/userManagement";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";

interface EmployeeRoleManagementProps {
  user: ManagedUser;
  roles: ReferenceItem[];
  isCurrentUser: boolean;
  isSubmitting: boolean;
  error?: string | null;
  success?: string | null;
  onInteraction: () => void;
  onDirtyChange?: (isDirty: boolean) => void;
  onSave: (roleId: number) => Promise<void>;
}

export default function EmployeeRoleManagement({
  user,
  roles,
  isCurrentUser,
  isSubmitting,
  error,
  success,
  onInteraction,
  onDirtyChange,
  onSave,
}: EmployeeRoleManagementProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "userManagement.roleManagement",
  });
  const [roleId, setRoleId] = useState(user.role ? String(user.role.id) : "");
  const originalRoleId = user.role ? String(user.role.id) : "";
  const isDirty = Boolean(roleId) && roleId !== originalRoleId;

  useEffect(() => {
    onDirtyChange?.(isDirty);
  }, [isDirty, onDirtyChange]);

  useEffect(
    () => () => {
      onDirtyChange?.(false);
    },
    [onDirtyChange],
  );

  return (
    <ComponentCard title={t("title")} desc={t("description")} compact>
      <div className="max-w-xl">
        <Select
          id="employee-role-management"
          name="role_id"
          aria-label={t("label")}
          options={roles.map((role) => ({
            value: String(role.id),
            label: role.name,
          }))}
          value={roleId}
          placeholder={t("placeholder")}
          disabled={isCurrentUser || isSubmitting}
          className="focus:!border-kps-primary focus:!ring-kps-primary/20 dark:focus:!border-kps-primary dark:focus:!ring-kps-primary/30"
          onChange={(value) => {
            setRoleId(value);
            onInteraction();
          }}
        />
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
          className="w-full !bg-kps-primary hover:!bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none disabled:!bg-kps-primary sm:w-auto"
          disabled={isCurrentUser || isSubmitting || !isDirty}
          onClick={() => void onSave(Number(roleId))}
        >
          {isSubmitting ? t("submitting") : t("save")}
        </Button>
      </div>
    </ComponentCard>
  );
}
