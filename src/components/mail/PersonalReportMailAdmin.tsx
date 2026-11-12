import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import Input from "@/components/form/input/InputField";
import Button from "@/components/ui/button/Button";
import {
  getReportMailRoster,
  type ReportMailRoster,
} from "@/services/reportMailService";
import { dateOnlyInTimeZone, formatDateOnly } from "@/utils/dateOnly";
import { lastReportSaturday } from "@/utils/personalReportWeek";

export default function PersonalReportMailAdmin() {
  const { t } = useTranslation("common", { keyPrefix: "reportMail" });
  const [open, setOpen] = useState(false);
  return (
    <details
      onToggle={(event) => setOpen(event.currentTarget.open)}
      className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3"
    >
      <summary className="cursor-pointer font-semibold text-gray-900 dark:text-white">
        {t("adminTitle")}
      </summary>
      {open && <Roster />}
    </details>
  );
}

function Roster() {
  const { t } = useTranslation("common", { keyPrefix: "reportMail" });
  const [endDate, setEndDate] = useState(() =>
    lastReportSaturday(dateOnlyInTimeZone()),
  );
  const [search, setSearch] = useState("");
  const [query, setQuery] = useState("");
  const [page, setPage] = useState(1);
  const [version, setVersion] = useState(0);
  const [result, setResult] = useState<ReportMailRoster | null>(null);
  const [error, setError] = useState(false);
  useEffect(() => {
    const timer = setTimeout(() => {
      setQuery(search);
      setPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [search]);
  useEffect(() => {
    const controller = new AbortController();
    getReportMailRoster(endDate, query, page, controller.signal)
      .then((data) => {
        if (!controller.signal.aborted) {
          setResult(data);
          setError(false);
        }
      })
      .catch(() => {
        if (!controller.signal.aborted) {
          setError(true);
          setResult(null);
        }
      });
    return () => controller.abort();
  }, [endDate, query, page, version]);
  return (
    <div className="mt-4 space-y-4 text-sm text-gray-700 dark:text-gray-300">
      <p className="leading-6">{t("adminHint")}</p>
      <div className="flex flex-wrap gap-3">
        <div className="w-full sm:w-56">
          <KpsDatePicker
            value={endDate}
            onChange={(date) => {
              setEndDate(date);
              setPage(1);
            }}
            ariaLabel={t("weekDate")}
            placeholder={t("weekDate")}
          />
        </div>
        <div className="w-full sm:max-w-sm">
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            aria-label={t("search")}
            placeholder={t("search")}
          />
        </div>
        <Button variant="outline" onClick={() => setVersion((v) => v + 1)}>
          {t("reload")}
        </Button>
      </div>
      {error && (
        <p role="alert" className="text-error-600 dark:text-error-400">
          {t("loadError")}
        </p>
      )}
      {result &&
        (!result.schema_ready ? (
          <p>{t("setupRequired")}</p>
        ) : (
          <>
            <p>
              {t(result.auto_enabled ? "adminEnabled" : "automationOff")}
              {result.start_date
                ? ` · ${t("startDate", { date: formatDateOnly(result.start_date) })}`
                : ""}
            </p>
            <p className="text-xs text-gray-500 dark:text-gray-400">
              {t("count", { count: result.total })}
            </p>
            <ul className="space-y-3">
              {result.recipients.map((row) => (
                <li
                  key={row.user_id}
                  className="space-y-1 rounded-lg border border-gray-200 p-3 dark:border-gray-700"
                >
                  <p className="font-medium text-gray-900 dark:text-white">
                    {row.name}
                    {row.department ? ` · ${row.department}` : ""}
                  </p>
                  <p className="break-all">{row.email}</p>
                  <p
                    className={
                      row.eligibility === "ready"
                        ? "text-success-700 dark:text-success-300"
                        : "text-warning-700 dark:text-warning-300"
                    }
                  >
                    {t(`adminEligibility.${row.eligibility}`)}
                  </p>
                  <p>
                    {t("deliveryLabel")}:{" "}
                    {t(`delivery.${row.delivery_status}`, {
                      defaultValue: t("delivery.failed"),
                    })}
                  </p>
                  {row.error_code && (
                    <p className="text-xs leading-6 text-gray-500 dark:text-gray-400">
                      {t(`reason.${row.error_code}`, { defaultValue: "" })}
                    </p>
                  )}
                </li>
              ))}
            </ul>
            {result.recipients.length === 0 && <p>{t("empty")}</p>}
            <div className="flex gap-3">
              <Button
                variant="outline"
                disabled={page <= 1}
                onClick={() => setPage((v) => v - 1)}
              >
                {t("previous")}
              </Button>
              <Button
                variant="outline"
                disabled={page >= result.last_page}
                onClick={() => setPage((v) => v + 1)}
              >
                {t("next")}
              </Button>
            </div>
          </>
        ))}
    </div>
  );
}
