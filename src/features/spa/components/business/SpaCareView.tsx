import { useState } from "react";
import { useTranslation } from "react-i18next";
import { useSpaPreview } from "../../hooks/useSpaPreviewSnapshot";
import useSpaBusinessForms from "../../hooks/useSpaBusinessForms";
import { normalizeSpaText } from "../../previewWorkflow";
import SpaActionDialog, { type SpaActionForm } from "./SpaActionDialog";
import {
  SpaAction,
  SpaStats,
  SpaSearch,
  SpaTabs,
  SpaHint,
  SpaEmpty,
  spaPanel,
} from "./SpaBusinessUi";
export default function SpaCareView() {
  const { t } = useTranslation();
  const b = (k: string) => t(`spa.business.${k}`);
  const { snapshot } = useSpaPreview();
  const forms = useSpaBusinessForms();
  const [query, setQuery] = useState(""),
    [filter, setFilter] = useState("open"),
    [form, setForm] = useState<SpaActionForm | null>(null);
  const customer = (id: string) =>
    snapshot.customers.find((c) => c.id === id)?.name ?? id;
  const jobs = snapshot.careTasks
    .filter(
      (j) =>
        (filter === "all" || (filter === "done" ? j.done : !j.done)) &&
        normalizeSpaText(`${j.title} ${customer(j.customerId)}`).includes(
          normalizeSpaText(query),
        ),
    )
    .sort((a, b) => a.due.localeCompare(b.due));
  return (
    <div className="space-y-5">
      <SpaStats
        items={[
          {
            label: b("open"),
            value: snapshot.careTasks.filter((j) => !j.done).length,
          },
          {
            label: b("careOverdue"),
            value: snapshot.careTasks.filter(
              (j) => !j.done && j.due <= snapshot.date,
            ).length,
          },
          {
            label: b("done"),
            value: snapshot.careTasks.filter((j) => j.done).length,
          },
        ]}
      />
      <SpaHint>{b("careHint")}</SpaHint>
      <div className="flex flex-wrap justify-between gap-3">
        <SpaSearch value={query} onChange={setQuery} />
        <SpaAction onClick={() => setForm(forms.care())}>
          {b("careNew")}
        </SpaAction>
      </div>
      <SpaTabs
        value={filter}
        onChange={setFilter}
        items={["open", "done", "all"].map((key) => ({ key, label: b(key) }))}
      />
      <div className="grid gap-4 lg:grid-cols-2">
        {jobs.map((j) => (
          <article key={j.id} className={spaPanel}>
            <div className="flex flex-wrap justify-between gap-2">
              <p className="text-sm text-kps-primary dark:text-gray-300">
                {customer(j.customerId)}
              </p>
              <span className="text-xs text-gray-500 dark:text-gray-400">
                {b(j.done ? "done" : "open")}
              </span>
            </div>
            <h2 className="mt-2 font-semibold text-gray-900 dark:text-white">
              {j.title}
            </h2>
            <p className="mt-3 text-sm text-gray-500 dark:text-gray-400">
              {j.due} · {b(j.channel)} ·{" "}
              {snapshot.roster.find((s) => s.id === j.staffId)?.name}
            </p>
            {j.result && (
              <p className="mt-3 text-sm break-words whitespace-pre-wrap text-gray-600 dark:text-gray-300">
                {j.result}
              </p>
            )}
            <div className="mt-4">
              <SpaAction secondary onClick={() => setForm(forms.careResult(j))}>
                {b("careResult")}
              </SpaAction>
            </div>
          </article>
        ))}
      </div>
      {!jobs.length && <SpaEmpty />}
      {form && <SpaActionDialog form={form} onClose={() => setForm(null)} />}
    </div>
  );
}
