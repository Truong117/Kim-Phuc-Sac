import type { SpaActor } from "./index";
export type SpaPhotoStage = "before" | "after";
export interface SpaSkinPhotoInput {
  id: string;
  url: string;
  name: string;
  bytes: number;
  width: number;
  height: number;
  stage: SpaPhotoStage;
  caption: string;
  source: "camera" | "library";
}
export interface SpaSkinPhoto extends SpaSkinPhotoInput {
  appointmentId: string;
  taskId: string;
  customerId: string;
  date: string;
  technicianId: string;
  createdBy: SpaActor;
  createdAt: string;
  removedAt?: string;
  removedBy?: SpaActor;
  removeReason?: string;
}
export interface SpaPhotoAccess {
  manage: boolean;
  technicianId: string | null;
}
export type SpaPhotoCommand =
  | {
      kind: "photo-add";
      id: string;
      taskId: string;
      photos: SpaSkinPhotoInput[];
    }
  | { kind: "photo-remove"; id: string; photoId: string; reason: string };
