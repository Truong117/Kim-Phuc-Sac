import type { WeeklyReportingPerson } from "@/types/dashboard";

const normalizeSearch = (value: string) =>
  value
    .normalize("NFD")
    .replace(/\p{M}/gu, "")
    .replace(/[đĐ]/g, "d")
    .toLocaleLowerCase("vi");

export const filterWeeklyReporters = (
  rows: WeeklyReportingPerson[],
  search: string,
  department: string,
  missingOnly: boolean,
) => {
  const query = normalizeSearch(search.trim());
  return rows.filter(
    (row) =>
      (!query || normalizeSearch(row.employee.name).includes(query)) &&
      (!department ||
        String(row.department?.id ?? "unassigned") === department) &&
      (!missingOnly || row.missing_dates.length > 0),
  );
};
