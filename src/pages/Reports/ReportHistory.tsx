import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import ReportHistoryEmptyState from "@/components/reports/ReportHistoryEmptyState";
import ReportHistoryFilters from "@/components/reports/ReportHistoryFilters";
import ReportHistoryPagination from "@/components/reports/ReportHistoryPagination";
import ReportHistoryTable from "@/components/reports/ReportHistoryTable";
import Button from "@/components/ui/button/Button";
import { ApiServiceError } from "@/services/apiClient";
import {
  getReportReferences,
  getReports,
} from "@/services/reportService";
import type {
  ReportHistoryFilterValues,
  ReportListResponse,
  ReportReferences,
} from "@/types/reports";
import { useCallback, useEffect, useState } from "react";
import { useTranslation } from "react-i18next";

const PAGE_SIZE = 20;

const initialFilters: ReportHistoryFilterValues = {
  fromDate: "",
  toDate: "",
  employeeId: "",
  departmentId: "",
  locationId: "",
  status: "",
  search: "",
};

const initialReferences: ReportReferences = {
  employees: { visible: false, options: [] },
  departments: { visible: false, options: [] },
  locations: { visible: false, options: [] },
};

const isAbortError = (error: unknown) =>
  error instanceof DOMException && error.name === "AbortError";

export default function ReportHistory() {
  const { t } = useTranslation("common", { keyPrefix: "reportHistory" });
  const [filters, setFilters] =
    useState<ReportHistoryFilterValues>(initialFilters);
  const [queryFilters, setQueryFilters] =
    useState<ReportHistoryFilterValues>(initialFilters);
  const [currentPage, setCurrentPage] = useState(1);
  const [result, setResult] = useState<ReportListResponse | null>(null);
  const [references, setReferences] =
    useState<ReportReferences>(initialReferences);
  const [isLoading, setIsLoading] = useState(true);
  const [isReferenceLoading, setIsReferenceLoading] = useState(true);
  const [listError, setListError] = useState<string | null>(null);
  const [referenceError, setReferenceError] = useState<string | null>(null);
  const [listRequestVersion, setListRequestVersion] = useState(0);
  const [referenceRequestVersion, setReferenceRequestVersion] = useState(0);

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      setQueryFilters({ ...filters });
      setIsLoading(true);
      setListError(null);
    }, 300);
    return () => window.clearTimeout(timeout);
  }, [filters]);

  useEffect(() => {
    const abortController = new AbortController();
    getReportReferences(abortController.signal)
      .then((nextReferences) => {
        setReferences(nextReferences);
        setReferenceError(null);
      })
      .catch((error: unknown) => {
        if (!isAbortError(error)) setReferenceError(t("errors.references"));
      })
      .finally(() => {
        if (!abortController.signal.aborted) setIsReferenceLoading(false);
      });
    return () => abortController.abort();
  }, [referenceRequestVersion, t]);

  useEffect(() => {
    const abortController = new AbortController();
    getReports(
      { ...queryFilters, page: currentPage, perPage: PAGE_SIZE },
      abortController.signal,
    )
      .then((nextResult) => {
        setResult(nextResult);
        setListError(null);
      })
      .catch((error: unknown) => {
        if (isAbortError(error)) return;
        if (error instanceof ApiServiceError && error.status === 403) {
          setListError(t("errors.forbidden"));
        } else if (error instanceof ApiServiceError && error.status === 0) {
          setListError(t("errors.network"));
        } else {
          setListError(t("errors.load"));
        }
      })
      .finally(() => {
        if (!abortController.signal.aborted) setIsLoading(false);
      });
    return () => abortController.abort();
  }, [currentPage, listRequestVersion, queryFilters, t]);

  const handleFilterChange = useCallback(
    <Key extends keyof ReportHistoryFilterValues>(
      field: Key,
      value: ReportHistoryFilterValues[Key],
    ) => {
      setFilters((current) => ({ ...current, [field]: value }));
      setCurrentPage(1);
    },
    [],
  );

  const handleResetFilters = () => {
    setFilters(initialFilters);
    setCurrentPage(1);
  };

  const hasFilters = Boolean(
    filters.fromDate ||
      filters.toDate ||
      filters.employeeId ||
      filters.departmentId ||
      filters.locationId ||
      filters.status ||
      filters.search.trim(),
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

      <div className="space-y-6">
        {referenceError && (
          <div
            role="alert"
            className="flex flex-col gap-3 rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-theme-sm text-warning-800 sm:flex-row sm:items-center sm:justify-between dark:border-warning-800 dark:bg-warning-500/10 dark:text-warning-300"
          >
            <span>{referenceError}</span>
            <button
              type="button"
              onClick={() => {
                setIsReferenceLoading(true);
                setReferenceRequestVersion((current) => current + 1);
              }}
              className="self-start rounded font-semibold underline underline-offset-2 focus-visible:ring-2 focus-visible:ring-warning-500/40 focus-visible:outline-none sm:self-auto"
            >
              {t("actions.retry")}
            </button>
          </div>
        )}

        <ReportHistoryFilters
          filters={filters}
          references={references}
          isReferenceLoading={isReferenceLoading}
          onChange={handleFilterChange}
          onReset={handleResetFilters}
        />

        {listError ? (
          <section className="rounded-2xl border border-error-200 bg-white px-6 py-12 text-center dark:border-error-800 dark:bg-white/3">
            <p className="text-theme-sm text-error-600 dark:text-error-400">
              {listError}
            </p>
            <Button
              type="button"
              variant="outline"
              className="mt-5"
              onClick={() => {
                setIsLoading(true);
                setListRequestVersion((current) => current + 1);
              }}
            >
              {t("actions.retry")}
            </Button>
          </section>
        ) : isLoading && !result ? (
          <div
            role="status"
            className="rounded-2xl border border-gray-200 bg-white px-6 py-14 text-center text-theme-sm text-gray-500 dark:border-gray-800 dark:bg-white/3 dark:text-gray-400"
          >
            {t("loading")}
          </div>
        ) : result?.data.length === 0 ? (
          <ReportHistoryEmptyState
            onReset={handleResetFilters}
            hasFilters={hasFilters}
          />
        ) : result ? (
          <div className={isLoading ? "opacity-60" : undefined}>
            <ReportHistoryTable reports={result.data} />
            <div className="mt-6">
              <ReportHistoryPagination
                currentPage={result.meta.current_page}
                totalPages={result.meta.last_page}
                totalItems={result.meta.total}
                pageSize={result.meta.per_page}
                onPageChange={(page) => {
                  setIsLoading(true);
                  setCurrentPage(page);
                }}
              />
            </div>
          </div>
        ) : null}
      </div>
    </>
  );
}
