import {
  isSpaBusinessCommand,
  applySpaBusinessCommand,
} from "./previewBusiness";
import type {
  SpaActor,
  SpaAppointment,
  SpaPreviewCommand,
  SpaPreviewError,
  SpaPreviewSnapshot,
} from "./types";

export const normalizeSpaText = (value: string) =>
  value
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/đ/gi, "d")
    .toLowerCase()
    .trim();
export const normalizeSpaPhone = (value: string) => {
  const phone = value.replace(/[\s().-]/g, "");
  return phone.startsWith("+84")
    ? `0${phone.slice(3)}`
    : phone.startsWith("84") && phone.length === 11
      ? `0${phone.slice(2)}`
      : phone;
};
export const spaTimeMinutes = (value: string): number | null =>
  /^([01]\d|2[0-3]):[0-5]\d$/.test(value)
    ? Number(value.slice(0, 2)) * 60 + Number(value.slice(3))
    : null;
export const spaTimeLabel = (minutes: number) =>
  `${String(Math.floor(minutes / 60)).padStart(2, "0")}:${String(minutes % 60).padStart(2, "0")}`;
const overlaps = (
  start: number,
  duration: number,
  otherStart: number,
  otherDuration: number,
) => start < otherStart + otherDuration && otherStart < start + duration;
export const spaReceptionStage = (appointment: SpaAppointment) =>
  appointment.status === "cancelled"
    ? "cancelled"
    : appointment.status === "booked"
      ? "booked"
      : appointment.tasks.some((task) => task.status === "in_progress")
        ? "serving"
        : appointment.tasks.every((task) => task.status === "completed") &&
            appointment.tasks.length > 0
          ? "completed"
          : appointment.tasks.some((task) => task.status === "unassigned")
            ? "waiting"
            : "assigned";

