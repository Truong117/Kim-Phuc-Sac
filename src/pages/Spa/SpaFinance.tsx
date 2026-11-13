import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import SpaPageHeading from "@/features/spa/components/SpaPageHeading";
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
      <SpaPageHeading section="finance" />
      <SpaFinanceView />
    </>
  );
}
