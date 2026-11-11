import ComponentCard from "@/components/common/ComponentCard";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import Badge from "@/components/ui/badge/Badge";
import type { SpaSectionKey } from "@/features/spa/config";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";

export default function SpaSection({ section }: { section: SpaSectionKey }) {
  const { t } = useTranslation();
  const title = t(`spa.navigation.${section}`);
  return (
    <>
      <PageMeta
        title={t("spa.meta.section", { name: title })}
        description={t("spa.meta.description")}
      />
      <PageBreadCrumb pageTitle={title} />
      <ComponentCard title={title} desc={t(`spa.sections.${section}`)}>
        <Badge color="light">{t("spa.foundation.badge")}</Badge>
        <p className="text-sm text-gray-600 dark:text-gray-300">
          {t("spa.foundation.description")}
        </p>
        <Link
          to="/spa"
          className="inline-flex min-h-11 items-center rounded-lg border border-gray-300 px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-kps-primary dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
        >
          {t("spa.foundation.back")}
        </Link>
      </ComponentCard>
    </>
  );
}
