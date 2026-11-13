import type { SpaPreviewSnapshot, SpaServiceTask } from "./types";
export const spaMoney = (value: number) =>
  new Intl.NumberFormat("vi-VN", {
    style: "currency",
    currency: "VND",
    maximumFractionDigits: 0,
  }).format(value);
export const spaDateValid = (value: string) =>
  /^\d{4}-\d{2}-\d{2}$/.test(value) &&
  !Number.isNaN(Date.parse(`${value}T00:00:00Z`)) &&
  new Date(`${value}T00:00:00Z`).toISOString().slice(0, 10) === value;
export const spaMinutes = (value: string) =>
  /^([01]\d|2[0-3]):[0-5]\d$/.test(value)
    ? Number(value.slice(0, 2)) * 60 + Number(value.slice(3))
    : -1;
export const spaShiftDate = (value: string, days: number) => {
  const date = new Date(`${value}T00:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
};
export const spaInvoicePaid = (
  snapshot: SpaPreviewSnapshot,
  id: string,
  until = "9999-12-31",
) =>
  snapshot.payments
    .filter((p) => p.invoiceId === id && p.date <= until)
    .reduce((sum, p) => sum + p.amount, 0);
export const spaInvoiceBalance = (
  snapshot: SpaPreviewSnapshot,
  id: string,
  until?: string,
) =>
  Math.max(
    0,
    (snapshot.invoices.find((i) => i.id === id)?.total ?? 0) -
      spaInvoicePaid(snapshot, id, until),
  );
export const spaTaskPrice = (
  snapshot: SpaPreviewSnapshot,
  task: SpaServiceTask,
) =>
  task.unitPrice ??
  snapshot.services.find((s) => s.key === task.serviceKey)?.price ??
  0;
export const spaTaskName = (
  snapshot: SpaPreviewSnapshot,
  task: SpaServiceTask,
) =>
  task.serviceName ??
  snapshot.services.find((s) => s.key === task.serviceKey)?.name ??
  task.serviceKey;
export const spaDayTasks = (snapshot: SpaPreviewSnapshot, date: string) =>
  snapshot.appointments
    .filter((a) => a.date === date && a.status !== "cancelled")
    .flatMap((a) =>
      a.tasks.map((task) => ({
        ...task,
        customerName: a.customerName,
        appointmentId: a.id,
        appointmentNote: a.note,
      })),
    );
export function spaReport(
  snapshot: SpaPreviewSnapshot,
  from: string,
  to: string,
) {
  const visits = snapshot.appointments.filter(
    (a) => a.date >= from && a.date <= to,
  );
  const invoices = snapshot.invoices.filter(
    (i) => i.date >= from && i.date <= to,
  );
  const payments = snapshot.payments.filter(
    (p) => p.date >= from && p.date <= to,
  );
  const expenses = snapshot.expenses.filter(
    (p) => p.date >= from && p.date <= to,
  );
  const tasks = visits
    .filter((a) => a.status !== "cancelled")
    .flatMap((a) => a.tasks);
  return {
    visits,
    invoices,
    payments,
    expenses,
    tasks,
    collected: payments.reduce((s, p) => s + p.amount, 0),
    billed: invoices.reduce((s, i) => s + i.total, 0),
    spent: expenses.reduce((s, p) => s + p.amount, 0),
    completed: visits.filter(
      (a) =>
        a.status !== "cancelled" &&
        a.tasks.length > 0 &&
        a.tasks.every((t) => t.status === "completed"),
    ).length,
    outstanding: invoices.reduce(
      (s, i) => s + spaInvoiceBalance(snapshot, i.id, to),
      0,
    ),
  };
}
