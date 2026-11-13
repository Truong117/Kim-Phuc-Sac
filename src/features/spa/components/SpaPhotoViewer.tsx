import { useSpaPreview } from "../hooks/useSpaPreviewSnapshot";
import { useId, useState } from "react";
import { useTranslation } from "react-i18next";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import type { SpaSkinPhoto } from "../types";
import { spaFieldClasses } from "../config";
import { SpaAction, SpaHint } from "./business/SpaBusinessUi";
export default function SpaPhotoViewer({
  photos,
  initialId,
  onClose,
}: {
  photos: SpaSkinPhoto[];
  initialId: string;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const { snapshot } = useSpaPreview();
  const id = useId();
  const [firstId, setFirst] = useState(initialId),
    [secondId, setSecond] = useState(
      photos.find(
        (p) =>
          p.id !== initialId &&
          p.stage !== photos.find((p) => p.id === initialId)?.stage,
      )?.id ??
        photos.find((p) => p.id !== initialId)?.id ??
        initialId,
    ),
    [zoom, setZoom] = useState<string>();
  const first = photos.find((p) => p.id === firstId) ?? photos[0],
    second =
      photos.find((p) => p.id === secondId) ??
      photos.find((p) => p.id !== first?.id) ??
      first;
  const label = (p: SpaSkinPhoto) =>
    `${p.date} · ${snapshot.appointments.find((a) => a.id === p.appointmentId)?.tasks.find((task) => task.id === p.taskId)?.serviceName ?? p.taskId} · ${p.appointmentId} · ${t(`spa.photos.${p.stage}`)} · ${p.name}`;
  const image = (p: SpaSkinPhoto) => (
    <figure className="min-w-0 space-y-2 rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
      <p className="text-sm font-semibold text-kps-primary dark:text-gray-200">
        {t(`spa.photos.${p.stage}`)}
      </p>
      <img
        src={p.url}
        alt={t("spa.photos.alt", {
          stage: t(`spa.photos.${p.stage}`),
          date: p.date,
          visit: p.appointmentId,
        })}
        className={
          zoom
            ? "max-h-128 w-full rounded-lg object-contain"
            : "aspect-square w-full rounded-lg bg-gray-50 object-contain dark:bg-gray-800"
        }
      />
      <figcaption className="space-y-1 text-xs break-words text-gray-600 dark:text-gray-300">
        <p>{label(p)}</p>
        <p>{p.caption}</p>
        <p>
          {p.createdBy.name} ·{" "}
          {new Intl.DateTimeFormat("vi-VN", {
            timeZone: "Asia/Ho_Chi_Minh",
            dateStyle: "short",
            timeStyle: "short",
          }).format(new Date(p.createdAt))}
        </p>
      </figcaption>
      {!zoom && (
        <SpaAction secondary onClick={() => setZoom(p.id)}>
          {t("spa.photos.zoom")}
        </SpaAction>
      )}
    </figure>
  );
  if (!first) return null;
  const single = photos.length === 1;
  const large =
    photos.find((p) => p.id === zoom) ?? (single ? first : undefined);
  return (
    <section
      aria-label={t("spa.photos.viewer")}
      className="space-y-4 rounded-xl border border-kps-primary/30 p-3"
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h5 className="font-semibold text-gray-900 dark:text-white">
          {t(large ? "spa.photos.zoom" : "spa.photos.compare")}
        </h5>
        <SpaAction
          secondary
          onClick={() => (large && !single ? setZoom(undefined) : onClose())}
        >
          {t(
            large && !single ? "spa.photos.backCompare" : "spa.photos.collapse",
          )}
        </SpaAction>
      </div>
      <SpaHint>{t("spa.photos.compareHint")}</SpaHint>
      {large ? (
        image(large)
      ) : (
        <div className="grid items-start gap-4 sm:grid-cols-2">
          {[first, second].map((p, index) => (
            <div key={index} className="min-w-0 space-y-3">
              <Label htmlFor={`${id}-${index}`}>
                {t(index === 0 ? "spa.photos.first" : "spa.photos.second")}
              </Label>
              <Select
                id={`${id}-${index}`}
                placeholder={t("spa.photos.chooseComparison")}
                value={p.id}
                onChange={index === 0 ? setFirst : setSecond}
                options={photos.map((photo) => ({
                  value: photo.id,
                  label: label(photo),
                }))}
                className={spaFieldClasses}
              />
              {image(p)}
            </div>
          ))}
        </div>
      )}
    </section>
  );
}
