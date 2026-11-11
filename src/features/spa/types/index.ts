export type SpaAppointmentStatus = "booked" | "arrived" | "cancelled";
export type SpaTaskStatus = "assigned" | "in_progress" | "completed";

export interface SpaServiceTask {
  id: string;
  serviceKey: string;
  technicianId: string;
  technicianName: string;
  room: string;
  startTime: string;
  durationMinutes: number;
  status: SpaTaskStatus;
  requiresSkinPhoto: boolean;
  noteKey: string;
}

export interface SpaAppointment {
  id: string;
  customerName: string;
  startTime: string;
  status: SpaAppointmentStatus;
  tasks: SpaServiceTask[];
}

export interface SpaPreviewSnapshot {
  date: string;
  appointments: SpaAppointment[];
  previewTechnician: { id: string; name: string };
}

// Read-only boundary: replacing the mock does not require rewriting page UI.
export interface SpaPreviewRepository {
  readSnapshot: () => SpaPreviewSnapshot;
}

export interface SpaTechnicianTask extends SpaServiceTask {
  customerName: string;
  appointmentId: string;
}
