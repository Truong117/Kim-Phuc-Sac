import { useState } from "react";
import { useTranslation } from "react-i18next";
import { useSpaPreview } from "../hooks/useSpaPreviewSnapshot";
import { getSpaCustomerPhotos } from "../skinPhotos";
import SpaPhotoViewer from "./SpaPhotoViewer";
import { SpaAction, SpaHint } from "./business/SpaBusinessUi";
export default function SpaCustomerPhotos({
  customerId,
}: {
  customerId: string;
}) {
  const { t } = useTranslation();
  const { snapshot, photoAccess } = useSpaPreview();
  const [open, setOpen] = useState(false);
  const photos = getSpaCustomerPhotos(snapshot, customerId, photoAccess);
  return (
    <section className="space-y-3">
      <h4 className="font-medium">{t("spa.photos.title")}</h4>
      <SpaHint>{t("spa.photos.count", { count: photos.length })}</SpaHint>
      {photos.length > 0 && (
        <SpaAction secondary onClick={() => setOpen((v) => !v)}>
          {t(open ? "spa.photos.collapse" : "spa.photos.compare")}
        </SpaAction>
      )}
      {open && photos.length > 0 && (
        <SpaPhotoViewer
          key={customerId}
          photos={photos}
          initialId={photos[0].id}
          onClose={() => setOpen(false)}
        />
      )}
    </section>
  );
}
