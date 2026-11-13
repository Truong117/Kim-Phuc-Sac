import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import SpaPageHeading from "@/features/spa/components/SpaPageHeading";
import SpaCatalogView from "@/features/spa/components/business/SpaCatalogView";
export default function SpaCatalog() {
  const { t } = useTranslation();
  const title = t("spa.navigation.services");
  return (
    <>
      <PageMeta
        title={`${title} | KIM PHỤC SẮC`}
        description={t("spa.meta.description")}
      />
      <SpaPageHeading section="services" />
      <SpaCatalogView />
    </>
  );
}
