export type ReportHoliday = {
  id: number;
  name: string;
  start_date: string;
  end_date: string;
  note: string | null;
  is_active: boolean;
  version: number;
  reporting_days: number;
  created_at: string;
  updated_at: string;
};

export type ReportHolidayInput = Pick<
  ReportHoliday,
  "name" | "start_date" | "end_date" | "note"
>;

export type ReportHolidaysResponse = {
  data: ReportHoliday[];
  meta: { business_date: string; working_days: number[]; can_manage: boolean };
};