/** Pure, in-memory UI simulation. Never sends data or authorizes a business operation. */
export function applySpaPreviewCommand(
  snapshot: SpaPreviewSnapshot,
  command: SpaPreviewCommand,
  actor: SpaActor,
  at: string,
): { snapshot: SpaPreviewSnapshot; error?: SpaPreviewError } {
  if (isSpaBusinessCommand(command))
    return applySpaBusinessCommand(snapshot, command, actor, at);
  const fail = (error: SpaPreviewError) => ({ snapshot, error });
  const next = structuredClone(snapshot);
  if (command.kind === "technician") {
    const technician = next.technicians.find((item) => item.id === command.id);
    if (!technician) return fail("technicianRequired");
    next.previewTechnician = technician;
    return { snapshot: next };
  }
  if (command.kind === "customer") {
    const name = command.customer.name.trim();
    const phone = normalizeSpaPhone(command.customer.phone);
    if (!name || name.length > 100) return fail("nameRequired");
    if (!/^\d{10,15}$/.test(phone)) return fail("phoneInvalid");
    if (command.customer.note.length > 1000) return fail("noteTooLong");
    if (
      next.customers.some(
        (item) =>
          item.id !== command.id && normalizeSpaPhone(item.phone) === phone,
      )
    )
      return fail("phoneDuplicate");
    const customer = {
      id: command.id,
      name,
      phone,
      note: command.customer.note.trim(),
    };
    const existing = next.customers.findIndex((item) => item.id === command.id);
    if (existing < 0) next.customers.push(customer);
    else next.customers[existing] = customer;
    next.appointments
      .filter((item) => item.customerId === customer.id)
      .forEach((item) => {
        item.customerName = name;
      });
    return { snapshot: next };
  }
  const appointment = next.appointments.find((item) => item.id === command.id);
  const audit = (
    kind:
      | "created"
      | "updated"
      | "arrived"
      | "assigned"
      | "reassigned"
      | "cancelled",
    detail?: string,
  ) => ({ kind, actor, at, detail });
  if (command.kind === "booking") {
    if (
      (command.editing &&
        (!appointment ||
          appointment.status !== "booked" ||
          appointment.tasks.some((task) =>
            ["in_progress", "completed"].includes(task.status),
          ))) ||
      (!command.editing && appointment)
    )
      return fail("invalidTransition");
    const draft = command.draft;
    const customer = next.customers.find(
      (item) => item.id === draft.customerId,
    );
    if (!customer) return fail("customerRequired");
    if (
      !/^\d{4}-\d{2}-\d{2}$/.test(draft.date) ||
      Number.isNaN(Date.parse(`${draft.date}T00:00:00Z`)) ||
      new Date(`${draft.date}T00:00:00Z`).toISOString().slice(0, 10) !==
        draft.date
    )
      return fail("dateInvalid");
    const start = spaTimeMinutes(draft.startTime);
    const services = draft.serviceKeys.map((key) =>
      next.services.find((item) => item.key === key),
    );
    if (!services.length || services.some((item) => !item))
      return fail("serviceRequired");
    const duration = services.reduce(
      (sum, service) => sum + service!.durationMinutes,
      0,
    );
    if (
      start === null ||
      start < spaTimeMinutes(next.settings.open)! ||
      start + duration > spaTimeMinutes(next.settings.close)!
    )
      return fail("timeInvalid");
    if (draft.note.length > 1000) return fail("noteTooLong");
    if (
      next.appointments.some(
        (item) =>
          item.id !== command.id &&
          item.customerId === customer.id &&
          item.date === draft.date &&
          item.status !== "cancelled" &&
          item.tasks.some((task) =>
            overlaps(
              start,
              duration,
              spaTimeMinutes(task.startTime)!,
              task.durationMinutes,
            ),
          ),
      )
    )
      return fail("customerConflict");
    for (let index = 0; index < services.length; index++) {
      const packageId = draft.packageIds?.[index];
      if (!packageId) continue;
      const pack = next.packages.find(
        (p) =>
          p.id === packageId &&
          p.customerId === customer.id &&
          p.serviceKey === services[index]!.key,
      );
      if (!pack || pack.expires < draft.date) return fail("packageInvalid");
      if (pack.used >= pack.total) return fail("packageEmpty");
    }
    let cursor = start;
    const tasks = services.map((service, index) => {
      const task = {
        id: `${command.id}-service-${index + 1}`,
        serviceKey: service!.key,
        serviceName: service!.name,
        unitPrice: service!.price,
        packageId: draft.packageIds?.[index] || undefined,
        technicianId: "",
        technicianName: "",
        room: "",
        startTime: spaTimeLabel(cursor),
        durationMinutes: service!.durationMinutes,
        status: "unassigned" as const,
        requiresSkinPhoto: service!.requiresSkinPhoto,
        noteKey: "noSpecialNote",
      };
      cursor += service!.durationMinutes;
      return task;
    });
    const saved: SpaAppointment = {
      ...appointment,
      id: command.id,
      customerId: customer.id,
      customerName: customer.name,
      date: draft.date,
      startTime: draft.startTime,
      status: "booked",
      tasks,
      note: draft.note.trim(),
      audit: [
        ...(appointment?.audit ?? []),
        audit(command.editing ? "updated" : "created"),
      ],
    };
    if (appointment)
      next.appointments[next.appointments.indexOf(appointment)] = saved;
    else next.appointments.push(saved);
    return { snapshot: next };
  }
  if (!appointment) return fail("notFound");
  if (command.kind === "receive") {
    if (appointment.status !== "booked") return fail("invalidTransition");
    appointment.status = "arrived";
    appointment.receivedBy = actor;
    appointment.receivedAt = at;
    appointment.audit = [...(appointment.audit ?? []), audit("arrived")];
  }
  if (command.kind === "cancel") {
    if (
      appointment.status === "cancelled" ||
      appointment.tasks.some((task) =>
        ["in_progress", "completed"].includes(task.status),
      )
    )
      return fail("invalidTransition");
    if (!command.reason.trim() || command.reason.trim().length > 500)
      return fail("cancelReasonRequired");
    appointment.status = "cancelled";
    appointment.cancelReason = command.reason.trim();
    appointment.audit = [
      ...(appointment.audit ?? []),
      audit("cancelled", appointment.cancelReason),
    ];
  }
  if (command.kind === "assign") {
    const task = appointment.tasks.find((item) => item.id === command.taskId);
    if (
      appointment.status !== "arrived" ||
      !task ||
      !["unassigned", "assigned"].includes(task.status)
    )
      return fail("invalidTransition");
    const technician = next.technicians.find(
      (item) => item.id === command.technicianId,
    );
    const bed = next.beds.find((item) => item.id === command.bedId);
    if (!technician) return fail("technicianRequired");
    if (!bed) return fail("bedRequired");
    const start = spaTimeMinutes(command.startTime);
    if (
      start === null ||
      start < spaTimeMinutes(next.settings.open)! ||
      start + task.durationMinutes > spaTimeMinutes(next.settings.close)!
    )
      return fail("timeInvalid");
    for (const other of next.appointments.filter(
      (item) => item.status !== "cancelled" && item.date === appointment.date,
    )) {
      for (const occupied of other.tasks.filter(
        (item) => item.id !== task.id,
      )) {
        if (
          !overlaps(
            start,
            task.durationMinutes,
            spaTimeMinutes(occupied.startTime)!,
            occupied.durationMinutes,
          )
        )
          continue;
        if (other.customerId === appointment.customerId)
          return fail("customerConflict");
        if (["unassigned", "completed"].includes(occupied.status)) continue;
        if (
          occupied.technicianId === technician.id ||
          occupied.handover?.toId === technician.id
        )
          return fail("technicianConflict");
        if (occupied.bedId === bed.id) return fail("bedConflict");
      }
    }
    const kind = task.status === "assigned" ? "reassigned" : "assigned";
    Object.assign(task, {
      technicianId: technician.id,
      technicianName: technician.name,
      bedId: bed.id,
      bedNumber: bed.number,
      room: bed.room,
      startTime: command.startTime,
      status: "assigned",
      assignedBy: actor,
      assignedAt: at,
    });
    appointment.audit = [
      ...(appointment.audit ?? []),
      audit(
        kind,
        `${technician.name} · ${bed.room}/${bed.number} · ${command.startTime}`,
      ),
    ];
  }
  return { snapshot: next };
}
