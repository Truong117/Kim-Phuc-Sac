import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import SpaPageHeading from "@/features/spa/components/SpaPageHeading";
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
      <SpaPageHeading section="treatments" />
      <SpaTreatmentsView />
    </>
  );
}
