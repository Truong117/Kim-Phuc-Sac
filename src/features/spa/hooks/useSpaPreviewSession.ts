import { useAuthorization } from "@/hooks/useAuthorization";
import { useEffect, useRef, useState } from "react";
import { useAuth } from "@/hooks/useAuth";
import { applySpaPreviewCommand } from "../previewWorkflow";
import { spaPreviewRepository } from "../services/spaPreviewRepository";
import type { SpaPreviewContext } from "./useSpaPreviewSnapshot";

export default function useSpaPreviewSession(): SpaPreviewContext {
  const { currentUser } = useAuth();
  const { workspaces } = useAuthorization();
  const urls = useRef(new Set<string>());
  useEffect(
    () => () => {
      urls.current.forEach((url) => URL.revokeObjectURL(url));
      urls.current.clear();
    },
    [],
  );
  const [snapshot, setSnapshot] = useState(spaPreviewRepository.readSnapshot);
  const current = useRef(snapshot);
  const [generation, setGeneration] = useState(0);
  const currentGeneration = useRef(0);
  const execute: SpaPreviewContext["execute"] = (command) => {
    if (!currentUser || generation !== currentGeneration.current)
      return "invalidTransition";
    const result = applySpaPreviewCommand(
      current.current,
      command,
      { id: String(currentUser.id), name: currentUser.name },
      new Date().toISOString(),
      {
        manage: workspaces.some(
          (w) => w.id === "spa" && w.sections.includes("settings"),
        ),
        technicianId: workspaces.some(
          (w) => w.id === "spa" && w.sections.includes("technician"),
        )
          ? current.current.previewTechnician.id
          : null,
      },
    );
    if (!result.error) {
      if (command.kind === "photo-add")
        command.photos.forEach((p) => urls.current.add(p.url));
      if (command.kind === "photo-remove") {
        const photo = current.current.skinPhotos.find(
          (p) => p.id === command.photoId,
        );
        if (photo?.url) {
          URL.revokeObjectURL(photo.url);
          urls.current.delete(photo.url);
        }
      }
      current.current = result.snapshot;
      setSnapshot(result.snapshot);
    }
    return result.error;
  };
  const reset = () => {
    currentGeneration.current++;
    setGeneration(currentGeneration.current);
    urls.current.forEach((url) => URL.revokeObjectURL(url));
    urls.current.clear();
    const fresh = spaPreviewRepository.readSnapshot();
    current.current = fresh;
    setSnapshot(fresh);
  };
  return {
    snapshot,
    execute,
    reset,
    photoAccess: {
      manage: workspaces.some(
        (w) => w.id === "spa" && w.sections.includes("settings"),
      ),
      technicianId: workspaces.some(
        (w) => w.id === "spa" && w.sections.includes("technician"),
      )
        ? snapshot.previewTechnician.id
        : null,
    },
  };
}
