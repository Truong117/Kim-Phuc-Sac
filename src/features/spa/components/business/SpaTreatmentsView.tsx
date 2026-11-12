import { useState } from "react";
import { useTranslation } from "react-i18next";
import { useSpaPreview } from "../../hooks/useSpaPreviewSnapshot";
import useSpaBusinessForms from "../../hooks/useSpaBusinessForms";
import { spaMoney } from "../../businessSelectors";
import { normalizeSpaText } from "../../previewWorkflow";
import type { SpaPackage } from "../../types";
import SpaActionDialog, { type SpaActionForm } from "./SpaActionDialog";
import SpaBookingDialog from "../SpaBookingDialog";
import SpaDialog from "../SpaDialog";
import {
  SpaAction,
  SpaStats,
  SpaSearch,
  SpaHint,
  SpaEmpty,
  spaPanel,
} from "./SpaBusinessUi";
export default function SpaTreatmentsView() {
  const { t } = useTranslation();
  const b = (k: string) => t(`spa.business.${k}`);
  const { snapshot } = useSpaPreview();
  const forms = useSpaBusinessForms();
  const [query, setQuery] = useState(""),
    [form, setForm] = useState<SpaActionForm | null>(null),
    [booking, setBooking] = useState<SpaPackage>(),
    [history, setHistory] = useState<SpaPackage>();
  const customer = (id: string) =>
    snapshot.customers.find((c) => c.id === id)?.name ?? id;
  const packages = snapshot.packages.filter((p) =>
    normalizeSpaText(`${p.name} ${customer(p.customerId)}`).includes(
      normalizeSpaText(query),
    ),
  );
  return (
    <div className="space-y-5">
      <SpaStats
        items={[
          { label: b("packageCount"), value: snapshot.packages.length },
          {
            label: b("packageActive"),
            value: snapshot.packages.filter(
              (p) => p.used < p.total && p.expires >= snapshot.date,
            ).length,
          },
          {
            label: b("remaining"),
            value: snapshot.packages
              .filter((p) => p.expires >= snapshot.date)
              .reduce((s, p) => s + p.total - p.used, 0),
          },
        ]}
      />
      <div className="flex flex-wrap justify-between gap-3">
        <SpaSearch value={query} onChange={setQuery} />
        <SpaAction onClick={() => setForm(forms.package())}>
          {b("packageSell")}
        </SpaAction>
      </div>
      <SpaHint>{b("packageHint")}</SpaHint>
      <div className="grid gap-4 lg:grid-cols-2">
        {packages.map((p) => (
          <article key={p.id} className={spaPanel}>
            <p className="text-sm text-gray-500 dark:text-gray-400">
              {p.id} · {customer(p.customerId)}
            </p>
            <h2 className="mt-2 text-lg font-semibold text-gray-900 dark:text-white">
              {p.name}
            </h2>
            <p className="mt-3 text-sm text-gray-600 dark:text-gray-300">
              {t("spa.business.sessionBalance", {
                used: p.used,
                total: p.total,
              })}{" "}
              · {b("expires")}: {p.expires}
            </p>
            <progress
              className="mt-3 h-2 w-full accent-kps-primary"
              max={p.total}
              value={p.used}
              aria-label={b("used")}
            />
            <p className="mt-3 text-sm text-gray-500 dark:text-gray-400">
              {spaMoney(p.price)} ·{" "}
              {b(
                p.used < p.total && p.expires >= snapshot.date
                  ? "packageActive"
                  : "packageExpired",
              )}
            </p>
            <div className="mt-4 flex flex-wrap gap-2">
              <SpaAction
                disabled={p.used >= p.total || p.expires < snapshot.date}
                onClick={() => setBooking(p)}
              >
                {b("bookNext")}
              </SpaAction>
              <SpaAction secondary onClick={() => setHistory(p)}>
                {b("viewHistory")}
              </SpaAction>
            </div>
          </article>
        ))}
      </div>
      {!packages.length && <SpaEmpty />}
      {form && <SpaActionDialog form={form} onClose={() => setForm(null)} />}
      {booking && (
        <SpaBookingDialog
          customerId={booking.customerId}
          serviceKey={booking.serviceKey}
          packageId={booking.id}
          date={snapshot.date}
          onClose={() => setBooking(undefined)}
          onSaved={() => setBooking(undefined)}
        />
      )}
      {history && (
        <SpaDialog
          title={b("packageHistory")}
          onClose={() => setHistory(undefined)}
        >
          <div className="space-y-3 text-sm text-gray-600 dark:text-gray-300">
            <SpaHint>
              {history.name} · {customer(history.customerId)}
            </SpaHint>
            <p>
              {t("spa.business.openingBalance", {
                count: history.used - history.uses.length,
              })}
            </p>
            {history.uses.map((use) => (
              <p key={use.taskId}>
                {use.date} · {use.taskId} · {use.actor.name}
              </p>
            ))}
          </div>
        </SpaDialog>
      )}
    </div>
  );
}
