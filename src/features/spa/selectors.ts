import type {
  SpaAppointment,
  SpaAppointmentStatus,
  SpaPreviewSnapshot,
  SpaTechnicianTask,
} from "./types";

const normalize = (value: string) =>
  value
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/đ/gi, "d")
    .toLowerCase()
    .trim();

export function filterSpaAppointments(
  appointments: SpaAppointment[],
  query: string,
  status: SpaAppointmentStatus | "all",
) {
  const normalizedQuery = normalize(query);
  return appointments
    .filter(
      (item) =>
        (status === "all" || item.status === status) &&
        normalize(`${item.customerName} ${item.id}`).includes(normalizedQuery),
    )
    .toSorted((a, b) => a.startTime.localeCompare(b.startTime));
}

export function getSpaOverview(snapshot: SpaPreviewSnapshot) {
  const active = snapshot.appointments.filter(
    (item) => item.status !== "cancelled" && item.date === snapshot.date,
  );
  const tasks = active.flatMap((item) => item.tasks);
  return {
    appointments: active.length,
    waiting: active.filter(
      (item) =>
        item.status === "arrived" &&
        item.tasks.some((task) =>
          ["unassigned", "assigned"].includes(task.status),
        ),
    ).length,
    inProgress: tasks.filter((task) => task.status === "in_progress").length,
    completed: tasks.filter((task) => task.status === "completed").length,
  };
}

export function getSpaTechnicianTasks(
  snapshot: SpaPreviewSnapshot,
  technicianId: string,
): SpaTechnicianTask[] {
  return snapshot.appointments
    .filter(
      (appointment) =>
        appointment.status !== "cancelled" &&
        appointment.date === snapshot.date,
    )
    .flatMap((appointment) =>
      appointment.tasks
        .filter(
          (task) =>
            task.technicianId === technicianId ||
            task.handover?.toId === technicianId ||
            task.participants?.includes(technicianId),
        )
        .map((task) => ({
          ...task,
          customerName: appointment.customerName,
          appointmentId: appointment.id,
          appointmentNote: appointment.note,
        })),
    )
    .toSorted((a, b) => a.startTime.localeCompare(b.startTime));
}
