import { useState } from "react";
import { spaPreviewRepository } from "../services/spaPreviewRepository";

export default function useSpaPreviewSnapshot() {
  const [snapshot] = useState(() => spaPreviewRepository.readSnapshot());
  return snapshot;
}
