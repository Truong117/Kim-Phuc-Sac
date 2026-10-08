import PageMeta from "@/components/common/PageMeta";
import { useTranslation } from "react-i18next";

export default function Forbidden() {
  const { t } = useTranslation("common", {
    keyPrefix: "authorization.forbidden",
  });

  return (
    <>
      <PageMeta
        title={`${t("title")} | KIM PHỤC SẮC`}
        description={t("metaDescription")}
      />
      <section className="flex min-h-[60vh] items-center justify-center py-12 text-center">
        <div className="max-w-xl rounded-2xl border border-gray-200 bg-white px-6 py-12 shadow-theme-xs sm:px-10 dark:border-gray-800 dark:bg-white/3">
          <p className="text-title-lg font-bold text-kps-primary dark:text-kps-primary">
            {t("error")}
          </p>
          <h1 className="mt-4 text-title-sm font-semibold text-gray-900 dark:text-white">
            {t("title")}
          </h1>
          <p className="mt-3 text-theme-sm text-gray-500 dark:text-gray-400">
            {t("message")}
          </p>
        </div>
      </section>
    </>
  );
}
