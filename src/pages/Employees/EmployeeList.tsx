import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import EmployeeFilters from "@/components/employees/EmployeeFilters";
import EmployeePagination from "@/components/employees/EmployeePagination";
import EmployeeTable from "@/components/employees/EmployeeTable";
import Button from "@/components/ui/button/Button";
import { PlusIcon } from "@/icons";
import { ApiServiceError } from "@/services/apiClient";
import { getUserReferences, getUsers } from "@/services/userService";
import type {
  UserListFilters,
  UserListResponse,
  UserReferences,
} from "@/types/userManagement";
import { useCallback, useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";

const PAGE_SIZE = 20;

const initialFilters: UserListFilters = {
  search: "",
  departmentId: "",
  roleId: "",
  status: "",
};

const initialReferences: UserReferences = {
  roles: [],
  departments: [],
  locations: [],
};

const isAbortError = (error: unknown) =>
  error instanceof DOMException && error.name === "AbortError";

export default function EmployeeList() {
  const { t } = useTranslation("common", { keyPrefix: "userManagement" });
  const [filters, setFilters] = useState<UserListFilters>(initialFilters);
  const [queryFilters, setQueryFilters] =
    useState<UserListFilters>(initialFilters);
  const [currentPage, setCurrentPage] = useState(1);
  const [result, setResult] = useState<UserListResponse | null>(null);
  const [references, setReferences] =
    useState<UserReferences>(initialReferences);
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

    getUserReferences(abortController.signal)
      .then((nextReferences) => {
        setReferences(nextReferences);
        setReferenceError(null);
      })
      .catch((error: unknown) => {
        if (isAbortError(error)) return;
        setReferenceError(t("errors.references"));
      })
      .finally(() => {
        if (!abortController.signal.aborted) setIsReferenceLoading(false);
      });

    return () => abortController.abort();
  }, [referenceRequestVersion, t]);

  useEffect(() => {
    const abortController = new AbortController();

    getUsers(
      {
        ...queryFilters,
        page: currentPage,
        perPage: PAGE_SIZE,
      },
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
          setListError(t("errors.loadList"));
        }
      })
      .finally(() => {
        if (!abortController.signal.aborted) setIsLoading(false);
      });

    return () => abortController.abort();
  }, [currentPage, listRequestVersion, queryFilters, t]);

  const handleFilterChange = useCallback(
    <Key extends keyof UserListFilters>(
      field: Key,
      value: UserListFilters[Key],
    ) => {
      setFilters((current) => ({ ...current, [field]: value }));
      setCurrentPage(1);
    },
    [],
  );

  const handleReset = () => {
    setFilters(initialFilters);
    setCurrentPage(1);
  };

  const handlePageChange = (page: number) => {
    setIsLoading(true);
    setCurrentPage(page);
  };

  const retryList = () => {
    setIsLoading(true);
    setListError(null);
    setListRequestVersion((current) => current + 1);
  };

  const retryReferences = () => {
    setIsReferenceLoading(true);
    setReferenceError(null);
    setReferenceRequestVersion((current) => current + 1);
  };

  const hasFilters = Boolean(
    filters.search.trim() ||
    filters.departmentId ||
    filters.roleId ||
    filters.status,
  );

  return (
    <>
      <PageMeta
        title={`${t("list.title")} | KIM PHỤC SẮC`}
        description={t("list.metaDescription")}
      />
      <PageBreadCrumb pageTitle={t("list.title")} />

      <div className="-mt-4 mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-theme-sm text-gray-500 dark:text-gray-400">
          {t("list.subtitle")}
        </p>
        <Link
          to="/employees/new"
          className="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-kps-primary px-5 py-3.5 text-theme-sm font-medium text-white shadow-theme-xs transition hover:bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none dark:bg-kps-primary dark:hover:bg-kps-primary-hover"
        >
          <PlusIcon className="size-5" />
          {t("list.add")}
        </Link>
      </div>

      <div className="space-y-6">
        {referenceError && (
          <div
            role="alert"
            className="flex flex-col gap-3 rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-theme-sm text-warning-800 sm:flex-row sm:items-center sm:justify-between dark:border-warning-800 dark:bg-warning-500/10 dark:text-warning-300"
          >
            <span>{referenceError}</span>
            <button
              type="button"
              onClick={retryReferences}
              className="self-start rounded font-semibold underline underline-offset-2 focus-visible:ring-2 focus-visible:ring-warning-500/40 focus-visible:outline-none sm:self-auto"
            >
              {t("actions.retry")}
            </button>
          </div>
        )}

        <EmployeeFilters
          filters={filters}
          departments={references.departments}
          roles={references.roles}
          isReferenceLoading={isReferenceLoading}
          onChange={handleFilterChange}
          onReset={handleReset}
        />

        {listError ? (
          <section className="rounded-2xl border border-error-200 bg-white px-6 py-12 text-center dark:border-error-800 dark:bg-white/3">
            <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
              {t("errors.title")}
            </h2>
            <p className="mt-2 text-theme-sm text-error-600 dark:text-error-400">
              {listError}
            </p>
            <Button
              type="button"
              variant="outline"
              className="mt-5"
              onClick={retryList}
            >
              {t("actions.retry")}
            </Button>
          </section>
        ) : isLoading && !result ? (
          <div
            role="status"
            className="rounded-2xl border border-gray-200 bg-white px-6 py-14 text-center text-theme-sm text-gray-500 dark:border-gray-800 dark:bg-white/3 dark:text-gray-400"
          >
            {t("list.loading")}
          </div>
        ) : result?.data.length === 0 ? (
          <section className="rounded-2xl border border-gray-200 bg-white px-6 py-12 text-center dark:border-gray-800 dark:bg-white/3">
            <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
              {t(hasFilters ? "list.emptyFiltered" : "list.empty")}
            </h2>
            <p className="mt-2 text-theme-sm text-gray-500 dark:text-gray-400">
              {t(
                hasFilters
                  ? "list.emptyFilteredDescription"
                  : "list.emptyDescription",
              )}
            </p>
            {hasFilters && (
              <Button
                type="button"
                variant="outline"
                className="mt-5"
                onClick={handleReset}
              >
                {t("filters.reset")}
              </Button>
            )}
          </section>
        ) : result ? (
          <div className={isLoading ? "opacity-60" : undefined}>
            <EmployeeTable users={result.data} />
            <div className="mt-6">
              <EmployeePagination
                currentPage={result.meta.current_page}
                totalPages={result.meta.last_page}
                totalItems={result.meta.total}
                pageSize={result.meta.per_page}
                from={result.meta.from}
                to={result.meta.to}
                isLoading={isLoading}
                onPageChange={handlePageChange}
              />
            </div>
          </div>
        ) : null}
      </div>
    </>
  );
}
