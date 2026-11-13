import { useOutletContext } from "react-router";
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
export const useSpaPreview = () => useOutletContext<SpaPreviewContext>();
export default function useSpaPreviewSnapshot() {
  return useSpaPreview().snapshot;
}
