import ComponentCard from "@/components/common/ComponentCard";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import { ApiServiceError } from "@/services/apiClient";
import {
  getWeeklyPlanGoogleDocsEmployeeMappings,
  updateWeeklyPlanGoogleDocsEmployeeMapping,
} from "@/services/weeklyPlanningService";
import type { WeeklyPlanGoogleDocsEmployeeMappings } from "@/types/weeklyPlanning";
import {
  buildGoogleDocsMappingUpdateInput,
  canSaveGoogleDocsMappingDraft,
  confirmGoogleDocsMappingDraft,
  createGoogleDocsMappingDrafts,
  getGoogleDocsMappingConflictKind,
  isGoogleDocsMappingConflictRefreshTrusted,
  isGoogleDocsMappingSnapshotRegressive,
  markGoogleDocsMappingConflictPending,
  reconcileGoogleDocsMappingConflict,
  reconcileGoogleDocsMappingSaveSuccess,
  reconcileGoogleDocsMappingSnapshot,
  resetGoogleDocsMappingDraftFromServer,
  shouldBlockGoogleDocsMappingWrites,
  shouldApplyGoogleDocsMappingResponse,
  updateGoogleDocsMappingDraft,
  type GoogleDocsMappingDrafts,
  type GoogleDocsMappingRequestToken,
} from "@/utils/googleDocsEmployeeMappingDrafts";
import { useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";

type Props = { planId: number };

export default function GoogleDocsEmployeeMappingPanel({ planId }: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.googleDocsEmployeeMapping",
  });
  const [mappings, setMappings] =
    useState<WeeklyPlanGoogleDocsEmployeeMappings | null>(null);
  const [drafts, setDrafts] = useState<GoogleDocsMappingDrafts>({});
  const [loading, setLoading] = useState(true);
  const [savingTabId, setSavingTabId] = useState<string | null>(null);
  const [refreshingTabId, setRefreshingTabId] = useState<string | null>(null);
  const [writeBlocked, setWriteBlocked] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const activeControllerRef = useRef<AbortController | null>(null);
  const requestGenerationRef = useRef(0);
  const activePlanIdRef = useRef(planId);
  const operationInFlightRef = useRef(false);
  const mappingsRef = useRef<WeeklyPlanGoogleDocsEmployeeMappings | null>(null);
  const translateRef = useRef(t);

  const startRequest = () => {
    activeControllerRef.current?.abort();
    const controller = new AbortController();
    const token: GoogleDocsMappingRequestToken = {
      generation: ++requestGenerationRef.current,
      planId,
    };
    activeControllerRef.current = controller;

    return { controller, token };
  };

  const requestIsActive = (
    controller: AbortController,
    token: GoogleDocsMappingRequestToken,
  ) =>
    activeControllerRef.current === controller &&
    shouldApplyGoogleDocsMappingResponse(
      requestGenerationRef.current,
      activePlanIdRef.current,
      token,
      controller.signal.aborted,
    );

  const applySnapshot = (
    next: WeeklyPlanGoogleDocsEmployeeMappings,
    reconcile: (
      current: GoogleDocsMappingDrafts,
      tabs: WeeklyPlanGoogleDocsEmployeeMappings["tabs"],
    ) => GoogleDocsMappingDrafts,
  ): boolean => {
    const current = mappingsRef.current;
    if (
      current &&
      isGoogleDocsMappingSnapshotRegressive(current.tabs, next.tabs)
    ) {
      return false;
    }

    mappingsRef.current = next;
    if (!next.actions.can_update) setWriteBlocked(true);
    setMappings(next);
    setDrafts((drafts) => reconcile(drafts, next.tabs));

    return true;
  };

  useEffect(() => {
    translateRef.current = t;
  }, [t]);

  useEffect(() => {
    activeControllerRef.current?.abort();
    const controller = new AbortController();
    const token: GoogleDocsMappingRequestToken = {
      generation: ++requestGenerationRef.current,
      planId,
    };
    activeControllerRef.current = controller;
    activePlanIdRef.current = planId;
    mappingsRef.current = null;
    operationInFlightRef.current = false;
    getWeeklyPlanGoogleDocsEmployeeMappings(planId, controller.signal)
      .then((next) => {
        if (!requestIsActive(controller, token)) return;
        applySnapshot(next, (current, tabs) =>
          Object.keys(current).length === 0
            ? createGoogleDocsMappingDrafts(tabs)
            : reconcileGoogleDocsMappingSnapshot(current, tabs),
        );
      })
      .catch((nextError: unknown) => {
        if (!requestIsActive(controller, token)) return;
        setError(
          nextError instanceof ApiServiceError && nextError.status === 403
            ? translateRef.current("errors.forbidden")
            : translateRef.current("errors.load"),
        );
      })
      .finally(() => {
        if (requestIsActive(controller, token)) {
          activeControllerRef.current = null;
          setLoading(false);
          setSavingTabId(null);
          setRefreshingTabId(null);
        }
      });

    return () => {
      controller.abort();
      activeControllerRef.current?.abort();
      activeControllerRef.current = null;
      requestGenerationRef.current += 1;
      operationInFlightRef.current = false;
    };
  }, [planId]);

  const updateDraft = (
    tabId: string,
    values: Parameters<typeof updateGoogleDocsMappingDraft>[2],
  ) => {
    setDrafts((current) =>
      updateGoogleDocsMappingDraft(current, tabId, values),
    );
    setError(null);
  };

  const chooseServerDraft = (tabId: string) => {
    if (!mappings) return;
    setDrafts((current) =>
      resetGoogleDocsMappingDraftFromServer(current, mappings.tabs, tabId),
    );
    setError(null);
  };

  const keepDraftAfterConflict = (tabId: string) => {
    if (!mappings) return;
    setDrafts((current) =>
      confirmGoogleDocsMappingDraft(current, mappings.tabs, tabId),
    );
    setError(null);
  };

  const refreshConflict = async (
    tabId: string,
    submittedExpectedVersion: number,
    controller: AbortController,
    token: GoogleDocsMappingRequestToken,
  ): Promise<boolean> => {
    try {
      const refreshed = await getWeeklyPlanGoogleDocsEmployeeMappings(
        planId,
        controller.signal,
      );
      if (!requestIsActive(controller, token)) return false;
      if (
        !isGoogleDocsMappingConflictRefreshTrusted(
          refreshed.tabs,
          tabId,
          submittedExpectedVersion,
        )
      ) {
        setError(translateRef.current("errors.conflictSnapshotStale"));
        return false;
      }
      if (
        !applySnapshot(refreshed, (current, tabs) =>
          reconcileGoogleDocsMappingConflict(current, tabs, tabId),
        )
      ) {
        setError(translateRef.current("errors.conflictRefresh"));
        return false;
      }

      setError(null);
      return true;
    } catch {
      if (requestIsActive(controller, token)) {
        setError(translateRef.current("errors.conflictRefresh"));
      }
      return false;
    }
  };

  const retryConflictRefresh = async (tabId: string) => {
    if (operationInFlightRef.current) return;
    const draft = drafts[tabId];
    if (
      !draft?.conflictRefreshPending ||
      !Number.isSafeInteger(draft.conflictExpectedVersion) ||
      draft.conflictExpectedVersion === null
    )
      return;

    const { controller, token } = startRequest();
    operationInFlightRef.current = true;
    setRefreshingTabId(tabId);
    setError(null);
    try {
      await refreshConflict(
        tabId,
        draft.conflictExpectedVersion,
        controller,
        token,
      );
    } finally {
      if (activeControllerRef.current === controller) {
        activeControllerRef.current = null;
        operationInFlightRef.current = false;
        if (!controller.signal.aborted) setRefreshingTabId(null);
      }
    }
  };

  const save = async (tabId: string) => {
    if (operationInFlightRef.current || writeBlocked) return;

    const draft = drafts[tabId];
    if (!draft || draft.conflict) return;
    if (!canSaveGoogleDocsMappingDraft(draft)) {
      setError(t("errors.employeeRequired"));
      return;
    }

    const { controller, token } = startRequest();
    const submitted = buildGoogleDocsMappingUpdateInput(tabId, draft);
    operationInFlightRef.current = true;
    setSavingTabId(tabId);
    setError(null);
    try {
      const next = await updateWeeklyPlanGoogleDocsEmployeeMapping(
        planId,
        submitted,
        controller.signal,
      );
      if (
        !requestIsActive(controller, token)
      )
        return;
      if (
        !applySnapshot(next, (current, tabs) =>
          reconcileGoogleDocsMappingSaveSuccess(current, tabs, tabId),
        )
      ) {
        setError(translateRef.current("errors.staleResponse"));
      }
    } catch (nextError) {
      if (
        !requestIsActive(controller, token) ||
        (nextError instanceof DOMException && nextError.name === "AbortError")
      )
        return;
      setError(
        nextError instanceof ApiServiceError &&
          [409, 422].includes(nextError.status)
          ? nextError.message
          : nextError instanceof ApiServiceError && nextError.status === 403
            ? t("errors.forbidden")
            : t("errors.save"),
      );
      const conflictKind = getGoogleDocsMappingConflictKind(nextError);
      if (conflictKind === "VERSION") {
        setDrafts((current) =>
          markGoogleDocsMappingConflictPending(
            current,
            submitted.tab_id,
            submitted.expected_version,
          ),
        );
        await refreshConflict(
          submitted.tab_id,
          submitted.expected_version,
          controller,
          token,
        );
      } else if (shouldBlockGoogleDocsMappingWrites(conflictKind)) {
        setWriteBlocked(true);
      }
    } finally {
      if (activeControllerRef.current === controller) {
        activeControllerRef.current = null;
        operationInFlightRef.current = false;
        if (!controller.signal.aborted) setSavingTabId(null);
      }
    }
  };

  return (
    <ComponentCard title={t("title")} desc={t("description")} compact>
      {error && (
        <p
          role="alert"
          className="rounded-lg bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
        >
          {error}
        </p>
      )}

      {loading && !mappings ? (
        <div className="h-28 animate-pulse rounded-xl bg-gray-100 dark:bg-gray-800" />
      ) : mappings?.tabs.length === 0 ? (
        <p className="text-theme-sm text-gray-500 dark:text-gray-400">
          {t("empty")}
        </p>
      ) : (
        <div className="space-y-3">
          {mappings?.tabs.map((tab) => {
            const draft = drafts[tab.tab_id];
            if (!draft) return null;
            const employeeOptions = mappings.employees.map((employee) => ({
              value: String(employee.id),
              label: employee.department
                ? `${employee.name} — ${employee.department.name}`
                : employee.name,
            }));

            return (
              <section
                key={tab.tab_id}
                className="rounded-xl border border-gray-200 p-4 dark:border-gray-800"
              >
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <h3 className="font-semibold text-gray-900 dark:text-white">
                        {tab.title || t("untitledTab")}
                      </h3>
                      <span className="rounded-full bg-gray-100 px-2.5 py-1 text-theme-xs font-medium text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                        {t(`statuses.${tab.status}`)}
                      </span>
                    </div>
                    <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                      {t("tabPosition", {
                        order: tab.order,
                        level: tab.nesting_level,
                      })}
                    </p>
                    {draft.dirty && !draft.conflict && (
                      <p className="mt-1 text-theme-xs font-medium text-brand-600 dark:text-brand-400">
                        {t("unsaved")}
                      </p>
                    )}
                    {tab.reason === "EMPLOYEE_INELIGIBLE" && (
                      <p className="mt-2 text-theme-xs text-warning-700 dark:text-warning-400">
                        {t("employeeIneligible")}
                      </p>
                    )}
                    {tab.suggestion && (
                      <div className="mt-3 flex flex-wrap items-center gap-2">
                        <p className="text-theme-xs text-gray-600 dark:text-gray-300">
                          {t("suggestion", {
                            name: tab.suggestion.employee.name,
                          })}
                        </p>
                        <button
                          type="button"
                          disabled={
                            !mappings.actions.can_update ||
                            writeBlocked ||
                            savingTabId !== null ||
                            refreshingTabId !== null ||
                            draft.conflict
                          }
                          className="text-theme-xs font-semibold text-brand-600 hover:text-brand-700 disabled:cursor-not-allowed disabled:opacity-50 dark:text-brand-400"
                          onClick={() =>
                            updateDraft(tab.tab_id, {
                              status: "VERIFIED",
                              employeeId: String(tab.suggestion?.employee.id),
                            })
                          }
                        >
                          {t("useSuggestion")}
                        </button>
                      </div>
                    )}
                  </div>

                  <div className="grid w-full gap-3 sm:grid-cols-2 lg:max-w-2xl lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)_auto]">
                    <div>
                      <Label htmlFor={`mapping-status-${tab.tab_id}`}>
                        {t("statusLabel")}
                      </Label>
                      <Select
                        id={`mapping-status-${tab.tab_id}`}
                        value={draft.status}
                        disabled={
                          !mappings.actions.can_update ||
                          writeBlocked ||
                          savingTabId !== null ||
                          refreshingTabId !== null ||
                          draft.conflict
                        }
                        options={[
                          { value: "VERIFIED", label: t("statuses.VERIFIED") },
                          {
                            value: "NEEDS_REVIEW",
                            label: t("statuses.NEEDS_REVIEW"),
                          },
                          {
                            value: "NOT_APPLICABLE",
                            label: t("statuses.NOT_APPLICABLE"),
                          },
                        ]}
                        onChange={(value) =>
                          updateDraft(tab.tab_id, {
                            status: value as typeof draft.status,
                            employeeId:
                              value === "VERIFIED" ? draft.employeeId : "",
                          })
                        }
                      />
                    </div>
                    <div>
                      <Label htmlFor={`mapping-employee-${tab.tab_id}`}>
                        {t("employeeLabel")}
                      </Label>
                      <Select
                        id={`mapping-employee-${tab.tab_id}`}
                        value={draft.employeeId}
                        disabled={
                          !mappings.actions.can_update ||
                          writeBlocked ||
                          savingTabId !== null ||
                          refreshingTabId !== null ||
                          draft.conflict ||
                          draft.status !== "VERIFIED"
                        }
                        placeholder={t("employeePlaceholder")}
                        options={employeeOptions}
                        onChange={(value) =>
                          updateDraft(tab.tab_id, { employeeId: value })
                        }
                      />
                    </div>
                    <div className="flex items-end">
                      <Button
                        type="button"
                        size="sm"
                        disabled={
                          !mappings.actions.can_update ||
                          writeBlocked ||
                          savingTabId !== null ||
                          refreshingTabId !== null ||
                          !canSaveGoogleDocsMappingDraft(draft)
                        }
                        onClick={() => void save(tab.tab_id)}
                      >
                        {savingTabId === tab.tab_id ? t("saving") : t("save")}
                      </Button>
                    </div>
                    {draft.conflict && (
                      <div className="sm:col-span-2 lg:col-span-3">
                        <div
                          role="alert"
                          className="rounded-lg border border-warning-200 bg-warning-50 p-3 text-theme-xs text-warning-800 dark:border-warning-900/50 dark:bg-warning-500/10 dark:text-warning-300"
                        >
                          <p>
                            {t(
                              draft.conflictRefreshPending
                                ? "conflict.refreshPending"
                                : "conflict.description",
                            )}
                          </p>
                          <div className="mt-3 flex flex-wrap gap-2">
                            {draft.conflictRefreshPending ? (
                              <Button
                                type="button"
                                size="sm"
                                disabled={refreshingTabId !== null}
                                onClick={() =>
                                  void retryConflictRefresh(tab.tab_id)
                                }
                              >
                                {refreshingTabId === tab.tab_id
                                  ? t("conflict.refreshing")
                                  : t("conflict.retryRefresh")}
                              </Button>
                            ) : (
                              <>
                                <Button
                                  type="button"
                                  size="sm"
                                  variant="outline"
                                  onClick={() => chooseServerDraft(tab.tab_id)}
                                >
                                  {t("conflict.useServer")}
                                </Button>
                                <Button
                                  type="button"
                                  size="sm"
                                  onClick={() =>
                                    keepDraftAfterConflict(tab.tab_id)
                                  }
                                >
                                  {t("conflict.keepDraft")}
                                </Button>
                              </>
                            )}
                          </div>
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              </section>
            );
          })}
        </div>
      )}

      <p className="text-theme-xs text-gray-500 dark:text-gray-400">
        {t("privacyNotice")}
      </p>
    </ComponentCard>
  );
}
