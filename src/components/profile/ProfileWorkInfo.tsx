import ComponentCard from "@/components/common/ComponentCard";
import type { AuthUser } from "@/types/auth";
import { useTranslation } from "react-i18next";

interface ProfileWorkInfoProps {
  user: AuthUser;
}

export default function ProfileWorkInfo({ user }: ProfileWorkInfoProps) {
  const { t } = useTranslation("common", { keyPrefix: "profile.work" });
  const items = [
    [t("organization"), user.organization?.name],
    [t("role"), user.role?.name],
    [t("department"), user.department?.name],
    [t("location"), user.location?.name],
  ];

  return (
    <ComponentCard title={t("title")} desc={t("description")} compact>
      <dl className="grid gap-4 sm:grid-cols-2">
        {items.map(([label, value]) => (
          <div
            key={label}
            className="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 dark:border-gray-800 dark:bg-gray-900"
          >
            <dt className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
              {label}
            </dt>
            <dd className="mt-1 text-theme-sm font-medium text-gray-800 dark:text-white/90">
              {value ?? t("notAssigned")}
            </dd>
          </div>
        ))}
      </dl>
    </ComponentCard>
  );
}
