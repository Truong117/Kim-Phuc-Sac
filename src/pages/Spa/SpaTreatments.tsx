import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import SpaTreatmentsView from "@/features/spa/components/business/SpaTreatmentsView";
export default function SpaTreatments() {
  const { t } = useTranslation();
  const title = t("spa.navigation.treatments");
  return (
    <>
      <PageMeta
        title={`${title} | KIM PHỤC SẮC`}
        description={t("spa.meta.description")}
      />
      <PageBreadCrumb pageTitle={title} />
      <SpaTreatmentsView />
    </>
  );
}
