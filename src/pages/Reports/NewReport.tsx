import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import Form from "@/components/form/Form";
import ReportInfo from "@/components/reports/ReportInfo";
import ReportSaveModal, {
  type ReportSaveState,
} from "@/components/reports/ReportSaveModal";
import ReportStatusBadge from "@/components/reports/ReportStatusBadge";
import ReportWorkItemDetails from "@/components/reports/ReportWorkItemDetails";
import WorkItemList from "@/components/reports/WorkItemList";
import Button from "@/components/ui/button/Button";
import { useAuth } from "@/hooks/useAuth";
import { DocsIcon, TimeIcon } from "@/icons";
import { ApiServiceError } from "@/services/apiClient";
import {
  createReport,
  getTodayReport,
  updateReport,
} from "@/services/reportService";
import type {
  DailyReport,
  SaveReportInput,
  TodayReportState,
  WorkItemField,
  WorkItemFormData,
  WorkItemTouchedFields,
  WorkItemValidationErrors,
} from "@/types/reports";
import { useCallback, useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link, useNavigate } from "react-router";

const isAbortError = (error: unknown) =>
  error instanceof DOMException && error.name === "AbortError";

const createClientId = () => {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
    return crypto.randomUUID();
  }
  return `work-item-${Date.now()}-${Math.random().toString(36).slice(2)}`;
};

const createEmptyWorkItem = (): WorkItemFormData => ({
  clientId: createClientId(),
  content: "",
  result: "",
  status: "",
  note: "",
});

const mapReportItems = (report: DailyReport): WorkItemFormData[] =>
  report.items.map((item) => ({
    clientId: `report-item-${item.id}`,
    content: item.content,
    result: item.result,
    status: item.status,
    note: item.note ?? "",
  }));

const formatDate = (value: string) =>
  new Intl.DateTimeFormat("vi-VN").format(new Date(`${value}T00:00:00`));

const formatTime = (value: string) =>
  new Intl.DateTimeFormat("vi-VN", {
    hour: "2-digit",
    minute: "2-digit",
    timeZone: "Asia/Ho_Chi_Minh",
  }).format(new Date(value));

interface ReportStateActionsProps {
  homeLabel: string;
  historyLabel: string;
}

const ReportStateActions = ({
  homeLabel,
  historyLabel,
}: ReportStateActionsProps) => (
  <div className="flex flex-col gap-3 sm:flex-row sm:justify-end">
    <Link
      to="/"
      className="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-4 py-3 text-theme-sm font-semibold text-gray-700 transition-colors hover:bg-gray-50 focus-visible:ring-3 focus-visible:ring-kps-primary/30 focus-visible:outline-none sm:w-auto dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
    >
      {homeLabel}
    </Link>
    <Link
      to="/reports"
      className="inline-flex items-center justify-center rounded-lg bg-kps-primary px-4 py-3 text-theme-sm font-semibold text-white transition-colors hover:bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none sm:w-auto"
    >
      {historyLabel}
    </Link>
  </div>
);

