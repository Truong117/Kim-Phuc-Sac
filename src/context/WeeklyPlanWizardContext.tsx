import { ApiServiceError, type ValidationErrors } from "@/services/apiClient";
import {
  getWeeklyPlan,
  getWeeklyPlanGoogleDocsEmployeeMappings,
  getWeeklyPlanReferences,
} from "@/services/weeklyPlanningService";
import type {
  WeeklyPlan,
  WeeklyPlanGoogleDocsEmployeeMappings,
  WeeklyPlanReferences,
} from "@/types/weeklyPlanning";
import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from "react";
import { useTranslation } from "react-i18next";
import { useParams } from "react-router";

type WeeklyPlanWizardContextValue = {
  plan: WeeklyPlan | null;
  references: WeeklyPlanReferences | null;
  mappings: WeeklyPlanGoogleDocsEmployeeMappings | null;
  loading: boolean;
  busy: boolean;
  error: string | null;
  setMappings: (mappings: WeeklyPlanGoogleDocsEmployeeMappings) => void;
  refresh: () => Promise<void>;
  perform: (
    action: () => Promise<WeeklyPlan>,
    onValidationErrors?: (errors: ValidationErrors) => void,
  ) => Promise<boolean>;
  clearError: () => void;
};

const WeeklyPlanWizardContext = createContext<
  WeeklyPlanWizardContextValue | undefined
>(undefined);

export function WeeklyPlanWizardProvider({
  children,
}: {
  children: ReactNode;
}) {
  const { id } = useParams();
  const { t } = useTranslation("common", { keyPrefix: "weeklyPlanning" });
  const [plan, setPlan] = useState<WeeklyPlan | null>(null);
  const [references, setReferences] = useState<WeeklyPlanReferences | null>(
    null,
  );
  const [mappings, setMappings] =
    useState<WeeklyPlanGoogleDocsEmployeeMappings | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const activeRequest = useRef<AbortController | null>(null);
  const requestVersion = useRef(0);
  const loadedPlanId = useRef<string | null>(null);
  const busyRef = useRef(false);

  const refresh = useCallback(async () => {
    if (!id) return;
    activeRequest.current?.abort();
    const controller = new AbortController();
    activeRequest.current = controller;
    const version = ++requestVersion.current;
    if (loadedPlanId.current !== id) {
      setPlan(null);
      setMappings(null);
    }
    setLoading(true);

    try {
      const [nextPlan, nextReferences] = await Promise.all([
        getWeeklyPlan(id, controller.signal),
        getWeeklyPlanReferences(controller.signal),
      ]);
      let nextMappings: WeeklyPlanGoogleDocsEmployeeMappings | null = null;
      if (nextPlan.source_type === "GOOGLE_DOCS" && nextPlan.actions.can_edit) {
        nextMappings = await getWeeklyPlanGoogleDocsEmployeeMappings(
          nextPlan.id,
          controller.signal,
        );
      }
      if (controller.signal.aborted || requestVersion.current !== version)
        return;
      setPlan(nextPlan);
      loadedPlanId.current = id;
      setReferences(nextReferences);
      setMappings(nextMappings);
      setError(null);
    } catch (nextError) {
      if (
        nextError instanceof DOMException &&
        nextError.name === "AbortError"
      ) {
        return;
      }
      if (requestVersion.current !== version) return;
      setError(
        nextError instanceof ApiServiceError && nextError.status === 403
          ? t("errors.forbidden")
          : nextError instanceof ApiServiceError && nextError.status === 404
            ? t("errors.notFound")
            : t("errors.loadDetail"),
      );
    } finally {
      if (!controller.signal.aborted && requestVersion.current === version) {
        setLoading(false);
      }
    }
  }, [id, t]);

  useEffect(() => {
    let active = true;
    queueMicrotask(() => {
      if (active) void refresh();
    });
    return () => {
      active = false;
      activeRequest.current?.abort();
    };
  }, [refresh]);

  const perform = useCallback(
    async (
      action: () => Promise<WeeklyPlan>,
      onValidationErrors?: (errors: ValidationErrors) => void,
    ): Promise<boolean> => {
      if (busyRef.current) return false;
      busyRef.current = true;
      setBusy(true);
      setError(null);
      try {
        setPlan(await action());
        return true;
      } catch (nextError) {
        const validation =
          nextError instanceof ApiServiceError
            ? Object.values(nextError.validationErrors ?? {})[0]?.[0]
            : undefined;
        if (
          nextError instanceof ApiServiceError &&
          nextError.validationErrors
        ) {
          onValidationErrors?.(nextError.validationErrors);
        }
        setError(
          validation ??
            (nextError instanceof ApiServiceError && nextError.status === 409
              ? nextError.message
              : t("errors.action")),
        );
        return false;
      } finally {
        busyRef.current = false;
        setBusy(false);
      }
    },
    [t],
  );

  return (
    <WeeklyPlanWizardContext.Provider
      value={{
        plan,
        references,
        mappings,
        loading,
        busy,
        error,
        setMappings,
        refresh,
        perform,
        clearError: () => setError(null),
      }}
    >
      {children}
    </WeeklyPlanWizardContext.Provider>
  );
}

export const useWeeklyPlanWizard = (): WeeklyPlanWizardContextValue => {
  const context = useContext(WeeklyPlanWizardContext);
  if (!context) {
    throw new Error(
      "useWeeklyPlanWizard must be used inside WeeklyPlanWizardProvider.",
    );
  }
  return context;
};
