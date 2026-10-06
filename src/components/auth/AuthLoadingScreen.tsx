import BrandMark from "@/components/common/BrandMark";
import { useTranslation } from "react-i18next";

export default function AuthLoadingScreen() {
  const { t } = useTranslation();

  return (
    <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4 dark:bg-gray-950">
      <div className="flex flex-col items-center gap-5 text-center">
        <BrandMark />
        <span
          className="size-8 animate-spin rounded-full border-4 border-gray-200 border-t-brand-500 dark:border-gray-800 dark:border-t-brand-400"
          aria-hidden="true"
        />
        <p className="text-theme-sm text-gray-500 dark:text-gray-400">
          {t("auth.initializing")}
        </p>
      </div>
    </div>
  );
}
