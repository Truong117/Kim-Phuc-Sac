import { useTranslation } from "react-i18next";
import PageMeta from "@/components/common/PageMeta";
import SpaPageHeading from "@/features/spa/components/SpaPageHeading";
import SpaRoomsView from "@/features/spa/components/business/SpaRoomsView";
export default function SpaRooms() {
  const { t } = useTranslation();
  const title = t("spa.navigation.rooms");
  return (
    <>
      <PageMeta
        title={`${title} | KIM PHỤC SẮC`}
        description={t("spa.meta.description")}
      />
      <SpaPageHeading section="rooms" />
      <SpaRoomsView />
    </>
  );
}
