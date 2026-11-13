import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import SpaSettingsView from "@/features/spa/components/business/SpaSettingsView";
export default function SpaSettings() {
  const { t } = useTranslation();
  const title = t("spa.navigation.settings");
  return (
    <>
      <PageMeta
        title={`${title} | KIM PHỤC SẮC`}
        description={t("spa.meta.description")}
      />
      <PageBreadCrumb pageTitle={title} />
      <SpaSettingsView />
    </>
  );
}
