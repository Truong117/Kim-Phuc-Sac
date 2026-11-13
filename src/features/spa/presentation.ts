import { spaMinutes } from "./businessSelectors";
import type { SpaAppointment, SpaServiceTask } from "./types";

export const spaTime = (minutes: number) =>
  `${String(Math.floor(minutes / 60)).padStart(2, "0")}:${String(minutes % 60).padStart(2, "0")}`;
/** Include pending incoming handovers so a reserved slot is visible in both columns. */
export function spaScheduleItems(
  appointments: SpaAppointment[],
  technicianId: string,
  minimumMinutes = 0,
) {
  const items = appointments
    .filter((a) => a.status !== "cancelled")
    .flatMap((a) =>
      a.tasks
        .filter(
          (task) =>
            task.technicianId === technicianId ||
            (technicianId !== "" && task.handover?.toId === technicianId),
        )
        .map((task) => ({
          ...task,
          appointmentId: a.id,
          customerName: a.customerName,
          incoming: task.technicianId !== technicianId,
          start: spaMinutes(task.startTime),
        })),
    )
    .toSorted((a, b) => a.start - b.start || a.id.localeCompare(b.id));
  const ends: number[] = [];
  const placed = items.map((item) => {
    let lane = ends.findIndex((end) => end <= item.start);
    if (lane < 0) lane = ends.length;
    ends[lane] = item.start + Math.max(item.durationMinutes, minimumMinutes);
    return { ...item, lane };
  });
  // Each connected overlap group shares its width, including nested intervals.
  let group: typeof placed = [],
    groupEnd = -1;
  const result: ((typeof placed)[number] & { lanes: number })[] = [];
  const flush = () => {
    const lanes = Math.max(1, ...group.map((item) => item.lane + 1));
    result.push(...group.map((item) => ({ ...item, lanes })));
  };
  for (const item of placed) {
    if (item.start >= groupEnd) {
      flush();
      group = [];
      groupEnd = -1;
    }
    group.push(item);
    groupEnd = Math.max(
      groupEnd,
      item.start + Math.max(item.durationMinutes, minimumMinutes),
    );
  }
  flush();
  return result;
}

export function spaScheduleBounds(
  appointments: SpaAppointment[],
  open: string,
  close: string,
) {
  const tasks = appointments
    .filter((a) => a.status !== "cancelled")
    .flatMap((a) => a.tasks);
  const start =
    Math.floor(
      Math.min(
        spaMinutes(open),
        ...tasks.map((task) => spaMinutes(task.startTime)),
      ) / 60,
    ) * 60;
  const end =
    Math.ceil(
      Math.max(
        spaMinutes(close),
        ...tasks.map(
          (task) => spaMinutes(task.startTime) + task.durationMinutes,
        ),
      ) / 60,
    ) * 60;
  return { start, end };
}

export function spaHourlyVisits(
  appointments: SpaAppointment[],
  open: string,
  close: string,
) {
  const { start, end } = spaScheduleBounds(appointments, open, close);
  return Array.from({ length: Math.max(1, (end - start) / 60) }, (_, index) => {
    const minute = start + index * 60;
    return {
      label: spaTime(minute),
      count: appointments.filter(
        (a) =>
          a.status !== "cancelled" &&
          spaMinutes(a.startTime) >= minute &&
          spaMinutes(a.startTime) < minute + 60,
      ).length,
    };
  });
}

export function spaServiceStage(tasks: SpaServiceTask[]) {
  return tasks.some((task) => task.status === "in_progress")
    ? "serving"
    : tasks.length && tasks.every((task) => task.status === "completed")
      ? "completed"
      : "waiting";
}
