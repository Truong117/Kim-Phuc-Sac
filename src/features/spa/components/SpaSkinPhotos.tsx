import SpaPhotoRemoval from "./SpaPhotoRemoval";
import { useEffect, useId, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import { useAuth } from "@/hooks/useAuth";
import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import { useWeeklyPlanV3NavigationSafety } from "@/utils/weeklyPlanV3NavigationSafety";
import { useSpaPreview } from "../hooks/useSpaPreviewSnapshot";
import { prepareSkinPhoto } from "../prepareSkinPhoto";
import {
  getSpaCustomerPhotos,
  spaPhotoCanAdd,
  spaPhotoCanRemove,
  spaPhotoCanView,
  spaPhotoLimits,
} from "../skinPhotos";
import { spaFieldClasses } from "../config";
import type {
  SpaPhotoStage,
  SpaPreviewError,
  SpaServiceTask,
  SpaSkinPhotoInput,
} from "../types";
import SpaFormError from "./SpaFormError";
import SpaPhotoViewer from "./SpaPhotoViewer";
import { SpaAction, SpaHint, SpaTabs } from "./business/SpaBusinessUi";
export default function SpaSkinPhotos({
  appointmentId,
  task,
}: {
  appointmentId: string;
  task: SpaServiceTask;
}) {
  const { t } = useTranslation();
  const id = useId();
  const { snapshot, execute, photoAccess } = useSpaPreview();
  const { currentUser } = useAuth();
  const camera = useRef<HTMLInputElement>(null),
    library = useRef<HTMLInputElement>(null);
  const [stage, setStage] = useState<SpaPhotoStage>(
      task.status === "completed" ? "after" : "before",
    ),
    [caption, setCaption] = useState(""),
    [error, setError] = useState<SpaPreviewError>(),
    [busy, setBusy] = useState(false),
    [viewer, setViewer] = useState<string>(),
    [remove, setRemove] = useState<string>();
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);
  const working = useRef(false);
  const [notice, setNotice] = useState(false);
  useWeeklyPlanV3NavigationSafety(
    Boolean(caption || busy),
    t("spa.flow.discard"),
  );
  const appointment = snapshot.appointments.find((a) => a.id === appointmentId);
  const photos = snapshot.skinPhotos.filter(
    (p) =>
      !p.removedAt && p.taskId === task.id && p.appointmentId === appointmentId,
  );
  const all = appointment
    ? getSpaCustomerPhotos(snapshot, appointment.customerId, photoAccess)
    : [];
  const add = spaPhotoCanAdd(snapshot, appointmentId, task, photoAccess),
    canView = spaPhotoCanView(task, photoAccess);
  const effectiveStage =
    !photoAccess.manage && task.status === "completed" ? "after" : stage;
  const addFiles = async (
    files: FileList | null,
    source: "camera" | "library",
  ) => {
    if (!files?.length || working.current) return;
    const list = Array.from(files);
    setError(undefined);
    setNotice(false);
    if (
      list.length + photos.filter((p) => p.stage === effectiveStage).length >
      spaPhotoLimits.perStage
    ) {
      setError("photoLimit");
      return;
    }
    if (caption.length > 500) {
      setError("photoCaptionTooLong");
      return;
    }
    working.current = true;
    setBusy(true);
    const prepared: SpaSkinPhotoInput[] = [];
    let adopted = false;
    try {
      for (const file of list) {
        prepared.push(
          await prepareSkinPhoto(file, effectiveStage, caption, source),
        );
        if (!mounted.current) return;
      }
      const error = execute({
        kind: "photo-add",
        id: appointmentId,
        taskId: task.id,
        photos: prepared,
      });
      if (error) {
        setError(error);
        return;
      }
      adopted = true;
      setCaption("");
      setNotice(true);
    } catch (error) {
      if (mounted.current)
        setError(
          error instanceof Error &&
            [
              "photoTypeInvalid",
              "photoFileTooLarge",
              "photoCaptionTooLong",
            ].includes(error.message)
            ? (error.message as SpaPreviewError)
            : "photoUnreadable",
        );
    } finally {
      if (!adopted) prepared.forEach((p) => URL.revokeObjectURL(p.url));
      working.current = false;
      if (mounted.current) setBusy(false);
    }
  };
  if (!canView) return null;
  return (
    <section
      aria-label={t("spa.photos.title")}
      className="mt-6 space-y-4 border-t border-gray-200 pt-5 dark:border-gray-700"
    >
      <div className="flex flex-wrap justify-between gap-2">
        <h4 className="font-semibold text-gray-900 dark:text-white">
          {t("spa.photos.title")}
        </h4>
        <span className="text-xs text-gray-500 dark:text-gray-400">
          {t("spa.photos.count", { count: photos.length })}
        </span>
      </div>
      <SpaHint>{t("spa.photos.previewHint")}</SpaHint>
      <SpaFormError error={error} />
      {notice && (
        <p
          role="status"
          className="text-sm text-success-700 dark:text-success-400"
        >
          {t("spa.photos.added")}
        </p>
      )}
      {add && (
        <fieldset
          disabled={busy}
          className="space-y-3 rounded-xl bg-gray-50 p-3 dark:bg-gray-800"
        >
          <SpaTabs
            value={effectiveStage}
            onChange={(value) => {
              setStage(value as SpaPhotoStage);
              setError(undefined);
            }}
            items={(task.status === "completed" && !photoAccess.manage
              ? ["after"]
              : ["before", "after"]
            ).map((key) => ({ key, label: t(`spa.photos.${key}`) }))}
          />
          <div>
            <Label htmlFor={`${id}-caption`}>{t("spa.photos.caption")}</Label>
            <TextArea
              id={`${id}-caption`}
              value={caption}
              onChange={(v) => {
                setCaption(v);
                setError(undefined);
              }}
              placeholder={t("spa.photos.captionHint")}
              className={spaFieldClasses}
              error={error === "photoCaptionTooLong"}
              disabled={busy}
            />
          </div>
          <div className="flex flex-wrap gap-2">
            <SpaAction disabled={busy} onClick={() => camera.current?.click()}>
              {t("spa.photos.camera")}
            </SpaAction>
            <SpaAction
              secondary
              disabled={busy}
              onClick={() => library.current?.click()}
            >
              {t("spa.photos.library")}
            </SpaAction>
          </div>
          <input
            ref={camera}
            aria-label={t("spa.photos.cameraInput")}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            capture="environment"
            className="hidden"
            disabled={busy}
            onChange={(e) => {
              void addFiles(e.currentTarget.files, "camera");
              e.currentTarget.value = "";
            }}
          />
          <input
            ref={library}
            aria-label={t("spa.photos.libraryInput")}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            multiple
            className="hidden"
            disabled={busy}
            onChange={(e) => {
              void addFiles(e.currentTarget.files, "library");
              e.currentTarget.value = "";
            }}
          />
          <SpaHint>{t("spa.photos.limits")}</SpaHint>
          {busy && (
            <p
              role="status"
              className="text-sm text-gray-600 dark:text-gray-300"
            >
              {t("spa.photos.processing")}
            </p>
          )}
        </fieldset>
      )}
      {!add && <SpaHint>{t("spa.photos.readOnly")}</SpaHint>}
      <div className="grid gap-4 sm:grid-cols-2">
        {(["before", "after"] as const).map((phase) => (
          <div key={phase} className="min-w-0 space-y-3">
            <h5 className="text-sm font-semibold text-gray-800 dark:text-gray-200">
              {t(`spa.photos.${phase}`)}
            </h5>
            {photos.filter((p) => p.stage === phase).length ? (
              <div className="grid grid-cols-2 gap-2">
                {photos
                  .filter((p) => p.stage === phase)
                  .map((p) => (
                    <figure
                      key={p.id}
                      className={`min-w-0 space-y-2 rounded-lg border border-gray-200 p-2 dark:border-gray-700 ${remove === p.id ? "col-span-2" : ""}`}
                    >
                      <button
                        type="button"
                        onClick={() => setViewer(p.id)}
                        aria-label={t("spa.photos.open", {
                          name: p.name,
                          stage: t(`spa.photos.${p.stage}`),
                        })}
                        className="block min-h-11 w-full rounded-lg focus-visible:ring-2 focus-visible:ring-kps-primary focus-visible:outline-none"
                      >
                        <img
                          src={p.url}
                          alt={t("spa.photos.alt", {
                            stage: t(`spa.photos.${p.stage}`),
                            date: p.date,
                            visit: p.appointmentId,
                          })}
                          className="aspect-square w-full rounded-lg object-cover"
                        />
                      </button>
                      <figcaption className="space-y-1 text-xs break-words text-gray-600 dark:text-gray-300">
                        <p>{p.caption || p.name}</p>
                        <p>{p.createdBy.name}</p>
                        <p>
                          {new Intl.DateTimeFormat("vi-VN", {
                            timeZone: "Asia/Ho_Chi_Minh",
                            dateStyle: "short",
                            timeStyle: "short",
                          }).format(new Date(p.createdAt))}
                        </p>
                      </figcaption>
                      {spaPhotoCanRemove(
                        p,
                        task,
                        photoAccess,
                        String(currentUser?.id),
                      ) && (
                        <SpaAction
                          secondary
                          disabled={busy}
                          onClick={() => {
                            setRemove(p.id);
                            setNotice(false);
                            setError(undefined);
                          }}
                        >
                          {t("spa.photos.remove")}
                        </SpaAction>
                      )}
                      {remove === p.id && (
                        <SpaPhotoRemoval
                          appointmentId={appointmentId}
                          photoId={p.id}
                          onClose={() => setRemove(undefined)}
                        />
                      )}
                    </figure>
                  ))}
              </div>
            ) : (
              <SpaHint>{t("spa.photos.empty")}</SpaHint>
            )}
          </div>
        ))}
      </div>
      {all.length > 0 && (
        <SpaAction
          secondary
          onClick={() => setViewer(photos[0]?.id ?? all[0].id)}
        >
          {t("spa.photos.compare")}
        </SpaAction>
      )}
      {viewer && (
        <SpaPhotoViewer
          key={viewer}
          photos={all}
          initialId={viewer}
          onClose={() => setViewer(undefined)}
        />
      )}
    </section>
  );
}
