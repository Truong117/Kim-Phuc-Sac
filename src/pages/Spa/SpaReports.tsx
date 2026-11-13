import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import SpaReportsView from "@/features/spa/components/business/SpaReportsView";
export default function SpaReports() {
  const { t } = useTranslation();
  const title = t("spa.navigation.reports");
  return (
    <>
      <PageMeta
        title={`${title} | KIM PHỤC SẮC`}
        description={t("spa.meta.description")}
      />
      <PageBreadCrumb pageTitle={title} />
      <SpaReportsView />
    </>
  );
}
