import type {
  SpaActor,
  SpaPhotoAccess,
  SpaPhotoCommand,
  SpaPreviewError,
  SpaPreviewSnapshot,
  SpaServiceTask,
  SpaSkinPhoto,
} from "./types";
export const spaPhotoLimits = {
  perStage: 6,
  fileBytes: 10 * 1024 * 1024,
  previewBytes: 2 * 1024 * 1024,
  sessionBytes: 24 * 1024 * 1024,
  edge: 1600,
};
export const spaPhotoCanView = (task: SpaServiceTask, access: SpaPhotoAccess) =>
  access.manage ||
  Boolean(
    access.technicianId &&
    (task.technicianId === access.technicianId ||
      task.participants?.includes(access.technicianId) ||
      task.handover?.toId === access.technicianId),
  );
export function spaPhotoCanAdd(
  snapshot: SpaPreviewSnapshot,
  appointmentId: string,
  task: SpaServiceTask,
  access: SpaPhotoAccess,
) {
  return (
    snapshot.appointments.some(
      (a) => a.id === appointmentId && a.status === "arrived",
    ) &&
    ["assigned", "in_progress", "completed"].includes(task.status) &&
    Boolean(task.technicianId) &&
    (access.manage ||
      Boolean(access.technicianId === task.technicianId && !task.handover))
  );
}
export const spaPhotoCanRemove = (
  photo: SpaSkinPhoto,
  task: SpaServiceTask,
  access: SpaPhotoAccess,
  actorId: string,
) =>
  access.manage ||
  Boolean(
    access.technicianId === task.technicianId &&
    access.technicianId === photo.technicianId &&
    photo.createdBy.id === actorId &&
    task.status !== "completed" &&
    !task.handover,
  );
export const getSpaCustomerPhotos = (
  snapshot: SpaPreviewSnapshot,
  customerId: string,
  access: SpaPhotoAccess,
) =>
  snapshot.skinPhotos
    .filter(
      (p) =>
        !p.removedAt &&
        p.customerId === customerId &&
        snapshot.appointments.some(
          (a) =>
            a.id === p.appointmentId &&
            a.tasks.some(
              (task) => task.id === p.taskId && spaPhotoCanView(task, access),
            ),
        ),
    )
    .toSorted((a, b) => b.createdAt.localeCompare(a.createdAt));
export function applySpaPhotoCommand(
  snapshot: SpaPreviewSnapshot,
  command: SpaPhotoCommand,
  actor: SpaActor,
  at: string,
  access: SpaPhotoAccess,
): { snapshot: SpaPreviewSnapshot; error?: SpaPreviewError } {
  const fail = (error: SpaPreviewError) => ({ snapshot, error });
  const appointment = snapshot.appointments.find((a) => a.id === command.id);
  if (!appointment) return fail("notFound");
  if (command.kind === "photo-add") {
    const task = appointment.tasks.find((task) => task.id === command.taskId);
    if (!task || !spaPhotoCanAdd(snapshot, appointment.id, task, access))
      return fail("photoForbidden");
    if (
      !command.photos.length ||
      command.photos.length > spaPhotoLimits.perStage
    )
      return fail("photoLimit");
    const ids = new Set(snapshot.skinPhotos.map((p) => p.id));
    for (const p of command.photos) {
      if (ids.has(p.id)) return fail("duplicateOperation");
      ids.add(p.id);
      if (
        !p.id ||
        !p.url.startsWith("blob:") ||
        !p.name.trim() ||
        p.name.length > 150 ||
        !["before", "after"].includes(p.stage) ||
        !["camera", "library"].includes(p.source) ||
        p.caption.length > 500 ||
        !Number.isInteger(p.width) ||
        !Number.isInteger(p.height) ||
        Math.min(p.width, p.height) < 1 ||
        Math.max(p.width, p.height) > spaPhotoLimits.edge ||
        !Number.isSafeInteger(p.bytes) ||
        p.bytes < 1 ||
        p.bytes > spaPhotoLimits.previewBytes
      )
        return fail("photoInvalid");
      if (!access.manage && task.status === "completed" && p.stage !== "after")
        return fail("photoForbidden");
    }
    for (const stage of ["before", "after"]) {
      if (
        snapshot.skinPhotos.filter(
          (p) => p.taskId === task.id && !p.removedAt && p.stage === stage,
        ).length +
          command.photos.filter((p) => p.stage === stage).length >
        spaPhotoLimits.perStage
      )
        return fail("photoLimit");
    }
    if (
      snapshot.skinPhotos
        .filter((p) => !p.removedAt)
        .reduce((s, p) => s + p.bytes, 0) +
        command.photos.reduce((s, p) => s + p.bytes, 0) >
      spaPhotoLimits.sessionBytes
    )
      return fail("photoSessionLimit");
    const next = structuredClone(snapshot),
      visit = next.appointments.find((a) => a.id === appointment.id)!;
    next.skinPhotos.push(
      ...command.photos.map((p) => ({
        ...p,
        caption: p.caption.trim(),
        appointmentId: visit.id,
        customerId: visit.customerId,
        taskId: task.id,
        date: visit.date,
        technicianId: access.manage ? task.technicianId : access.technicianId!,
        createdBy: actor,
        createdAt: at,
      })),
    );
    visit.audit = [
      ...(visit.audit ?? []),
      {
        kind: "photoAdded",
        actor,
        at,
        detail: command.photos.map((p) => p.name).join(" · "),
      },
    ];
    return { snapshot: next };
  }
  const photo = snapshot.skinPhotos.find(
      (p) =>
        p.id === command.photoId &&
        p.appointmentId === appointment.id &&
        !p.removedAt,
    ),
    task = appointment.tasks.find((t) => t.id === photo?.taskId);
  if (!photo || !task) return fail("notFound");
  if (!spaPhotoCanRemove(photo, task, access, actor.id))
    return fail("photoForbidden");
  if (!command.reason.trim() || command.reason.length > 500)
    return fail("reasonRequired");
  const next = structuredClone(snapshot),
    saved = next.skinPhotos.find((p) => p.id === photo.id)!;
  Object.assign(saved, {
    url: "",
    removedAt: at,
    removedBy: actor,
    removeReason: command.reason.trim(),
  });
  const visit = next.appointments.find((a) => a.id === appointment.id)!;
  visit.audit = [
    ...(visit.audit ?? []),
    {
      kind: "photoRemoved",
      actor,
      at,
      detail: `${photo.name} · ${command.reason.trim()}`,
    },
  ];
  return { snapshot: next };
}
