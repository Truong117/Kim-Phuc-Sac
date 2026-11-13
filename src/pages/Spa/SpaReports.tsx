import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import SpaPageHeading from "@/features/spa/components/SpaPageHeading";
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
      <SpaPageHeading section="reports" />
      <SpaReportsView />
    </>
  );
}
