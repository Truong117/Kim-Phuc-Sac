import { useState } from "react";
import { useTranslation } from "react-i18next";
import { useAuthorization } from "@/hooks/useAuthorization";
import { canOpenWorkspacePath } from "@/utils/workspace";
import { spaNavigation } from "../../config";
import { useSpaPreview } from "../../hooks/useSpaPreviewSnapshot";
import SpaActionDialog, { type SpaActionForm } from "./SpaActionDialog";
import {
  SpaAction,
  SpaTable,
  SpaHint,
  SpaEmpty,
  spaPanel,
} from "./SpaBusinessUi";
export default function SpaSettingsView() {
  const { t } = useTranslation();
  const b = (k: string) => t(`spa.business.${k}`);
  const { snapshot, execute } = useSpaPreview();
  const { workspaces } = useAuthorization();
  const [form, setForm] = useState<SpaActionForm | null>(null);
  const settings = snapshot.settings;
  const audit = snapshot.appointments
    .flatMap((a) =>
      (a.audit ?? []).map((event, index) => ({
        ...event,
        id: `${a.id}-${index}`,
        appointment: a.id,
      })),
    )
    .sort((a, b) => b.at.localeCompare(a.at));
  return (
    <div className="space-y-5">
      <section className={spaPanel}>
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
            {b("settingsTitle")}
          </h2>
          <SpaAction
            onClick={() =>
              setForm({
                title: b("settingsTitle"),
                hint: b("settingsHint"),
                initial: {
                  name: settings.facilityName,
                  open: settings.open,
                  close: settings.close,
                  commission: String(settings.commission),
                },
                fields: [
                  {
                    key: "name",
                    label: b("facility"),
                    errors: ["nameRequired"],
                  },
                  {
                    key: "open",
                    label: b("opening"),
                    type: "time",
                    errors: ["timeInvalid", "settingsConflict"],
                  },
                  {
                    key: "close",
                    label: b("closing"),
                    type: "time",
                    errors: ["timeInvalid", "settingsConflict"],
                  },
                  {
                    key: "commission",
                    label: b("commissionRate"),
                    type: "number",
                    min: 0,
                    max: 100,
                    step: 0.1,
                    errors: ["commissionInvalid"],
                  },
                ],
                submit: (v) =>
                  execute({
                    kind: "settings",
                    name: v.name,
                    open: v.open,
                    close: v.close,
                    commission: Number(v.commission),
                  }),
              })
            }
          >
            {b("editSettings")}
          </SpaAction>
        </div>
        <dl className="mt-5 grid gap-4 text-sm text-gray-700 sm:grid-cols-3 dark:text-gray-300">
          <div>
            <dt className="text-gray-500 dark:text-gray-400">
              {b("facility")}
            </dt>
            <dd className="mt-1">{settings.facilityName}</dd>
          </div>
          <div>
            <dt className="text-gray-500 dark:text-gray-400">
              {b("opening")} / {b("closing")}
            </dt>
            <dd className="mt-1">
              {settings.open} – {settings.close}
            </dd>
          </div>
          <div>
            <dt className="text-gray-500 dark:text-gray-400">
              {b("commission")}
            </dt>
            <dd className="mt-1">{settings.commission}%</dd>
          </div>
        </dl>
      </section>
      <SpaHint>{b("settingsHint")}</SpaHint>
      <h2 className="font-semibold text-gray-900 dark:text-white">
        {b("permissions")}
      </h2>
      <SpaTable
        headers={[b("description"), b("permissions")]}
        rows={spaNavigation.map((item) => ({
          id: item.key,
          cells: [
            t(`spa.navigation.${item.key}`),
            b(
              canOpenWorkspacePath(workspaces, item.path)
                ? "accessYes"
                : "accessNo",
            ),
          ],
        }))}
      />
      <h2 className="font-semibold text-gray-900 dark:text-white">
        {b("audit")}
      </h2>
      {audit.length ? (
        <SpaTable
          headers={[b("date"), b("visitCode"), b("description"), b("actor")]}
          rows={audit.slice(0, 50).map((event) => ({
            id: event.id,
            cells: [
              new Intl.DateTimeFormat("vi-VN", {
                dateStyle: "short",
                timeStyle: "short",
                timeZone: "Asia/Ho_Chi_Minh",
              }).format(new Date(event.at)),
              event.appointment,
              <span>
                {t(`spa.flow.events.${event.kind}`)}
                {event.detail && (
                  <span className="mt-1 block text-xs break-words">
                    {event.detail}
                  </span>
                )}
              </span>,
              event.actor.name,
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
