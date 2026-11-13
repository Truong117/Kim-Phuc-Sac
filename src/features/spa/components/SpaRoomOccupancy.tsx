import { Link } from "react-router";
import { useTranslation } from "react-i18next";
import { GridIcon, TimeIcon } from "@/icons";
import type { SpaPreviewSnapshot } from "../types";
import { spaDayTasks } from "../businessSelectors";
import SpaPanel from "./SpaPanel";

export default function SpaRoomOccupancy({
  snapshot,
  date,
}: {
  snapshot: SpaPreviewSnapshot;
  date: string;
}) {
  const { t } = useTranslation();
  const serving = new Set(
    spaDayTasks(snapshot, date)
      .filter((task) => task.status === "in_progress")
      .map((task) => task.bedId),
  );
  const rooms = [...new Set(snapshot.beds.map((bed) => bed.room))];
  return (
    <SpaPanel
      title={t("spa.concept.occupancy")}
      description={t("spa.concept.occupancyHint")}
      action={
        <Link
          to="/spa/rooms"
          className="inline-flex min-h-11 items-center text-theme-xs font-medium text-kps-primary-hover dark:text-gray-200"
        >
          {t("spa.flow.details")}
        </Link>
      }
    >
      <div className="space-y-3 p-4">
        {rooms.map((room) => {
          const beds = snapshot.beds.filter((bed) => bed.room === room);
          return (
            <div key={room}>
              <div className="mb-2 flex items-center justify-between gap-2 text-theme-xs">
                <h4 className="font-semibold text-gray-800 dark:text-gray-200">
                  {t("spa.technician.roomLabel", { room })}
                </h4>
                <span className="text-gray-500 dark:text-gray-400">
                  {t("spa.concept.bedsInUse", {
                    count: beds.filter((bed) => serving.has(bed.id)).length,
                    total: beds.length,
                  })}
                </span>
              </div>
              <div
                className={
                  beds.length === 1 ? "grid gap-2" : "grid grid-cols-2 gap-2"
                }
              >
                {beds.map((bed) => (
                  <Link
                    key={bed.id}
                    to="/spa/rooms"
                    className={`flex min-h-14 flex-row items-center justify-center gap-2 rounded-lg border p-3 text-center text-theme-xs ${serving.has(bed.id) ? "border-kps-primary/20 bg-kps-primary/10 text-kps-primary-hover dark:border-kps-primary/40 dark:bg-kps-primary/20 dark:text-gray-200" : "border-gray-200 text-gray-500 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"}`}
                  >
                    <GridIcon className="size-5" aria-hidden="true" />
                    {t("spa.concept.bedState", {
                      bed: bed.number,
                      state: t(
                        `spa.concept.${serving.has(bed.id) ? "bedServing" : "bedReady"}`,
                      ),
                    })}
                  </Link>
                ))}
              </div>
            </div>
          );
        })}
        <p className="flex items-start gap-2 rounded-lg border border-kps-primary/10 bg-kps-primary/5 p-3 text-theme-xs leading-5 text-kps-primary-hover dark:border-kps-primary/30 dark:bg-kps-primary/10 dark:text-gray-300">
          <TimeIcon className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
          {t("spa.concept.roomNote")}
        </p>
      </div>
    </SpaPanel>
  );
}
