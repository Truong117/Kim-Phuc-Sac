import { useOutletContext } from "react-router";
import type { SpaBookingCapabilities } from "../services/spaBookingService";
import type {
  SpaPhotoAccess,
  SpaPreviewCommand,
  SpaPreviewError,
  SpaPreviewSnapshot,
} from "../types";

export interface SpaPreviewContext {
  snapshot: SpaPreviewSnapshot;
  execute: (command: SpaPreviewCommand) => SpaPreviewError | undefined;
  reset: () => void;
  photoAccess: SpaPhotoAccess;
}
export type SpaBookingAccessState =
  | { status: "loading" }
  | { status: "error"; retry: () => void }
  | { status: "ready"; capabilities: SpaBookingCapabilities };

export const useSpaPreview = () => useOutletContext<SpaPreviewContext>();
export const useSpaBookingAccess = () =>
  useOutletContext<{ bookingAccess: SpaBookingAccessState }>().bookingAccess;
export default function useSpaPreviewSnapshot() {
  return useSpaPreview().snapshot;
}
