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
    <div className="w-full sm:w-60">
      <KpsDatePicker
        value={date}
        onChange={onChange}
        ariaLabel={t("spa.flow.filterDate")}
        placeholder={t("spa.flow.filterDate")}
      />
    </div>
  );
}
