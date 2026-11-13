import { useState } from "react";
import { useTranslation } from "react-i18next";
import { useSpaPreview } from "../../hooks/useSpaPreviewSnapshot";
import useSpaBusinessForms from "../../hooks/useSpaBusinessForms";
import { downloadSpaCsv } from "../../csv";
import { normalizeSpaText } from "../../previewWorkflow";
import SpaActionDialog, { type SpaActionForm } from "./SpaActionDialog";
import {
  SpaAction,
  SpaStats,
  SpaSearch,
  SpaTabs,
  SpaTable,
  SpaHint,
  SpaEmpty,
} from "./SpaBusinessUi";
export default function SpaInventoryView() {
  const { t } = useTranslation();
  const b = (k: string) => t(`spa.business.${k}`);
  const { snapshot } = useSpaPreview();
  const forms = useSpaBusinessForms();
  const [query, setQuery] = useState(""),
    [filter, setFilter] = useState("all"),
    [form, setForm] = useState<SpaActionForm | null>(null);
  const products = snapshot.products.filter(
    (p) =>
      (filter === "all" || p.stock < p.minStock) &&
      normalizeSpaText(`${p.name} ${p.batch}`).includes(
        normalizeSpaText(query),
      ),
  );
  return (
    <div className="space-y-5">
      <SpaStats
        items={[
          { label: b("stockAll"), value: snapshot.products.length },
          {
            label: b("lowStock"),
            value: snapshot.products.filter((p) => p.stock < p.minStock).length,
          },
          { label: b("movements"), value: snapshot.movements.length },
        ]}
      />
      <SpaHint>{b("stockHint")}</SpaHint>
      <div className="flex flex-wrap justify-between gap-3">
        <SpaSearch value={query} onChange={setQuery} />
        <SpaAction
          secondary
          onClick={() =>
            downloadSpaCsv("kho", [
              [
                b("product"),
                b("batch"),
                b("expires"),
                b("stock"),
                b("unit"),
                b("minimum"),
              ],
              ...products.map((p) => [
                p.name,
                p.batch,
                p.expires,
                p.stock,
                p.unit,
                p.minStock,
              ]),
            ])
          }
        >
          {b("export")}
        </SpaAction>
      </div>
      <SpaTabs
        value={filter}
        onChange={setFilter}
        items={["all", "lowStock"].map((key) => ({ key, label: b(key) }))}
      />
      {products.length ? (
        <SpaTable
          headers={[
            b("product"),
            b("batch"),
            b("stock"),
            b("minimum"),
            b("movements"),
          ]}
          rows={products.map((p) => ({
            id: p.id,
            cells: [
              <span className="font-medium">
                {p.name}
                <span className="block text-xs text-gray-500 dark:text-gray-400">
                  {p.unit} · {b(p.retail ? "retail" : "stock")}
                </span>
              </span>,
              <span>
                {p.batch}
                <span className="block text-xs">{p.expires}</span>
              </span>,
              <span
                className={
                  p.stock < p.minStock
                    ? "font-semibold text-warning-700 dark:text-warning-400"
                    : ""
                }
              >
                {p.stock}
              </span>,
              p.minStock,
              <div className="flex gap-2">
                <SpaAction
                  secondary
                  onClick={() => setForm(forms.stock(p, "in"))}
                >
                  {b("stockIn")}
                </SpaAction>
                <SpaAction
                  secondary
                  disabled={p.stock === 0}
                  onClick={() => setForm(forms.stock(p, "out"))}
                >
                  {b("stockOut")}
                </SpaAction>
              </div>,
            ],
          }))}
        />
      ) : (
        <SpaEmpty />
      )}
      <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
        {b("movements")}
      </h2>
      {snapshot.movements.length ? (
        <SpaTable
          headers={[
            b("date"),
            b("product"),
            b("quantity"),
            b("reason"),
            b("actor"),
          ]}
          rows={[...snapshot.movements].reverse().map((m) => ({
            id: m.id,
            cells: [
              m.date,
              snapshot.products.find((p) => p.id === m.productId)?.name ??
                m.productId,
              `${m.quantity > 0 ? "+" : ""}${m.quantity}`,
              m.reason,
              m.actor.name,
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
