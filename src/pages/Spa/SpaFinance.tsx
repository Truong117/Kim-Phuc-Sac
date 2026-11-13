import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import SpaFinanceView from "@/features/spa/components/business/SpaFinanceView";
export default function SpaFinance() {
  const { t } = useTranslation();
  const title = t("spa.navigation.finance");
  return (
    <>
      <PageMeta
        title={`${title} | KIM PHỤC SẮC`}
        description={t("spa.meta.description")}
      />
      <PageBreadCrumb pageTitle={title} />
      <SpaFinanceView />
    </>
  );
}
