import { useState } from "react";
import { useTranslation } from "react-i18next";
import { useSpaPreview } from "../../hooks/useSpaPreviewSnapshot";
import useSpaBusinessForms from "../../hooks/useSpaBusinessForms";
import {
  spaMoney,
  spaInvoicePaid,
  spaInvoiceBalance,
} from "../../businessSelectors";
import { downloadSpaCsv } from "../../csv";
import { normalizeSpaText } from "../../previewWorkflow";
import SpaActionDialog, { type SpaActionForm } from "./SpaActionDialog";
import SpaDialog from "../SpaDialog";
import {
  SpaAction,
  SpaStats,
  SpaSearch,
  SpaTabs,
  SpaTable,
  SpaHint,
  SpaEmpty,
} from "./SpaBusinessUi";
export default function SpaCashierView() {
  const { t } = useTranslation();
  const b = (k: string) => t(`spa.business.${k}`);
  const { snapshot } = useSpaPreview();
  const forms = useSpaBusinessForms();
  const [query, setQuery] = useState(""),
    [filter, setFilter] = useState("unpaid"),
    [form, setForm] = useState<SpaActionForm | null>(null),
    [detail, setDetail] = useState<string>();
  const customer = (id: string) =>
    snapshot.customers.find((c) => c.id === id)?.name ?? id;
  const invoices = snapshot.invoices.filter(
    (i) =>
      (filter === "all" || spaInvoiceBalance(snapshot, i.id) > 0) &&
      normalizeSpaText(`${i.id} ${customer(i.customerId)}`).includes(
        normalizeSpaText(query),
      ),
  );
  const invoice = snapshot.invoices.find((i) => i.id === detail);
  return (
    <div className="space-y-5">
      <SpaStats
        items={[
          {
            label: b("invoiceValue"),
            value: spaMoney(snapshot.invoices.reduce((s, i) => s + i.total, 0)),
          },
          {
            label: b("collected"),
            value: spaMoney(
              snapshot.payments.reduce((s, p) => s + p.amount, 0),
            ),
          },
          {
            label: b("outstanding"),
            value: spaMoney(
              snapshot.invoices.reduce(
                (s, i) => s + spaInvoiceBalance(snapshot, i.id),
                0,
              ),
            ),
          },
        ]}
      />
      <SpaHint>{b("cashierHint")}</SpaHint>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <SpaSearch value={query} onChange={setQuery} />
        <div className="flex flex-wrap gap-2">
          <SpaAction
            secondary
            onClick={() =>
              downloadSpaCsv("phieu", [
                [
                  b("invoice"),
                  b("customer"),
                  b("date"),
                  b("invoiceValue"),
                  b("collected"),
                  b("outstanding"),
                ],
                ...invoices.map((i) => [
                  i.id,
                  customer(i.customerId),
                  i.date,
                  i.total,
                  spaInvoicePaid(snapshot, i.id),
                  spaInvoiceBalance(snapshot, i.id),
                ]),
              ])
            }
          >
            {b("export")}
          </SpaAction>
          <SpaAction onClick={() => setForm(forms.retail())}>
            {b("sell")}
          </SpaAction>
        </div>
      </div>
      <SpaTabs
        items={["unpaid", "all"].map((key) => ({ key, label: b(key) }))}
        value={filter}
        onChange={setFilter}
      />
      {invoices.length ? (
        <SpaTable
          headers={[
            b("invoice"),
            b("customer"),
            b("invoiceValue"),
            b("collected"),
            b("outstanding"),
            b("collect"),
          ]}
          rows={invoices.map((i) => ({
            id: i.id,
            cells: [
              <button
                type="button"
                className="min-h-11 text-start font-semibold text-kps-primary underline dark:text-gray-200"
                onClick={() => setDetail(i.id)}
              >
                {i.id}
                <span className="block text-xs font-normal text-gray-500 dark:text-gray-400">
                  {i.date} ·{" "}
                  {b(`invoice${i.kind[0].toUpperCase()}${i.kind.slice(1)}`)}
                </span>
              </button>,
              customer(i.customerId),
              spaMoney(i.total),
              spaMoney(spaInvoicePaid(snapshot, i.id)),
              spaMoney(spaInvoiceBalance(snapshot, i.id)),
              spaInvoiceBalance(snapshot, i.id) > 0 ? (
                <SpaAction onClick={() => setForm(forms.payment(i))}>
                  {b("collect")}
                </SpaAction>
              ) : (
                b("paid")
              ),
            ],
          }))}
        />
      ) : (
        <SpaEmpty />
      )}
      {form && <SpaActionDialog form={form} onClose={() => setForm(null)} />}
      {invoice && (
        <SpaDialog
          title={`${b("invoiceLines")} · ${invoice.id}`}
          onClose={() => setDetail(undefined)}
        >
          <div className="space-y-5">
            <SpaHint>
              {customer(invoice.customerId)} · {invoice.actor.name}
            </SpaHint>
            <SpaTable
              headers={[b("description"), b("quantity"), b("price")]}
              rows={invoice.lines.map((line, index) => ({
                id: String(index),
                cells: [line.name, line.quantity, spaMoney(line.price)],
              }))}
            />
            <h3 className="font-semibold text-gray-900 dark:text-white">
              {b("receipts")}
            </h3>
            {snapshot.payments
              .filter((p) => p.invoiceId === invoice.id)
              .map((p) => (
                <p
                  key={p.id}
                  className="text-sm text-gray-600 dark:text-gray-300"
                >
                  {p.date} · {spaMoney(p.amount)} · {b(p.method)} ·{" "}
                  {p.actor.name}
                </p>
              ))}
          </div>
        </SpaDialog>
      )}
    </div>
  );
}
