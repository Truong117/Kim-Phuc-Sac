import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import SpaInventoryView from "@/features/spa/components/business/SpaInventoryView";
export default function SpaInventory() {
  const { t } = useTranslation();
  const title = t("spa.navigation.inventory");
  return (
    <>
      <PageMeta
        title={`${title} | KIM PHỤC SẮC`}
        description={t("spa.meta.description")}
      />
      <PageBreadCrumb pageTitle={title} />
      <SpaInventoryView />
    </>
  );
}
