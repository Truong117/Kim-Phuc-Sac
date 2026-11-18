import type { ReactNode } from "react";
import { useTranslation } from "react-i18next";
import { useSpaBookingAccess } from "../hooks/useSpaPreviewSnapshot";
import type { SpaBookingCapabilities } from "../services/spaBookingService";
import SpaPageHeading from "./SpaPageHeading";
import { SpaAction } from "./business/SpaBusinessUi";

export default function SpaBookingGate({
  section,
  preview,
  live,
}: {
  section: "appointments" | "customers";
  preview: ReactNode;
  live: (capabilities: SpaBookingCapabilities) => ReactNode;
}) {
  const { t } = useTranslation();
  const access = useSpaBookingAccess();
  if (access.status === "ready") {
    if (!access.capabilities.enabled) return preview;
    const allowed =
      section === "appointments"
        ? access.capabilities.viewAppointments
        : access.capabilities.viewCustomers;
    if (allowed) return live(access.capabilities);
  }
  return (
    <>
      <SpaPageHeading section={section} />
      {access.status === "loading" ? (
        <p role="status">{t("spa.live.loading")}</p>
      ) : (
        <div className="space-y-3">
          <p role="alert">
            {t(
              access.status === "error"
                ? "spa.live.loadError"
                : "spa.live.accessDenied",
            )}
          </p>
          {access.status === "error" && (
            <SpaAction onClick={access.retry}>{t("spa.live.retry")}</SpaAction>
          )}
        </div>
      )}
    </>
  );
}
