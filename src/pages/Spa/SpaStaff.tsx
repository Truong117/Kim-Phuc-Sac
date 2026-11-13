import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import SpaStaffView from "@/features/spa/components/business/SpaStaffView";
export default function SpaStaff() {
  const { t } = useTranslation();
  const title = t("spa.navigation.staff");
  return (
    <>
      <PageMeta
        title={`${title} | KIM PHỤC SẮC`}
        description={t("spa.meta.description")}
      />
      <PageBreadCrumb pageTitle={title} />
      <SpaStaffView />
    </>
  );
}
