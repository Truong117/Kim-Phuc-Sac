import { useState } from "react";
import { useTranslation } from "react-i18next";
import Label from "@/components/form/Label";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import { useSpaPreview } from "../../hooks/useSpaPreviewSnapshot";
import {
  spaReport,
  spaMoney,
  spaShiftDate,
  spaTaskPrice,
} from "../../businessSelectors";
import { downloadSpaCsv } from "../../csv";
import {
  SpaAction,
  SpaStats,
  SpaTabs,
  SpaTable,
  SpaHint,
  spaPanel,
} from "./SpaBusinessUi";
export default function SpaReportsView() {
  const { t } = useTranslation();
  const b = (k: string) => t(`spa.business.${k}`);
  const { snapshot } = useSpaPreview();
  const [to, setTo] = useState(snapshot.date),
    [period, setPeriod] = useState("day");
  const count = period === "week" ? 7 : period === "month" ? 30 : 1,
    from = spaShiftDate(to, 1 - count);
  const report = spaReport(snapshot, from, to);
  const days = Array.from({ length: count }, (_, i) => {
    const date = spaShiftDate(from, i),
      r = spaReport(snapshot, date, date);
    return { date, in: r.collected, out: r.spent };
  });
  const max = Math.max(1, ...days.flatMap((d) => [d.in, d.out]));
  const team = snapshot.technicians.map((tech) => {
    const tasks = report.tasks.filter((task) => task.technicianId === tech.id),
      done = tasks.filter((task) => task.status === "completed");
    return {
      id: tech.id,
      name: tech.name,
      assigned: tasks.length,
      done: done.length,
      commission: Math.round(
        (done.reduce((s, task) => s + spaTaskPrice(snapshot, task), 0) *
          snapshot.settings.commission) /
          100,
      ),
    };
  });
  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div className="space-y-3">
          <SpaTabs
            items={["day", "week", "month"].map((key) => ({
              key,
              label: b(key),
            }))}
            value={period}
            onChange={setPeriod}
          />
          <div className="w-full sm:w-60">
            <Label htmlFor="spa-report-to">{b("to")}</Label>
            <KpsDatePicker
              id="spa-report-to"
              value={to}
              onChange={setTo}
              ariaLabel={b("to")}
              placeholder={b("to")}
              required
            />
          </div>
        </div>
        <SpaAction
          secondary
          onClick={() =>
            downloadSpaCsv("bao-cao", [
              [b("date"), b("collected"), b("spent")],
              ...days.map((d) => [d.date, d.in, d.out]),
              [b("total"), report.collected, report.spent],
              [b("invoiceValue"), report.billed],
              [b("outstanding"), report.outstanding],
            ])
          }
        >
          {b("export")}
        </SpaAction>
      </div>
      <SpaHint>{t("spa.business.reportRange", { from, to })}</SpaHint>
      <SpaStats
        items={[
          { label: b("collected"), value: spaMoney(report.collected) },
          { label: b("invoiceValue"), value: spaMoney(report.billed) },
          { label: b("outstanding"), value: spaMoney(report.outstanding) },
          { label: b("spent"), value: spaMoney(report.spent) },
          { label: b("visits"), value: report.completed },
          {
            label: b("completed"),
            value: report.tasks.filter((task) => task.status === "completed")
              .length,
          },
          {
            label: b("cancelled"),
            value: report.visits.filter((a) => a.status === "cancelled").length,
          },
        ]}
      />
      <SpaHint>{b("reportHint")}</SpaHint>
      <section className={spaPanel}>
        <h2 className="mb-4 font-semibold text-gray-900 dark:text-white">
          {b("trend")}
        </h2>
        <div className="max-h-96 space-y-3 overflow-y-auto">
          {days.map((d) => (
            <div key={d.date} className="grid gap-2 sm:grid-cols-3">
              <p className="text-sm text-gray-600 dark:text-gray-300">
                {d.date}
              </p>
              <div>
                <div className="flex justify-between text-xs text-success-700 dark:text-success-400">
                  <span>{b("collected")}</span>
                  <span>{spaMoney(d.in)}</span>
                </div>
                <progress
                  className="h-2 w-full accent-success-500"
                  max={max}
                  value={d.in}
                  aria-label={`${b("collected")} ${d.date}`}
                />
              </div>
              <div>
                <div className="flex justify-between text-xs text-warning-700 dark:text-warning-400">
                  <span>{b("spent")}</span>
                  <span>{spaMoney(d.out)}</span>
                </div>
                <progress
                  className="h-2 w-full accent-warning-500"
                  max={max}
                  value={d.out}
                  aria-label={`${b("spent")} ${d.date}`}
                />
              </div>
            </div>
          ))}
        </div>
      </section>
      <h2 className="font-semibold text-gray-900 dark:text-white">
        {b("staffPerformance")}
      </h2>
      <SpaHint>
        {t("spa.business.commissionHint", {
          rate: snapshot.settings.commission,
        })}
      </SpaHint>
      <SpaTable
        headers={[b("staff"), b("assigned"), b("completed"), b("commission")]}
        rows={team.map((s) => ({
          id: s.id,
          cells: [s.name, s.assigned, s.done, spaMoney(s.commission)],
        }))}
      />
    </div>
  );
}
