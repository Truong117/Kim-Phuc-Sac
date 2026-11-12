import { spaDateValid, spaShiftDate } from "../businessSelectors";
import { ArrowRightIcon } from "@/icons";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import { useTranslation } from "react-i18next";
export default function SpaDateFilter({
  date,
  onChange,
}: {
  date: string;
  onChange: (value: string) => void;
}) {
  const { t } = useTranslation();
  return (
    <div className="flex w-full items-center gap-2 sm:w-auto">
      <button
        type="button"
        disabled={!spaDateValid(date)}
        aria-label={t("spa.business.previousDate")}
        onClick={() => onChange(spaShiftDate(date, -1))}
        className="flex size-11 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
      >
        <ArrowRightIcon className="size-5 rotate-180 fill-current rtl:rotate-0" />
      </button>
      <div className="min-w-0 flex-1 sm:w-52">
        <KpsDatePicker
          value={date}
          onChange={onChange}
          ariaLabel={t("spa.flow.filterDate")}
          placeholder={t("spa.flow.filterDate")}
        />
      </div>
      <button
        type="button"
        disabled={!spaDateValid(date)}
        aria-label={t("spa.business.nextDate")}
        onClick={() => onChange(spaShiftDate(date, 1))}
        className="flex size-11 shrink-0 items-center justify-center rounded-lg border border-gray-200 bg-white text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300"
      >
        <ArrowRightIcon className="size-5 fill-current rtl:rotate-180" />
      </button>
    </div>
  );
}
