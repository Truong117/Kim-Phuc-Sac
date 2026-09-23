import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import { useTranslation } from "react-i18next";

interface ModulePlaceholderProps {
  titleKey: string;
}

export default function ModulePlaceholder({
  titleKey,
}: ModulePlaceholderProps) {
  const { t } = useTranslation();
  const title = t(titleKey);

  return (
    <>
      <PageMeta
        title={`${title} | KIM PHỤC SẮC`}
        description={t("modulePlaceholder.metaDescription", { module: title })}
      />
      <PageBreadCrumb pageTitle={title} />
      <div className="rounded-2xl border border-gray-200 bg-white px-5 py-12 text-center shadow-theme-xs sm:px-8 dark:border-gray-800 dark:bg-white/3">
        <div className="mx-auto max-w-125">
          <div className="mx-auto mb-5 size-30">
            <img
              src="/images/logo/logo mau.png"
              alt="Logo Kim Phục Sắc"
              className="size-full object-contain"
            />
          </div>
          <h3 className="mb-2 text-theme-xl font-semibold text-gray-800 dark:text-white/90">
            {title}
          </h3>
          <p className="text-theme-sm text-gray-500 dark:text-gray-400">
            {t("modulePlaceholder.message")}
          </p>
        </div>
      </div>
    </>
  );
}
