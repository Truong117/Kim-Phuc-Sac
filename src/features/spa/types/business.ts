import type { SpaActor } from "./index";
export interface SpaProduct {
  id: string;
  name: string;
  retail: boolean;
  unit: string;
  batch: string;
  expires: string;
  stock: number;
  minStock: number;
  price: number;
}
export interface SpaPackage {
  id: string;
  customerId: string;
  serviceKey: string;
  name: string;
  total: number;
  used: number;
  expires: string;
  price: number;
  uses: { taskId: string; date: string; actor: SpaActor }[];
}
export interface SpaInvoice {
  id: string;
  customerId: string;
  appointmentId?: string;
  kind: "service" | "package" | "retail";
  date: string;
  lines: { name: string; quantity: number; price: number }[];
  total: number;
  actor: SpaActor;
}
export interface SpaPayment {
  id: string;
  invoiceId: string;
  amount: number;
  method: "cash" | "transfer";
  date: string;
  actor: SpaActor;
}
export interface SpaExpense {
  id: string;
  reason: string;
  amount: number;
  method: "cash" | "transfer";
  date: string;
  actor: SpaActor;
}
export interface SpaMovement {
  id: string;
  productId: string;
  quantity: number;
  reason: string;
  date: string;
  actor: SpaActor;
}
export interface SpaCareTask {
  id: string;
  customerId: string;
  title: string;
  due: string;
  channel: "phone" | "zalo" | "email";
  staffId: string;
  result: string;
  done: boolean;
}
export interface SpaRosterMember extends SpaActor {
  job: "manager" | "reception" | "dispatcher" | "technician" | "cashier";
}
export interface SpaSettings {
  facilityName: string;
  open: string;
  close: string;
  commission: number;
}
export interface SpaBusinessSnapshot {
  products: SpaProduct[];
  packages: SpaPackage[];
  invoices: SpaInvoice[];
  payments: SpaPayment[];
  expenses: SpaExpense[];
  movements: SpaMovement[];
  careTasks: SpaCareTask[];
  roster: SpaRosterMember[];
  attendance: { staffId: string; date: string; at: string; actor: SpaActor }[];
  settings: SpaSettings;
}
export type SpaBusinessCommand =
  | {
      kind: "service-save";
      id: string;
      name: string;
      duration: number;
      price: number;
      group: string;
      description: string;
      requiresSkinPhoto: boolean;
    }
  | {
      kind: "package-sell";
      id: string;
      customerId: string;
      serviceKey: string;
      total: number;
      price: number;
      expires: string;
      date: string;
    }
  | {
      kind: "retail-sell";
      id: string;
      customerId: string;
      productId: string;
      quantity: number;
      date: string;
    }
  | {
      kind: "payment";
      id: string;
      invoiceId: string;
      amount: number;
      method: "cash" | "transfer";
      date: string;
    }
  | {
      kind: "stock";
      id: string;
      productId: string;
      quantity: number;
      direction: "in" | "out";
      reason: string;
      date: string;
    }
  | {
      kind: "expense";
      id: string;
      amount: number;
      reason: string;
      method: "cash" | "transfer";
      date: string;
    }
  | {
      kind: "care-create";
      id: string;
      customerId: string;
      title: string;
      due: string;
      channel: "phone" | "zalo" | "email";
      staffId: string;
    }
  | { kind: "care-result"; id: string; result: string }
  | { kind: "attendance"; staffId: string; date: string }
  | {
      kind: "settings";
      name: string;
      open: string;
      close: string;
      commission: number;
    }
  | {
      kind: "task-start" | "task-draft" | "task-complete";
      id: string;
      taskId: string;
      technicianId: string;
      result?: string;
    }
  | {
      kind: "handover-request";
      id: string;
      taskId: string;
      technicianId: string;
      toId: string;
      reason: string;
    }
  | {
      kind: "handover-accept";
      id: string;
      taskId: string;
      technicianId: string;
    }
  | { kind: "handover-withdraw"; id: string; taskId: string; reason: string };
