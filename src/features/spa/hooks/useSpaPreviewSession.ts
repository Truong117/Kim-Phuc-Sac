import { useRef, useState } from "react";
import { useAuth } from "@/hooks/useAuth";
import { applySpaPreviewCommand } from "../previewWorkflow";
import { spaPreviewRepository } from "../services/spaPreviewRepository";
import type { SpaPreviewContext } from "./useSpaPreviewSnapshot";

export default function useSpaPreviewSession(): SpaPreviewContext {
  const { currentUser } = useAuth();
  const [snapshot, setSnapshot] = useState(spaPreviewRepository.readSnapshot);
  const current = useRef(snapshot);
  const execute: SpaPreviewContext["execute"] = (command) => {
    if (!currentUser) return "invalidTransition";
    const result = applySpaPreviewCommand(
      current.current,
      command,
      { id: String(currentUser.id), name: currentUser.name },
      new Date().toISOString(),
    );
    if (!result.error) {
      current.current = result.snapshot;
      setSnapshot(result.snapshot);
    }
    return result.error;
  };
  const reset = () => {
    const fresh = spaPreviewRepository.readSnapshot();
    current.current = fresh;
    setSnapshot(fresh);
  };
  return { snapshot, execute, reset };
}
