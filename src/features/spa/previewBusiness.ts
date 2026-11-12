import type {
  SpaActor,
  SpaBusinessCommand,
  SpaPreviewCommand,
  SpaPreviewError,
  SpaPreviewSnapshot,
} from "./types";
import {
  spaDateValid,
  spaInvoiceBalance,
  spaMinutes,
  spaTaskName,
  spaTaskPrice,
} from "./businessSelectors";
const kinds = [
  "service-save",
  "package-sell",
  "retail-sell",
  "payment",
  "stock",
  "expense",
  "care-create",
  "care-result",
  "attendance",
  "settings",
  "task-start",
  "task-draft",
  "task-complete",
  "handover-request",
  "handover-accept",
  "handover-withdraw",
];
export const isSpaBusinessCommand = (
  command: SpaPreviewCommand,
): command is SpaBusinessCommand => kinds.includes(command.kind);
const moneyValid = (value: number) =>
  Number.isSafeInteger(value) && value >= 0 && value <= 1_000_000_000;
const amountValid = (value: number) => moneyValid(value) && value > 0;
export function applySpaBusinessCommand(
  snapshot: SpaPreviewSnapshot,
  command: SpaBusinessCommand,
  actor: SpaActor,
  at: string,
): { snapshot: SpaPreviewSnapshot; error?: SpaPreviewError } {
  const fail = (error: SpaPreviewError) => ({ snapshot, error });
  const next = structuredClone(snapshot);
  if ("date" in command && !spaDateValid(command.date))
    return fail("dateInvalid");
  if (
    "id" in command &&
    [
      "payment",
      "expense",
      "retail-sell",
      "package-sell",
      "stock",
      "care-create",
    ].includes(command.kind) &&
    [
      ...next.invoices,
      ...next.payments,
      ...next.expenses,
      ...next.movements,
      ...next.careTasks,
      ...next.packages,
    ].some((i) => i.id === command.id)
  )
    return fail("duplicateOperation");
  if (command.kind === "service-save") {
    if (!command.name.trim() || command.name.trim().length > 100)
      return fail("nameRequired");
    if (
      !Number.isInteger(command.duration) ||
      command.duration < 15 ||
      command.duration > 180 ||
      command.duration % 15 !== 0
    )
      return fail("timeInvalid");
    if (!moneyValid(command.price)) return fail("amountInvalid");
    if (command.description.length > 1000) return fail("noteTooLong");
    const existing = next.services.find((s) => s.key === command.id);
    if (
      existing &&
      (existing.durationMinutes !== command.duration ||
        (existing.price ?? 0) !== command.price) &&
      next.appointments.some((a) =>
        a.tasks.some((t) => t.serviceKey === command.id),
      )
    )
      return fail("serviceInUse");
    const data = {
      key: command.id,
      name: command.name.trim(),
      durationMinutes: command.duration,
      price: command.price,
      group: command.group.trim(),
      description: command.description.trim(),
      requiresSkinPhoto: command.requiresSkinPhoto,
    };
    if (existing) Object.assign(existing, data);
    else next.services.push(data);
  }
  if (command.kind === "package-sell") {
    const customer = next.customers.find((c) => c.id === command.customerId),
      service = next.services.find((s) => s.key === command.serviceKey);
    if (!customer) return fail("customerRequired");
    if (!service) return fail("serviceRequired");
    if (
      !Number.isInteger(command.total) ||
      command.total < 1 ||
      command.total > 100
    )
      return fail("quantityInvalid");
    if (!moneyValid(command.price)) return fail("amountInvalid");
    if (!spaDateValid(command.expires) || command.expires < command.date)
      return fail("dateInvalid");
    const name = `${service.name ?? service.key} · ${command.total}`;
    next.packages.push({
      id: command.id,
      customerId: customer.id,
      serviceKey: service.key,
      name,
      total: command.total,
      used: 0,
      expires: command.expires,
      price: command.price,
      uses: [],
    });
    next.invoices.push({
      id: `INV-${command.id}`,
      customerId: customer.id,
      kind: "package",
      date: command.date,
      lines: [{ name, quantity: 1, price: command.price }],
      total: command.price,
      actor,
    });
  }
  if (command.kind === "retail-sell") {
    if (!next.customers.some((c) => c.id === command.customerId))
      return fail("customerRequired");
    const product = next.products.find(
      (p) => p.id === command.productId && p.retail,
    );
    if (!product) return fail("notFound");
    if (!Number.isInteger(command.quantity) || command.quantity < 1)
      return fail("quantityInvalid");
    if (command.quantity > product.stock) return fail("stockInsufficient");
    const total = product.price * command.quantity;
    if (!moneyValid(total)) return fail("amountInvalid");
    product.stock -= command.quantity;
    next.movements.push({
      id: `MOV-${command.id}`,
      productId: product.id,
      quantity: -command.quantity,
      reason: command.id,
      date: command.date,
      actor,
    });
    next.invoices.push({
      id: command.id,
      customerId: command.customerId,
      kind: "retail",
      date: command.date,
      lines: [
        {
          name: product.name,
          quantity: command.quantity,
          price: product.price,
        },
      ],
      total,
      actor,
    });
  }
  if (command.kind === "payment") {
    const invoice = next.invoices.find((i) => i.id === command.invoiceId);
    if (!invoice) return fail("notFound");
    if (
      !amountValid(command.amount) ||
      command.amount > spaInvoiceBalance(next, invoice.id)
    )
      return fail("amountInvalid");
    if (command.date < invoice.date) return fail("dateInvalid");
    if (!["cash", "transfer"].includes(command.method))
      return fail("invalidTransition");
    next.payments.push({
      id: command.id,
      invoiceId: invoice.id,
      amount: command.amount,
      method: command.method,
      date: command.date,
      actor,
    });
  }
  if (command.kind === "stock") {
    const product = next.products.find((p) => p.id === command.productId);
    if (!product) return fail("notFound");
    if (
      !Number.isSafeInteger(command.quantity) ||
      command.quantity < 1 ||
      command.quantity > 1_000_000
    )
      return fail("quantityInvalid");
    if (!command.reason.trim() || command.reason.length > 500)
      return fail("reasonRequired");
    if (command.direction === "out" && command.quantity > product.stock)
      return fail("stockInsufficient");
    const quantity =
      command.direction === "out" ? -command.quantity : command.quantity;
    product.stock += quantity;
    next.movements.push({
      id: command.id,
      productId: product.id,
      quantity,
      reason: command.reason.trim(),
      date: command.date,
      actor,
    });
  }
  if (command.kind === "expense") {
    if (!amountValid(command.amount)) return fail("amountInvalid");
    if (!command.reason.trim() || command.reason.length > 500)
      return fail("reasonRequired");
    if (!["cash", "transfer"].includes(command.method))
      return fail("invalidTransition");
    next.expenses.push({
      id: command.id,
      amount: command.amount,
      reason: command.reason.trim(),
      date: command.date,
      method: command.method,
      actor,
    });
  }
  if (command.kind === "care-create") {
    if (!next.customers.some((c) => c.id === command.customerId))
      return fail("customerRequired");
    if (!command.title.trim() || command.title.length > 200)
      return fail("nameRequired");
    if (!spaDateValid(command.due)) return fail("dateInvalid");
    if (
      !next.roster.some((s) => s.id === command.staffId) ||
      !["phone", "zalo", "email"].includes(command.channel)
    )
      return fail("invalidTransition");
    next.careTasks.push({
      id: command.id,
      customerId: command.customerId,
      title: command.title.trim(),
      due: command.due,
      channel: command.channel,
      staffId: command.staffId,
      result: "",
      done: false,
    });
  }
  if (command.kind === "care-result") {
    const task = next.careTasks.find((t) => t.id === command.id);
    if (!task) return fail("notFound");
    if (!command.result.trim() || command.result.length > 2000)
      return fail("resultRequired");
    task.result = command.result.trim();
    task.done = true;
  }
  if (command.kind === "attendance") {
    if (!next.roster.some((s) => s.id === command.staffId))
      return fail("notFound");
    if (
      next.attendance.some(
        (a) => a.staffId === command.staffId && a.date === command.date,
      )
    )
      return fail("duplicateOperation");
    next.attendance.push({
      staffId: command.staffId,
      date: command.date,
      at,
      actor,
    });
  }
  if (command.kind === "settings") {
    if (!command.name.trim() || command.name.length > 100)
      return fail("nameRequired");
    const open = spaMinutes(command.open),
      close = spaMinutes(command.close);
    if (open < 0 || close <= open) return fail("timeInvalid");
    if (
      !Number.isFinite(command.commission) ||
      command.commission < 0 ||
      command.commission > 100
    )
      return fail("commissionInvalid");
    if (
      next.appointments.some(
        (a) =>
          a.status !== "cancelled" &&
          a.tasks.some(
            (t) =>
              spaMinutes(t.startTime) < open ||
              spaMinutes(t.startTime) + t.durationMinutes > close,
          ),
      )
    )
      return fail("settingsConflict");
    next.settings = {
      facilityName: command.name.trim(),
      open: command.open,
      close: command.close,
      commission: command.commission,
    };
  }
  if (
    command.kind.startsWith("task-") ||
    command.kind.startsWith("handover-")
  ) {
    if (!("taskId" in command)) return fail("invalidTransition");
    const appointment = next.appointments.find((a) => a.id === command.id),
      task = appointment?.tasks.find((t) => t.id === command.taskId);
    if (!appointment || appointment.status !== "arrived" || !task)
      return fail("invalidTransition");
    const log = (
      kind:
        | "started"
        | "completed"
        | "handoverRequested"
        | "handoverAccepted"
        | "handoverWithdrawn",
      detail?: string,
    ) => {
      appointment.audit = [
        ...(appointment.audit ?? []),
        { kind, actor, at, detail },
      ];
    };
    const conflict = (technicianId: string) =>
      next.appointments
        .filter((a) => a.date === appointment.date && a.status !== "cancelled")
        .flatMap((a) => a.tasks)
        .some(
          (t) =>
            t.id !== task.id &&
            ["assigned", "in_progress"].includes(t.status) &&
            (t.technicianId === technicianId ||
              t.handover?.toId === technicianId) &&
            spaMinutes(t.startTime) <
              spaMinutes(task.startTime) + task.durationMinutes &&
            spaMinutes(task.startTime) <
              spaMinutes(t.startTime) + t.durationMinutes,
        );
    if (command.kind === "task-start") {
      if (
        task.status !== "assigned" ||
        task.handover ||
        task.technicianId !== command.technicianId
      )
        return fail("invalidTransition");
      task.status = "in_progress";
      task.participants = [
        ...new Set([...(task.participants ?? []), task.technicianId]),
      ];
      log("started", task.technicianName);
    }
    if (command.kind === "task-draft") {
      if (
        task.status !== "in_progress" ||
        task.handover ||
        task.technicianId !== command.technicianId
      )
        return fail("invalidTransition");
      if ((command.result ?? "").length > 2000) return fail("noteTooLong");
      if (task.draftResult === (command.result ?? "")) return { snapshot };
      task.draftResult = command.result ?? "";
    }
    if (command.kind === "task-complete") {
      if (
        task.status !== "in_progress" ||
        task.handover ||
        task.technicianId !== command.technicianId
      )
        return fail("invalidTransition");
      if (!command.result?.trim() || command.result.length > 2000)
        return fail("resultRequired");
      if (task.packageId) {
        const pack = next.packages.find(
          (p) =>
            p.id === task.packageId &&
            p.customerId === appointment.customerId &&
            p.serviceKey === task.serviceKey,
        );
        if (!pack || pack.expires < appointment.date)
          return fail("packageInvalid");
        if (pack.used >= pack.total) return fail("packageEmpty");
        pack.used++;
        pack.uses.push({ taskId: task.id, date: appointment.date, actor });
      }
      task.result = command.result.trim();
      task.draftResult = undefined;
      task.status = "completed";
      task.completedAt = at;
      task.completedBy = actor;
      task.participants = [
        ...new Set([...(task.participants ?? []), task.technicianId]),
      ];
      log("completed", task.technicianName);
      if (
        appointment.tasks.every((t) => t.status === "completed") &&
        !next.invoices.some((i) => i.appointmentId === appointment.id)
      ) {
        const lines = appointment.tasks
          .filter((t) => !t.packageId)
          .map((t) => ({
            name: spaTaskName(next, t),
            quantity: 1,
            price: spaTaskPrice(next, t),
          }));
        if (lines.length)
          next.invoices.push({
            id: `INV-${appointment.id}`,
            appointmentId: appointment.id,
            customerId: appointment.customerId,
            kind: "service",
            date: appointment.date,
            lines,
            total: lines.reduce((sum, l) => sum + l.price * l.quantity, 0),
            actor,
          });
      }
    }
    if (command.kind === "handover-request") {
      if (
        task.status !== "in_progress" ||
        task.handover ||
        task.technicianId !== command.technicianId ||
        command.toId === task.technicianId
      )
        return fail("invalidTransition");
      const to = next.technicians.find((t) => t.id === command.toId);
      if (!to) return fail("technicianRequired");
      if (!command.reason.trim() || command.reason.length > 500)
        return fail("reasonRequired");
      if (conflict(to.id)) return fail("technicianConflict");
      task.handover = {
        toId: to.id,
        toName: to.name,
        reason: command.reason.trim(),
        actor,
        at,
      };
      log(
        "handoverRequested",
        `${task.technicianName} → ${to.name} · ${command.reason.trim()}`,
      );
    }
    if (command.kind === "handover-accept") {
      if (
        task.status !== "in_progress" ||
        !task.handover ||
        task.handover.toId !== command.technicianId
      )
        return fail("invalidTransition");
      if (conflict(command.technicianId)) return fail("technicianConflict");
      task.participants = [
        ...new Set([
          ...(task.participants ?? []),
          task.technicianId,
          task.handover.toId,
        ]),
      ];
      task.technicianId = task.handover.toId;
      task.technicianName = task.handover.toName;
      task.handover = undefined;
      log("handoverAccepted", task.technicianName);
    }
    if (command.kind === "handover-withdraw") {
      if (task.status !== "in_progress" || !task.handover)
        return fail("invalidTransition");
      if (!command.reason.trim() || command.reason.length > 500)
        return fail("reasonRequired");
      task.handover = undefined;
      log("handoverWithdrawn", command.reason.trim());
    }
  }
  return { snapshot: next };
}
