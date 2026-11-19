import { useEffect, useState } from "react";
import useSpaPreviewSession from "../hooks/useSpaPreviewSession";
import AppLayout from "@/layout/AppLayout";
import SpaHeader from "./SpaHeader";
import { useTranslation } from "react-i18next";
import { Outlet, useLocation } from "react-router";
import { getSpaBookingCapabilities } from "../services/spaBookingService";
import type { SpaBookingAccessState } from "../hooks/useSpaPreviewSnapshot";

export default function SpaLayout() {
  const { t } = useTranslation();
  const { pathname } = useLocation();
  const session = useSpaPreviewSession();
  const [resetEpoch, setResetEpoch] = useState(0);
  const [attempt, setAttempt] = useState(0);
  const [bookingAccess, setBookingAccess] = useState<SpaBookingAccessState>({
    status: "loading",
  });
  useEffect(() => {
    const controller = new AbortController();
    getSpaBookingCapabilities(controller.signal)
      .then((capabilities) => {
        if (!controller.signal.aborted)
          setBookingAccess({ status: "ready", capabilities });
      })
      .catch(() => {
        if (!controller.signal.aborted)
          setBookingAccess({
            status: "error",
            retry: () => {
              setBookingAccess({ status: "loading" });
              setAttempt((n) => n + 1);
            },
          });
      });
    return () => controller.abort();
  }, [attempt]);
  const bookingScreen =
    pathname === "/spa/appointments" || pathname === "/spa/customers";
  const showPreviewFooter =
    !bookingScreen ||
    (bookingAccess.status === "ready" && !bookingAccess.capabilities.enabled);
  return (
    <AppLayout
      workspace="spa"
      header={<SpaHeader bookingAccess={bookingAccess} />}
    >
      <div className="min-w-0 space-y-6">
        <Outlet key={resetEpoch} context={{ ...session, bookingAccess }} />
        {showPreviewFooter && (
          <footer
            className={`mt-8 flex flex-wrap items-center justify-between gap-3 border-t border-gray-200 pt-4 text-theme-xs text-gray-500 dark:border-gray-800 dark:text-gray-400 ${pathname === "/spa/technician" ? "pb-24" : ""}`}
          >
            <div>
              <h1 className="font-medium">{t("spa.preview.title")}</h1>
              <p className="mt-1">{t("spa.preview.description")}</p>
            </div>
            <button
              type="button"
              onClick={() => {
                if (window.confirm(t("spa.flow.resetConfirm"))) {
                  session.reset();
                  setResetEpoch((v) => v + 1);
                }
              }}
              className="min-h-11 shrink-0 rounded-lg px-3 font-medium text-kps-primary-hover hover:bg-kps-primary/5 dark:text-gray-200"
            >
              {t("spa.flow.reset")}
            </button>
          </footer>
        )}
      </div>
    </AppLayout>
  );
}
