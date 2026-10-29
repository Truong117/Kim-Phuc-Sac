import ComponentCard from "@/components/common/ComponentCard";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import { ApiServiceError } from "@/services/apiClient";
import {
  getWeeklyPlanGoogleDocsEmployeeMappings,
  updateWeeklyPlanGoogleDocsEmployeeMappingsBatch,
} from "@/services/weeklyPlanningService";
import type { WeeklyPlanGoogleDocsEmployeeMappings } from "@/types/weeklyPlanning";
import {
  buildGoogleDocsMappingBatchUpdateInput,
  canSaveGoogleDocsMappingBatch,
  confirmGoogleDocsMappingDraft,
  createGoogleDocsMappingDrafts,
  getGoogleDocsMappingConflictKind,
  isGoogleDocsMappingBatchConflictRefreshTrusted,
  isGoogleDocsMappingSnapshotRegressive,
  markGoogleDocsMappingBatchConflictPending,
  reconcileGoogleDocsMappingBatchConflict,
  reconcileGoogleDocsMappingBatchSaveSuccess,
  resetGoogleDocsMappingDraftFromServer,
  shouldBlockGoogleDocsMappingWrites,
  shouldApplyGoogleDocsMappingResponse,
  updateGoogleDocsMappingDraft,
  type GoogleDocsMappingBatchUpdateInput,
  type GoogleDocsMappingDrafts,
  type GoogleDocsMappingRequestToken,
} from "@/utils/googleDocsEmployeeMappingDrafts";
import { useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";

type Props = {
  planId: number;
  mappings: WeeklyPlanGoogleDocsEmployeeMappings | null;
  readOnlyTabIds?: string[];
  onMappingsChange: (mappings: WeeklyPlanGoogleDocsEmployeeMappings) => void;
};

export default function GoogleDocsEmployeeMappingPanel({
  planId,
  mappings,
  readOnlyTabIds = [],
  onMappingsChange,
}: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.googleDocsEmployeeMapping",
  });
  const [drafts, setDrafts] = useState<GoogleDocsMappingDrafts>(() =>
    mappings ? createGoogleDocsMappingDrafts(mappings.tabs) : {},
  );
  const loading = mappings === null;
  const [savingBatch, setSavingBatch] = useState(false);
  const [refreshingConflicts, setRefreshingConflicts] = useState(false);
  const [conflictInputs, setConflictInputs] = useState<
    GoogleDocsMappingBatchUpdateInput[] | null
  >(null);
  const [writeBlocked, setWriteBlocked] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [saved, setSaved] = useState(false);
  const activeControllerRef = useRef<AbortController | null>(null);
  const requestGenerationRef = useRef(0);
  const activePlanIdRef = useRef(planId);
  const operationInFlightRef = useRef(false);
  const mappingsRef = useRef<WeeklyPlanGoogleDocsEmployeeMappings | null>(
    mappings,
  );
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
    onMappingsChange(next);
    setDrafts((drafts) => reconcile(drafts, next.tabs));

    return true;
  };

  useEffect(() => {
    translateRef.current = t;
  }, [t]);

  useEffect(() => {
    activePlanIdRef.current = planId;
    operationInFlightRef.current = false;

    return () => {
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
    setSaved(false);
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

  const refreshBatchConflict = async (
    submitted: GoogleDocsMappingBatchUpdateInput[],
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
        !isGoogleDocsMappingBatchConflictRefreshTrusted(
          refreshed.tabs,
          submitted,
        )
      ) {
        setError(translateRef.current("errors.conflictSnapshotStale"));
        return false;
      }
      if (
        !applySnapshot(refreshed, (current, tabs) =>
          reconcileGoogleDocsMappingBatchConflict(current, tabs, submitted),
        )
      ) {
        setError(translateRef.current("errors.conflictRefresh"));
        return false;
      }

      setConflictInputs(null);
      setError(translateRef.current("errors.batchConflict"));
      return true;
    } catch {
      if (requestIsActive(controller, token)) {
        setError(translateRef.current("errors.conflictRefresh"));
      }
      return false;
    }
  };

  const retryBatchConflictRefresh = async () => {
    if (operationInFlightRef.current || !conflictInputs) return;

    const { controller, token } = startRequest();
    operationInFlightRef.current = true;
    setRefreshingConflicts(true);
    setError(null);
    try {
      await refreshBatchConflict(conflictInputs, controller, token);
    } finally {
      if (activeControllerRef.current === controller) {
        activeControllerRef.current = null;
        operationInFlightRef.current = false;
        if (!controller.signal.aborted) setRefreshingConflicts(false);
      }
    }
  };

  const saveAll = async () => {
    if (operationInFlightRef.current || writeBlocked || !mappings) return;
    if (!canSaveGoogleDocsMappingBatch(mappings.tabs, drafts)) {
      setError(t("errors.employeeRequired"));
      return;
    }

    const { controller, token } = startRequest();
    const submitted = buildGoogleDocsMappingBatchUpdateInput(
      mappings.tabs,
      drafts,
    );
    operationInFlightRef.current = true;
    setSavingBatch(true);
    setSaved(false);
    setError(null);
    try {
      const next = await updateWeeklyPlanGoogleDocsEmployeeMappingsBatch(
        planId,
        submitted,
        controller.signal,
      );
      if (!requestIsActive(controller, token)) return;
      if (
        !applySnapshot(next, (_current, tabs) =>
          reconcileGoogleDocsMappingBatchSaveSuccess(tabs),
        )
      ) {
        setError(translateRef.current("errors.staleResponse"));
      } else {
        setConflictInputs(null);
        setSaved(true);
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
        setConflictInputs(submitted);
        setDrafts((current) =>
          markGoogleDocsMappingBatchConflictPending(current, submitted),
        );
        await refreshBatchConflict(submitted, controller, token);
      } else if (shouldBlockGoogleDocsMappingWrites(conflictKind)) {
        setWriteBlocked(true);
      }
    } finally {
      if (activeControllerRef.current === controller) {
        activeControllerRef.current = null;
        operationInFlightRef.current = false;
        if (!controller.signal.aborted) setSavingBatch(false);
      }
    }
  };

  const persistedCount =
    mappings?.tabs.filter((tab) =>
      ["VERIFIED", "NOT_APPLICABLE"].includes(tab.status),
    ).length ?? 0;
  const totalCount = mappings?.tabs.length ?? 0;
  const selectedCount =
    mappings?.tabs.filter((tab) => {
      const draft = drafts[tab.tab_id];

      return Boolean(
        draft &&
        (draft.status === "NOT_APPLICABLE" ||
          (draft.status === "VERIFIED" && draft.employeeId)),
      );
    }).length ?? 0;
  const pendingConfirmationCount =
    mappings?.tabs.filter((tab) => drafts[tab.tab_id]?.dirty).length ?? 0;
  const unresolvedCount = Math.max(totalCount - selectedCount, 0);
  const completed = totalCount > 0 && persistedCount === totalCount;
  const canSaveAll = Boolean(
    mappings &&
    mappings.actions.can_update &&
    !writeBlocked &&
    !savingBatch &&
    !refreshingConflicts &&
    canSaveGoogleDocsMappingBatch(mappings.tabs, drafts),
  );

  return (
    <ComponentCard title={t("title")} desc={t("description")} compact>
      {mappings && (
        <div className="rounded-xl bg-gray-50 px-4 py-3 dark:bg-gray-800/60">
          <p className="text-theme-sm font-semibold text-gray-900 dark:text-white">
            {t("selectionProgress", {
              selected: selectedCount,
              total: totalCount,
            })}
          </p>
          <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
            {t("persistedProgress", {
              persisted: persistedCount,
              total: totalCount,
            })}
          </p>
          {pendingConfirmationCount > 0 && (
            <p className="mt-2 text-theme-sm font-medium text-brand-600 dark:text-brand-400">
              {t("pendingConfirmation", {
                count: pendingConfirmationCount,
              })}
            </p>
          )}
          {unresolvedCount > 0 && (
            <p className="mt-1 text-theme-xs text-warning-700 dark:text-warning-400">
              {t("unresolved", { count: unresolvedCount })}
            </p>
          )}
          {completed && (
            <div className="mt-2 space-y-1 text-theme-sm text-success-700 dark:text-success-400">
              <p className="font-semibold">{t("completed")}</p>
              <p>{t("readyForAi")}</p>
            </div>
          )}
        </div>
      )}

      {saved && (
        <p
          role="status"
          className="rounded-lg bg-success-50 px-4 py-3 text-theme-sm text-success-700 dark:bg-success-500/10 dark:text-success-400"
        >
          {t("savedAll")}
        </p>
      )}

      {error && (
        <p
          role="alert"
          className="rounded-lg bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
        >
          {error}
        </p>
      )}
      {conflictInputs && (
        <div className="flex justify-end">
          <Button
            type="button"
            size="sm"
            disabled={refreshingConflicts || savingBatch}
            onClick={() => void retryBatchConflictRefresh()}
          >
            {refreshingConflicts
              ? t("conflict.refreshing")
              : t("conflict.retryRefresh")}
          </Button>
        </div>
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
            const isReadOnly = readOnlyTabIds.includes(tab.tab_id);
            const employeeOptions = mappings.employees.map((employee) => ({
              value: String(employee.id),
              label: employee.department
                ? `${employee.name} — ${employee.department.name}`
                : employee.name,
            }));

            return (
              <section
                key={tab.tab_id}
                className={`rounded-xl border p-4 ${
                  draft.prefillBasis === "CONFIRMED_ALIAS"
                    ? "border-warning-300 bg-warning-50/50 dark:border-warning-900/60 dark:bg-warning-500/10"
                    : "border-gray-200 dark:border-gray-800"
                }`}
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
                      {isReadOnly && (
                        <span className="rounded-full bg-success-50 px-2.5 py-1 text-theme-xs font-medium text-success-700 dark:bg-success-500/10 dark:text-success-400">
                          {t("processedReadonly")}
                        </span>
                      )}
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
                    {draft.prefillBasis && (
                      <div
                        className={`mt-3 rounded-lg border px-3 py-2 text-theme-xs font-medium ${
                          draft.prefillBasis === "CONFIRMED_ALIAS"
                            ? "border-warning-300 bg-warning-50 text-warning-800 dark:border-warning-900/60 dark:bg-warning-500/10 dark:text-warning-300"
                            : "border-brand-200 bg-brand-25 text-brand-700 dark:border-brand-900/50 dark:bg-brand-500/10 dark:text-brand-300"
                        }`}
                      >
                        {t(
                          draft.prefillBasis === "CONFIRMED_ALIAS"
                            ? "prefill.alias"
                            : "prefill.exact",
                        )}
                      </div>
                    )}
                    {tab.suggestion && !draft.prefillBasis && (
                      <div className="mt-3 flex flex-wrap items-center gap-2">
                        <p className="text-theme-xs text-gray-600 dark:text-gray-300">
                          {t(
                            tab.suggestion.basis === "CONFIRMED_ALIAS"
                              ? "suggestion.alias"
                              : "suggestion.exact",
                            {
                              name: tab.suggestion.employee.name,
                            },
                          )}
                        </p>
                        <button
                          type="button"
                          disabled={
                            !mappings.actions.can_update ||
                            isReadOnly ||
                            writeBlocked ||
                            savingBatch ||
                            refreshingConflicts ||
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

                  <div className="grid w-full gap-3 sm:grid-cols-2 lg:max-w-2xl">
                    <div>
                      <Label htmlFor={`mapping-status-${tab.tab_id}`}>
                        {t("statusLabel")}
                      </Label>
                      <Select
                        id={`mapping-status-${tab.tab_id}`}
                        value={draft.status}
                        disabled={
                          !mappings.actions.can_update ||
                          isReadOnly ||
                          writeBlocked ||
                          savingBatch ||
                          refreshingConflicts ||
                          draft.conflict
                        }
                        options={[
                          {
                            value: "VERIFIED",
                            label: t(
                              draft.dirty
                                ? "statuses.VERIFIED_PENDING"
                                : "statuses.VERIFIED",
                            ),
                          },
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
                          isReadOnly ||
                          writeBlocked ||
                          savingBatch ||
                          refreshingConflicts ||
                          draft.conflict ||
                          draft.status !== "VERIFIED"
                        }
                        placeholder={t("employeePlaceholder")}
                        options={employeeOptions}
                        onChange={(value) =>
                          updateDraft(tab.tab_id, { employeeId: value })
                        }
                      />
                      {draft.dirty &&
                        draft.status === "VERIFIED" &&
                        !draft.employeeId && (
                          <p className="mt-2 text-theme-xs text-error-600 dark:text-error-400">
                            {t("errors.employeeRequired")}
                          </p>
                        )}
                    </div>
                    {draft.conflict && (
                      <div className="sm:col-span-2">
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
                            {!draft.conflictRefreshPending && (
                              <>
                                <Button
                                  type="button"
                                  size="sm"
                                  variant="outline"
                                  disabled={savingBatch || refreshingConflicts}
                                  onClick={() => chooseServerDraft(tab.tab_id)}
                                >
                                  {t("conflict.useServer")}
                                </Button>
                                <Button
                                  type="button"
                                  size="sm"
                                  disabled={savingBatch || refreshingConflicts}
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

      {mappings && mappings.tabs.length > 0 && (
        <div className="flex justify-end">
          <Button
            type="button"
            disabled={!canSaveAll}
            className="bg-kps-primary hover:bg-kps-primary-hover"
            onClick={() => void saveAll()}
          >
            {savingBatch ? t("savingAll") : t("saveAll")}
          </Button>
        </div>
      )}

      <p className="text-theme-xs text-gray-500 dark:text-gray-400">
        {t("privacyNotice")}
      </p>
    </ComponentCard>
  );
}
