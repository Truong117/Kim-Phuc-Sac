import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import SpaPageHeading from "@/features/spa/components/SpaPageHeading";
import SpaCashierView from "@/features/spa/components/business/SpaCashierView";
export default function SpaCashier() {
  const { t } = useTranslation();
  const title = t("spa.navigation.cashier");
  return (
    <>
      <PageMeta
        title={`${title} | KIM PHỤC SẮC`}
        description={t("spa.meta.description")}
      />
      <SpaPageHeading section="cashier" />
      <SpaCashierView />
    </>
  );
}
