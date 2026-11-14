import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { getSpaCatalogStatus } from "../../services/spaCatalogService";
import SpaCatalogView from "./SpaCatalogView";
import SpaSavedCatalogView from "./SpaSavedCatalogView";
import { SpaAction } from "./SpaBusinessUi";

export default function SpaCatalogGate() {
  const { t } = useTranslation();
  const [mode, setMode] = useState<"loading" | "preview" | "saved" | "error">(
    "loading",
  );
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    getSpaCatalogStatus(controller.signal)
      .then((result) => {
        if (
          !controller.signal.aborted &&
          (typeof result.enabled !== "boolean" ||
            typeof result.can_manage !== "boolean")
        ) {
          setMode("error");
          return;
        }
        if (!controller.signal.aborted)
          setMode(
            !result.enabled ? "preview" : result.can_manage ? "saved" : "error",
          );
      })
      .catch(() => {
        if (!controller.signal.aborted) setMode("error");
      });
    return () => controller.abort();
  }, [attempt]);
  if (mode === "loading")
    return <p role="status">{t("spa.business.catalogLoading")}</p>;
  if (mode === "error")
    return (
      <div className="space-y-3">
        <p role="alert">{t("spa.business.catalogUnavailable")}</p>
        <SpaAction
          onClick={() => {
            setMode("loading");
            setAttempt((n) => n + 1);
          }}
        >
          {t("spa.business.catalogRetry")}
        </SpaAction>
      </div>
    );
  return mode === "saved" ? <SpaSavedCatalogView /> : <SpaCatalogView />;
}
