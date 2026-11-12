import AppLayout from "@/layout/AppLayout";
import Badge from "@/components/ui/badge/Badge";
import { useTranslation } from "react-i18next";
import { Outlet } from "react-router";

export default function SpaLayout() {
  const { t } = useTranslation();
  return (
    <AppLayout workspace="spa">
      <div className="min-w-0 space-y-6">
        <section
          className="rounded-2xl border border-kps-primary/20 bg-kps-primary/5 p-4 dark:border-kps-primary/50 dark:bg-kps-primary/10"
          aria-label={t("spa.preview.title")}
        >
          <div className="flex flex-wrap items-center gap-2">
            <Badge color="light">{t("spa.preview.badge")}</Badge>
            <h1 className="font-semibold text-gray-900 dark:text-white">
              {t("spa.preview.title")}
            </h1>
          </div>
          <p className="mt-2 text-sm text-gray-600 dark:text-gray-300">
            {t("spa.preview.description")}
          </p>
        </section>
        <Outlet />
      </div>
    </AppLayout>
  );
}
