import { useState } from "react";
import { useTranslation } from "react-i18next";
import { useSpaPreview } from "../../hooks/useSpaPreviewSnapshot";
import useSpaBusinessForms from "../../hooks/useSpaBusinessForms";
import { spaMoney, spaReport } from "../../businessSelectors";
import { downloadSpaCsv } from "../../csv";
import SpaDateFilter from "../SpaDateFilter";
import SpaActionDialog, { type SpaActionForm } from "./SpaActionDialog";
import {
  SpaAction,
  SpaStats,
  SpaTable,
  SpaHint,
  SpaEmpty,
} from "./SpaBusinessUi";
export default function SpaFinanceView() {
  const { t } = useTranslation();
  const b = (k: string) => t(`spa.business.${k}`);
  const { snapshot } = useSpaPreview();
  const forms = useSpaBusinessForms();
  const [date, setDate] = useState(snapshot.date),
    [form, setForm] = useState<SpaActionForm | null>(null);
  const report = spaReport(snapshot, date, date);
  const rows = [
    ...report.payments.map((p) => ({
      id: p.id,
      date: p.date,
      kind: b("in"),
      reason: p.invoiceId,
      amount: p.amount,
      method: b(p.method),
      actor: p.actor.name,
    })),
    ...report.expenses.map((p) => ({
      id: p.id,
      date: p.date,
      kind: b("out"),
      reason: p.reason,
      amount: -p.amount,
      method: b(p.method),
      actor: p.actor.name,
    })),
  ];
  const headers = [
    b("date"),
    b("direction"),
    b("reason"),
    b("amount"),
    b("method"),
    b("actor"),
  ];
  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <SpaDateFilter date={date} onChange={setDate} />
        <div className="flex flex-wrap gap-2">
          <SpaAction
            secondary
            onClick={() =>
              downloadSpaCsv("so-quy", [
                headers,
                ...rows.map((r) => [
                  r.date,
                  r.kind,
                  r.reason,
                  r.amount,
                  r.method,
                  r.actor,
                ]),
              ])
            }
          >
            {b("export")}
          </SpaAction>
          <SpaAction onClick={() => setForm(forms.expense(date))}>
            {b("expenseNew")}
          </SpaAction>
        </div>
      </div>
      <SpaStats
        items={[
          { label: b("collected"), value: spaMoney(report.collected) },
          { label: b("spent"), value: spaMoney(report.spent) },
          { label: b("net"), value: spaMoney(report.collected - report.spent) },
        ]}
      />
      <SpaHint>{b("financeHint")}</SpaHint>
      {rows.length ? (
        <SpaTable
          headers={headers}
          rows={rows.map((r) => ({
            id: r.id,
            cells: [
              r.date,
              r.kind,
              r.reason,
              <span
                className={
                  r.amount > 0
                    ? "text-success-700 dark:text-success-400"
                    : "text-error-600 dark:text-error-400"
                }
              >
                {spaMoney(r.amount)}
              </span>,
              r.method,
              r.actor,
            ],
          }))}
        />
      ) : (
        <SpaEmpty />
      )}
      {form && <SpaActionDialog form={form} onClose={() => setForm(null)} />}
    </div>
  );
}
