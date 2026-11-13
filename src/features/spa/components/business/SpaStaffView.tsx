import { useState } from "react";
import { useTranslation } from "react-i18next";
import { useSpaPreview } from "../../hooks/useSpaPreviewSnapshot";
import { spaDayTasks, spaMoney, spaTaskPrice } from "../../businessSelectors";
import SpaDateFilter from "../SpaDateFilter";
import SpaFormError from "../SpaFormError";
import type { SpaPreviewError } from "../../types";
import { SpaAction, SpaStats, SpaHint, spaPanel } from "./SpaBusinessUi";
export default function SpaStaffView() {
  const { t } = useTranslation();
  const b = (k: string) => t(`spa.business.${k}`);
  const { snapshot, execute } = useSpaPreview();
  const [date, setDate] = useState(snapshot.date),
    [error, setError] = useState<SpaPreviewError>();
  const tasks = spaDayTasks(snapshot, date);
  return (
    <div className="space-y-5">
      <SpaDateFilter
        date={date}
        onChange={(v) => {
          setDate(v);
          setError(undefined);
        }}
      />
      <SpaStats
        items={[
          { label: b("staffCount"), value: snapshot.roster.length },
          {
            label: b("attended"),
            value: snapshot.attendance.filter((a) => a.date === date).length,
          },
          {
            label: b("completed"),
            value: tasks.filter((task) => task.status === "completed").length,
          },
        ]}
      />
      <SpaHint>
        {t("spa.business.commissionHint", {
          rate: snapshot.settings.commission,
        })}
      </SpaHint>
      <SpaFormError error={error} />
      <div className="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
        {snapshot.roster.map((staff) => {
          const assigned = tasks.filter(
              (task) => task.technicianId === staff.id,
            ),
            done = assigned.filter((task) => task.status === "completed"),
            attendance = snapshot.attendance.find(
              (a) => a.date === date && a.staffId === staff.id,
            );
          return (
            <article key={staff.id} className={spaPanel}>
              <div className="flex items-center gap-3">
                <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-kps-primary/10 font-semibold text-kps-primary dark:bg-kps-primary/20 dark:text-gray-200">
                  {staff.name.split(" ").slice(-1)[0].slice(0, 1)}
                </span>
                <div>
                  <h2 className="font-semibold text-gray-900 dark:text-white">
                    {staff.name}
                  </h2>
                  <p className="text-sm text-gray-500 dark:text-gray-400">
                    {b(staff.job)}
                  </p>
                </div>
              </div>
              {staff.job === "technician" && (
                <dl className="mt-5 grid grid-cols-2 gap-3 text-sm text-gray-600 dark:text-gray-300">
                  <div>
                    <dt>{b("assigned")}</dt>
                    <dd className="mt-1 font-semibold">{assigned.length}</dd>
                  </div>
                  <div>
                    <dt>{b("completed")}</dt>
                    <dd className="mt-1 font-semibold">{done.length}</dd>
                  </div>
                  <div className="col-span-2">
                    <dt>{b("commission")}</dt>
                    <dd className="mt-1 font-semibold text-kps-primary dark:text-gray-200">
                      {spaMoney(
                        Math.round(
                          (done.reduce(
                            (s, task) => s + spaTaskPrice(snapshot, task),
                            0,
                          ) *
                            snapshot.settings.commission) /
                            100,
                        ),
                      )}
                    </dd>
                  </div>
                </dl>
              )}
              <div className="mt-5">
                {attendance ? (
                  <SpaHint>
                    {b("attended")} ·{" "}
                    {new Intl.DateTimeFormat("vi-VN", {
                      timeZone: "Asia/Ho_Chi_Minh",
                      hour: "2-digit",
                      minute: "2-digit",
                    }).format(new Date(attendance.at))}{" "}
                    · {attendance.actor.name}
                  </SpaHint>
                ) : (
                  <SpaAction
                    secondary
                    onClick={() =>
                      setError(
                        execute({
                          kind: "attendance",
                          staffId: staff.id,
                          date,
                        }),
                      )
                    }
                  >
                    {b("attendance")}
                  </SpaAction>
                )}
              </div>
            </article>
          );
        })}
      </div>
    </div>
  );
}
