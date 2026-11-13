import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import SpaCareView from "@/features/spa/components/business/SpaCareView";
export default function SpaCare() {
  const { t } = useTranslation();
  const title = t("spa.navigation.care");
  return (
    <>
      <PageMeta
        title={`${title} | KIM PHỤC SẮC`}
        description={t("spa.meta.description")}
      />
      <PageBreadCrumb pageTitle={title} />
      <SpaCareView />
    </>
  );
}
