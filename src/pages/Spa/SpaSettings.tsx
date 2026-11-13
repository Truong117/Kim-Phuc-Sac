import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import SpaPageHeading from "@/features/spa/components/SpaPageHeading";
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
      <SpaPageHeading section="settings" />
      <SpaSettingsView />
    </>
  );
}