export default function NewReport() {
  const { t } = useTranslation("common", { keyPrefix: "dailyReport" });
  const { currentUser } = useAuth();
  const navigate = useNavigate();
  const [today, setToday] = useState<TodayReportState | null>(null);
  const [items, setItems] = useState<WorkItemFormData[]>([
    createEmptyWorkItem(),
  ]);
  const [touchedFields, setTouchedFields] = useState<
    Record<string, WorkItemTouchedFields>
  >({});
  const [submitAttempted, setSubmitAttempted] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [saveState, setSaveState] = useState<ReportSaveState>({
    status: "idle",
  });
  const saveInFlightRef = useRef(false);

  const applyTodayState = useCallback((state: TodayReportState) => {
    setToday(state);
    setItems(
      state.report ? mapReportItems(state.report) : [createEmptyWorkItem()],
    );
    setTouchedFields({});
    setSubmitAttempted(false);
  }, []);

  const loadToday = useCallback(
    async (signal?: AbortSignal) => {
      try {
        applyTodayState(await getTodayReport(signal));
        setError(null);
      } catch (loadError) {
        if (isAbortError(loadError)) return;
        if (loadError instanceof ApiServiceError && loadError.status === 0) {
          setError(t("errors.network"));
        } else {
          setError(t("errors.load"));
        }
      } finally {
        if (!signal?.aborted) setIsLoading(false);
      }
    },
    [applyTodayState, t],
  );

  useEffect(() => {
    const abortController = new AbortController();
    getTodayReport(abortController.signal)
      .then((state) => {
        applyTodayState(state);
        setError(null);
      })
      .catch((loadError: unknown) => {
        if (isAbortError(loadError)) return;
        if (loadError instanceof ApiServiceError && loadError.status === 0) {
          setError(t("errors.network"));
        } else {
          setError(t("errors.load"));
        }
      })
      .finally(() => {
        if (!abortController.signal.aborted) setIsLoading(false);
      });
    return () => abortController.abort();
  }, [applyTodayState, t]);

  const validationErrors = items.reduce<
    Record<string, WorkItemValidationErrors>
  >((errors, item) => {
    const itemErrors: WorkItemValidationErrors = {};
    if (!item.content.trim()) itemErrors.content = t("validation.content");
    if (!item.result.trim()) itemErrors.result = t("validation.result");
    if (!item.status) itemErrors.status = t("validation.status");
    if (Object.keys(itemErrors).length > 0) {
      errors[item.clientId] = itemErrors;
    }
    return errors;
  }, {});

  const visibleErrors = Object.fromEntries(
    Object.entries(validationErrors).map(([clientId, itemErrors]) => [
      clientId,
      Object.fromEntries(
        Object.entries(itemErrors).filter(([field]) =>
          Boolean(
            submitAttempted ||
              touchedFields[clientId]?.[field as WorkItemField],
          ),
        ),
      ) as WorkItemValidationErrors,
    ]),
  );

  const handleItemChange = (
    clientId: string,
    changes: Partial<Omit<WorkItemFormData, "clientId">>,
  ) => {
    setItems((current) =>
      current.map((item) =>
        item.clientId === clientId ? { ...item, ...changes } : item,
      ),
    );
  };

  const handleItemBlur = (clientId: string, field: WorkItemField) => {
    setTouchedFields((current) => ({
      ...current,
      [clientId]: { ...current[clientId], [field]: true },
    }));
  };

  const handleDeleteItem = (clientId: string) => {
    setItems((current) =>
      current.length === 1
        ? current
        : current.filter((item) => item.clientId !== clientId),
    );
    setTouchedFields((current) => {
      const next = { ...current };
      delete next[clientId];
      return next;
    });
  };

  const resetItems = () => {
    setItems(
      today?.report ? mapReportItems(today.report) : [createEmptyWorkItem()],
    );
    setTouchedFields({});
    setSubmitAttempted(false);
    setError(null);
  };

  const handleSubmit = async () => {
    if (saveInFlightRef.current || saveState.status !== "idle") return;

    setSubmitAttempted(true);
    setError(null);
    if (Object.keys(validationErrors).length > 0 || !today) return;

    const input: SaveReportInput = {
      items: items.map((item) => ({
        content: item.content.trim(),
        result: item.result.trim(),
        status: item.status || "IN_PROGRESS",
        note: item.note.trim() || null,
      })),
    };

    saveInFlightRef.current = true;
    setSaveState({ status: "saving" });
    try {
      const savedReport = today.report
        ? await updateReport(today.report.id, input)
        : await createReport(input);
      setToday((current) =>
        current
          ? {
              ...current,
              report: savedReport,
              actions: { can_create: false },
            }
          : current,
      );
      setSaveState({
        status: "success",
        reportDate: savedReport.report_date,
      });
    } catch (submitError) {
      setSaveState({ status: "idle" });
      if (submitError instanceof ApiServiceError) {
        setError(
          submitError.status === 0
            ? t("errors.network")
            : submitError.message || t("errors.save"),
        );

        if (submitError.status === 409) {
          try {
            applyTodayState(await getTodayReport());
          } catch {
            // Keep the safe conflict message when the follow-up refresh fails.
          }
        }
      } else {
        setError(t("errors.save"));
      }
    } finally {
      saveInFlightRef.current = false;
    }
  };

  const isSaving = saveState.status === "saving";
  const isSaveFlowActive = saveState.status !== "idle";

  const employeeName =
    today?.report?.employee.name ?? currentUser?.name ?? t("unknown");
  const department =
    today?.report?.department?.name ??
    currentUser?.department?.name ??
    t("notAssigned");
  const isClosed = Boolean(today?.is_window_closed);
  const isReadOnly = Boolean(
    today?.report && (today.report.is_locked || !today.report.actions.can_edit),
  );
  const cannotCreate = Boolean(
    today && !today.report && !today.actions.can_create,
  );

  return (
    <>
      <PageMeta
        title={`${t("title")} | KIM PHỤC SẮC`}
        description={t("metaDescription")}
      />
      <PageBreadCrumb pageTitle={t("title")} />
      <p className="-mt-4 mb-6 text-theme-sm text-gray-500 dark:text-gray-400">
        {t("subtitle")}
      </p>

      <ReportSaveModal
        state={saveState}
        onGoHome={() => navigate("/")}
        onReview={() => navigate("/reports")}
      />

      {isLoading && !today ? (
        <div
          role="status"
          className="rounded-2xl border border-gray-200 bg-white px-6 py-14 text-center text-theme-sm text-gray-500 dark:border-gray-800 dark:bg-white/3 dark:text-gray-400"
        >
          {t("loading")}
        </div>
      ) : error && !today ? (
        <section className="rounded-2xl border border-error-200 bg-white px-6 py-12 text-center dark:border-error-800 dark:bg-white/3">
          <p className="text-theme-sm text-error-600 dark:text-error-400">
            {error}
          </p>
          <Button
            type="button"
            variant="outline"
            className="mt-5"
            onClick={() => {
              setIsLoading(true);
              void loadToday();
            }}
          >
            {t("actions.retry")}
          </Button>
        </section>
      ) : today?.reporting_required === false ? (
        <div className="space-y-6">
          <section className="rounded-2xl border border-kps-primary/20 bg-white px-6 py-10 text-center shadow-theme-xs dark:border-kps-primary/30 dark:bg-white/3">
            <span className="mx-auto flex size-12 items-center justify-center rounded-full bg-kps-primary/10 text-kps-primary dark:bg-kps-primary/20 dark:text-sidebar-selected">
              <DocsIcon className="size-6" aria-hidden="true" />
            </span>
            <h2 className="mt-5 text-lg font-semibold text-gray-900 dark:text-white">
              {t("notRequired.title")}
            </h2>
            <p className="mx-auto mt-2 max-w-xl text-theme-sm text-gray-600 dark:text-gray-300">
              {t("notRequired.description")}
            </p>
          </section>
          <ReportStateActions
            homeLabel={t("actions.goHome")}
            historyLabel={t("actions.viewHistory")}
          />
        </div>
      ) : today ? (
        <div className="space-y-6">
          <ReportInfo
            employeeName={employeeName}
            department={department}
            reportDate={today.business_date}
          />

          {error && (
            <div
              role="alert"
              className="rounded-xl border border-error-200 bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:border-error-800 dark:bg-error-500/10 dark:text-error-300"
            >
              {error}
            </div>
          )}

          {today.report && isReadOnly ? (
            <>
              <section className="rounded-xl border border-gray-200 bg-white p-5 shadow-theme-xs sm:p-6 dark:border-gray-800 dark:bg-white/3">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                  <div>
                    <span className="inline-flex rounded-full bg-kps-primary/10 px-3 py-1 text-theme-xs font-semibold text-kps-primary dark:bg-kps-primary/20 dark:text-sidebar-selected">
                      {t(
                        today.report.is_locked
                          ? "locked.badge"
                          : "readOnly.badge",
                      )}
                    </span>
                    <h2 className="mt-3 text-lg font-semibold text-gray-900 dark:text-white">
                      {t(
                        today.report.is_locked
                          ? "locked.title"
                          : "readOnly.title",
                      )}
                    </h2>
                    <p className="mt-1 text-theme-sm text-gray-600 dark:text-gray-300">
                      {t(
                        today.report.is_locked
                          ? "locked.description"
                          : "readOnly.description",
                        {
                          date: formatDate(today.report.report_date),
                          cutoff: formatTime(today.report.locked_at),
                        },
                      )}
                    </p>
                  </div>
                  <ReportStatusBadge status={today.report.overall_status} />
                </div>
              </section>
              <ReportWorkItemDetails items={today.report.items} />
              <ReportStateActions
                homeLabel={t("actions.goHome")}
                historyLabel={t("actions.viewHistory")}
              />
            </>
          ) : !today.report && (isClosed || cannotCreate) ? (
            <>
              <section className="rounded-2xl border border-warning-200 bg-warning-50 px-6 py-9 text-center dark:border-warning-800 dark:bg-warning-500/10">
                <TimeIcon className="mx-auto size-9 text-warning-600 dark:text-warning-400" />
                <h2 className="mt-4 text-lg font-semibold text-gray-900 dark:text-white">
                  {t(isClosed ? "closed.title" : "unavailable.title")}
                </h2>
                <p className="mt-2 text-theme-sm text-gray-600 dark:text-gray-300">
                  {t(
                    isClosed
                      ? "closed.description"
                      : "unavailable.description",
                    {
                      date: formatDate(today.business_date),
                      cutoff: formatTime(today.cutoff_at),
                    },
                  )}
                </p>
              </section>
              <ReportStateActions
                homeLabel={t("actions.goHome")}
                historyLabel={t("actions.viewHistory")}
              />
            </>
          ) : (
            <>
              {today.report && (
                <section className="rounded-xl border border-kps-primary/20 bg-kps-primary/5 px-4 py-4 dark:border-kps-primary/30 dark:bg-kps-primary/10">
                  <span className="inline-flex rounded-full bg-kps-primary px-3 py-1 text-theme-xs font-semibold text-white">
                    {t("existing.badge")}
                  </span>
                  <h2 className="mt-3 font-semibold text-gray-900 dark:text-white">
                    {t("existing.title")}
                  </h2>
                  <p className="mt-1 text-theme-sm text-gray-600 dark:text-gray-300">
                    {t("existing.description", {
                      cutoff: formatTime(today.report.locked_at),
                    })}
                  </p>
                </section>
              )}

              <Form onSubmit={() => void handleSubmit()} className="space-y-6">
                <WorkItemList
                  items={items}
                  errors={visibleErrors}
                  onItemChange={handleItemChange}
                  onItemBlur={handleItemBlur}
                  onItemAdd={() =>
                    setItems((current) => [...current, createEmptyWorkItem()])
                  }
                  onItemDelete={handleDeleteItem}
                />

                <div className="flex flex-col-reverse gap-3 rounded-2xl border border-gray-200 bg-white p-5 sm:flex-row sm:justify-end dark:border-gray-800 dark:bg-white/3">
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="w-full sm:w-auto"
                    onClick={resetItems}
                    disabled={isSaveFlowActive}
                  >
                    {t("actions.reset")}
                  </Button>
                  <Button
                    type="submit"
                    size="sm"
                    className="w-full !bg-kps-primary hover:!bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none sm:w-auto"
                    disabled={isSaveFlowActive}
                  >
                    {isSaving
                      ? t("actions.saving")
                      : t(today.report ? "actions.update" : "actions.save")}
                  </Button>
                </div>
              </Form>
            </>
          )}
        </div>
      ) : null}
    </>
  );
}
