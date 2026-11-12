export type SpaAppointmentStatus = "booked" | "arrived" | "cancelled";
export type SpaTaskStatus =
  "unassigned" | "assigned" | "in_progress" | "completed";

export interface SpaServiceTask {
  id: string;
  serviceKey: string;
  technicianId: string;
  technicianName: string;
  room: string;
  bedId?: string;
  bedNumber?: string;
  assignedBy?: SpaActor;
  assignedAt?: string;
  startTime: string;
  durationMinutes: number;
  status: SpaTaskStatus;
  requiresSkinPhoto: boolean;
  noteKey: string;
}

export interface SpaAppointment {
  id: string;
  customerName: string;
  customerId: string;
  date: string;
  note: string;
  receivedBy?: SpaActor;
  receivedAt?: string;
  cancelReason?: string;
  audit?: SpaAuditEvent[];
  startTime: string;
  status: SpaAppointmentStatus;
  tasks: SpaServiceTask[];
}

export interface SpaPreviewSnapshot {
  date: string;
  customers: SpaCustomer[];
  services: SpaServiceDefinition[];
  technicians: SpaActor[];
  beds: SpaBed[];
  appointments: SpaAppointment[];
  previewTechnician: { id: string; name: string };
}

// UI preview boundary: replacing the mock does not require rewriting page UI.
export interface SpaPreviewRepository {
  readSnapshot: () => SpaPreviewSnapshot;
}

export interface SpaTechnicianTask extends SpaServiceTask {
  customerName: string;
  appointmentId: string;
}

export interface SpaActor {
  id: string;
  name: string;
}
export interface SpaCustomer {
  id: string;
  name: string;
  phone: string;
  note: string;
}
export interface SpaServiceDefinition {
  key: string;
  durationMinutes: number;
  requiresSkinPhoto: boolean;
}
export interface SpaBed {
  id: string;
  room: string;
  number: string;
}
export interface SpaAuditEvent {
  kind:
    "created" | "updated" | "arrived" | "assigned" | "reassigned" | "cancelled";
  actor: SpaActor;
  at: string;
  detail?: string;
}
export interface SpaBookingDraft {
  customerId: string;
  date: string;
  startTime: string;
  serviceKeys: string[];
  note: string;
}
export type SpaPreviewCommand =
  | { kind: "customer"; id: string; customer: Omit<SpaCustomer, "id"> }
  | { kind: "booking"; id: string; draft: SpaBookingDraft; editing?: boolean }
  | { kind: "receive"; id: string }
  | { kind: "cancel"; id: string; reason: string }
  | {
      kind: "assign";
      id: string;
      taskId: string;
      technicianId: string;
      bedId: string;
      startTime: string;
    }
  | { kind: "technician"; id: string };
export type SpaPreviewError =
  | "nameRequired"
  | "phoneInvalid"
  | "phoneDuplicate"
  | "customerRequired"
  | "dateInvalid"
  | "timeInvalid"
  | "serviceRequired"
  | "noteTooLong"
  | "notFound"
  | "invalidTransition"
  | "cancelReasonRequired"
  | "technicianRequired"
  | "bedRequired"
  | "technicianConflict"
  | "bedConflict"
  | "customerConflict";
