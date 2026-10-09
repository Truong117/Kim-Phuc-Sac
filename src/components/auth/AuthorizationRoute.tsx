import { useAuthorization } from "@/hooks/useAuthorization";
import Forbidden from "@/pages/OtherPage/Forbidden";
import type { NavigationKey } from "@/types/authorization";
import { useTranslation } from "react-i18next";
import { Outlet } from "react-router";

interface AuthorizationRouteProps {
  navigationKey: NavigationKey;
}

export default function AuthorizationRoute({
  navigationKey,
}: AuthorizationRouteProps) {
  const { t } = useTranslation();
  const { canNavigate, isAuthorizationLoading } = useAuthorization();

  if (isAuthorizationLoading) {
    return (
      <div
        className="flex min-h-[50vh] flex-col items-center justify-center gap-3 text-center"
        role="status"
        aria-live="polite"
      >
        <span
          className="size-8 animate-spin rounded-full border-4 border-gray-200 border-t-kps-primary dark:border-gray-800 dark:border-t-kps-primary"
          aria-hidden="true"
        />
        <p className="text-theme-sm text-gray-500 dark:text-gray-400">
          {t("authorization.loading")}
        </p>
      </div>
    );
  }

  if (!canNavigate(navigationKey)) {
    return <Forbidden />;
  }

  return <Outlet />;
}
