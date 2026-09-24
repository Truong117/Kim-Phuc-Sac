import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import ReportHistoryEmptyState from "@/components/reports/ReportHistoryEmptyState";
import ReportHistoryFilters from "@/components/reports/ReportHistoryFilters";
import ReportHistoryPagination from "@/components/reports/ReportHistoryPagination";
import ReportHistoryTable from "@/components/reports/ReportHistoryTable";
import { mockReportHistory } from "@/mocks/reportHistory";
import type { ReportHistoryFilterValues } from "@/types/reports";
import { useMemo, useState } from "react";
import { useTranslation } from "react-i18next";

const REPORTS_PER_PAGE = 8;

const initialFilters: ReportHistoryFilterValues = {
  fromDate: "",
  toDate: "",
  employeeId: "",
  department: "",
  status: "",
  search: "",
};

const employeeOptions = Array.from(
  new Map(
    mockReportHistory.map((report) => [report.employeeId, report.employeeName]),
  ),
).map(([value, label]) => ({ value, label }));

const departmentOptions = Array.from(
  new Set(mockReportHistory.map((report) => report.department)),
).map((department) => ({ value: department, label: department }));

export default function ReportHistory() {
  const { t } = useTranslation("common", { keyPrefix: "reportHistory" });
  const [filters, setFilters] =
    useState<ReportHistoryFilterValues>(initialFilters);
  const [currentPage, setCurrentPage] = useState(1);

  const filteredReports = useMemo(() => {
    const searchTerm = filters.search.trim().toLocaleLowerCase("vi-VN");

    return mockReportHistory.filter((report) => {
      const matchesSearch =
        !searchTerm ||
        [report.employeeName, report.department, report.primaryCategory ?? ""]
          .join(" ")
          .toLocaleLowerCase("vi-VN")
          .includes(searchTerm);

      return (
        (!filters.fromDate || report.reportDate >= filters.fromDate) &&
        (!filters.toDate || report.reportDate <= filters.toDate) &&
        (!filters.employeeId || report.employeeId === filters.employeeId) &&
        (!filters.department || report.department === filters.department) &&
        (!filters.status || report.status === filters.status) &&
        matchesSearch
      );
    });
  }, [filters]);

  const totalPages = Math.max(
    1,
    Math.ceil(filteredReports.length / REPORTS_PER_PAGE),
  );
  const firstReportIndex = (currentPage - 1) * REPORTS_PER_PAGE;
  const visibleReports = filteredReports.slice(
    firstReportIndex,
    firstReportIndex + REPORTS_PER_PAGE,
  );

  const handleFilterChange = <Key extends keyof ReportHistoryFilterValues>(
    field: Key,
    value: ReportHistoryFilterValues[Key],
  ) => {
    setFilters((currentFilters) => ({
      ...currentFilters,
      [field]: value,
    }));
    setCurrentPage(1);
  };

  const handleResetFilters = () => {
    setFilters(initialFilters);
    setCurrentPage(1);
  };

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
        <ReportHistoryFilters
          filters={filters}
          employeeOptions={employeeOptions}
          departmentOptions={departmentOptions}
          onChange={handleFilterChange}
          onReset={handleResetFilters}
        />

        {filteredReports.length === 0 ? (
          <ReportHistoryEmptyState onReset={handleResetFilters} />
        ) : (
          <>
            <ReportHistoryTable reports={visibleReports} />
            <ReportHistoryPagination
              currentPage={currentPage}
              totalPages={totalPages}
              totalItems={filteredReports.length}
              pageSize={REPORTS_PER_PAGE}
              onPageChange={setCurrentPage}
            />
          </>
        )}
      </div>
    </>
  );
}
