import { useState } from "react";
import { useTranslation } from "react-i18next";
import { useSpaPreview } from "../../hooks/useSpaPreviewSnapshot";
import useSpaBusinessForms from "../../hooks/useSpaBusinessForms";
import { normalizeSpaText } from "../../previewWorkflow";
import { spaMoney } from "../../businessSelectors";
import SpaActionDialog, { type SpaActionForm } from "./SpaActionDialog";
import SpaBookingDialog from "../SpaBookingDialog";
import {
  SpaAction,
  SpaSearch,
  SpaTabs,
  SpaEmpty,
  SpaHint,
  spaPanel,
} from "./SpaBusinessUi";
export default function SpaCatalogView() {
  const { t } = useTranslation();
  const b = (k: string) => t(`spa.business.${k}`);
  const { snapshot } = useSpaPreview();
  const forms = useSpaBusinessForms();
  const [tab, setTab] = useState("services"),
    [query, setQuery] = useState(""),
    [form, setForm] = useState<SpaActionForm | null>(null),
    [booking, setBooking] = useState<string>();
  const services = snapshot.services.filter((s) =>
      normalizeSpaText(`${s.name} ${s.group}`).includes(
        normalizeSpaText(query),
      ),
    ),
    products = snapshot.products.filter(
      (p) =>
        p.retail && normalizeSpaText(p.name).includes(normalizeSpaText(query)),
    );
  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <SpaTabs
          items={["services", "retail"].map((key) => ({ key, label: b(key) }))}
          value={tab}
          onChange={setTab}
        />
        {tab === "services" && (
          <SpaAction onClick={() => setForm(forms.service())}>
            {b("newService")}
          </SpaAction>
        )}
      </div>
      <SpaSearch value={query} onChange={setQuery} />
      <SpaHint>{b(tab === "services" ? "serviceHint" : "sellHint")}</SpaHint>
      <div className="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
        {tab === "services"
          ? services.map((s) => (
              <article key={s.key} className={spaPanel}>
                <p className="text-sm text-kps-primary dark:text-gray-400">
                  {s.group || b("services")}
                </p>
                <h2 className="mt-2 text-lg font-semibold text-gray-900 dark:text-white">
                  {s.name ?? t(`spa.services.${s.key}`)}
                </h2>
                <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
                  {t("spa.minutes", { count: s.durationMinutes })} ·{" "}
                  {spaMoney(s.price ?? 0)}
                </p>
                <p className="mt-3 text-sm text-gray-600 dark:text-gray-300">
                  {s.description}
                </p>
                <div className="mt-5 flex flex-wrap gap-2">
                  <SpaAction onClick={() => setBooking(s.key)}>
                    {b("book")}
                  </SpaAction>
                  <SpaAction
                    secondary
                    onClick={() => setForm(forms.service(s))}
                  >
                    {b("editService")}
                  </SpaAction>
                </div>
              </article>
            ))
          : products.map((p) => (
              <article key={p.id} className={spaPanel}>
                <p className="text-sm text-gray-500 dark:text-gray-400">
                  {b("retail")} · {p.unit}
                </p>
                <h2 className="mt-2 text-lg font-semibold text-gray-900 dark:text-white">
                  {p.name}
                </h2>
                <p className="mt-3 text-xl font-semibold text-kps-primary dark:text-gray-200">
                  {spaMoney(p.price)}
                </p>
                <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
                  {b("stock")}: {p.stock}
                </p>
                <div className="mt-5">
                  <SpaAction
                    disabled={p.stock === 0}
                    onClick={() => setForm(forms.retail(p))}
                  >
                    {b("sell")}
                  </SpaAction>
                </div>
              </article>
            ))}
      </div>
      {(tab === "services" ? !services.length : !products.length) && (
        <SpaEmpty />
      )}
      {form && <SpaActionDialog form={form} onClose={() => setForm(null)} />}
      {booking && (
        <SpaBookingDialog
          serviceKey={booking}
          date={snapshot.date}
          onClose={() => setBooking(undefined)}
          onSaved={() => setBooking(undefined)}
        />
      )}
    </div>
  );
}
