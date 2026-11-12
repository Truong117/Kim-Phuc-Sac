import Button from "@/components/ui/button/Button";
import Badge from "@/components/ui/badge/Badge";
import { useTranslation } from "react-i18next";
import type { SpaTechnicianTask } from "../types";
import SpaStatusBadge from "./SpaStatusBadge";

export default function SpaTechnicianDetail({
  task,
  onBack,
}: {
  task: SpaTechnicianTask;
  onBack: () => void;
}) {
  const { t } = useTranslation();
  return (
    <article
      className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-gray-900"
      aria-label={t("spa.technician.detail")}
    >
      <div className="mb-4 md:hidden">
        <Button variant="outline" size="sm" onClick={onBack}>
          {t("spa.technician.back")}
        </Button>
      </div>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <span className="text-sm text-gray-500 dark:text-gray-400">
          {task.appointmentId}
        </span>
        <SpaStatusBadge kind="task" status={task.status} />
      </div>
      <h3 className="mt-4 text-xl font-semibold text-gray-900 dark:text-white">
        {task.customerName}
      </h3>
      <p className="mt-2 text-kps-primary-hover dark:text-gray-200">
        {t(`spa.services.${task.serviceKey}`)}
      </p>
      <dl className="mt-5 grid grid-cols-2 gap-4 rounded-xl bg-gray-50 p-4 text-sm dark:bg-gray-800">
        <div>
          <dt className="text-gray-500 dark:text-gray-400">
            {t("spa.technician.time")}
          </dt>
          <dd className="mt-1 font-medium text-gray-900 dark:text-white">
            {task.startTime}
          </dd>
        </div>
        <div>
          <dt className="text-gray-500 dark:text-gray-400">
            {t("spa.technician.room")}
          </dt>
          <dd className="mt-1 font-medium text-gray-900 dark:text-white">
            {task.room}
          </dd>
        </div>
        <div>
          <dt className="text-gray-500 dark:text-gray-400">
            {t("spa.technician.duration")}
          </dt>
          <dd className="mt-1 font-medium text-gray-900 dark:text-white">
            {t("spa.minutes", { count: task.durationMinutes })}
          </dd>
        </div>
        <div>
          <dt className="text-gray-500 dark:text-gray-400">
            {t("spa.technician.assignee")}
          </dt>
          <dd className="mt-1 font-medium text-gray-900 dark:text-white">
            {task.technicianName}
          </dd>
        </div>
      </dl>
      <h4 className="mt-6 font-semibold text-gray-900 dark:text-white">
        {t("spa.technician.notes")}
      </h4>
      <p className="mt-2 text-sm text-gray-600 dark:text-gray-300">
        {t(`spa.notes.${task.noteKey}`)}
      </p>
      <div className="mt-6 rounded-xl border border-dashed border-gray-300 p-4 dark:border-gray-700">
        <Badge color={task.requiresSkinPhoto ? "warning" : "light"}>
          {t(
            task.requiresSkinPhoto
              ? "spa.technician.photoRequired"
              : "spa.technician.photoOptional",
          )}
        </Badge>
        <p className="mt-3 text-sm text-gray-500 dark:text-gray-400">
          {t("spa.technician.photoPlanned")}
        </p>
      </div>
      <p className="mt-6 text-sm text-gray-500 dark:text-gray-400">
        {t("spa.technician.readOnly")}
      </p>
    </article>
  );
}
