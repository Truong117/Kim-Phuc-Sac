import { useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import { useWeeklyPlanV3NavigationSafety } from "@/utils/weeklyPlanV3NavigationSafety";
import type { SpaPreviewError } from "../types";

export default function useSpaForm(dirty: boolean, onClose: () => void) {
  const { t } = useTranslation();
  const ref = useRef<HTMLFormElement>(null);
  const [error, setError] = useState<SpaPreviewError>();
  useWeeklyPlanV3NavigationSafety(dirty, t("spa.flow.discard"));
  const fail = (code: SpaPreviewError) => {
    setError(code);
    requestAnimationFrame(() => {
      const field = ref.current?.querySelector<HTMLElement>(
        '[aria-invalid="true"]',
      );
      field?.scrollIntoView({ block: "center", behavior: "smooth" });
      field?.focus();
    });
  };
  const close = () => {
    if (!dirty || window.confirm(t("spa.flow.discard"))) onClose();
  };
  return { ref, error, setError, fail, close };
}
