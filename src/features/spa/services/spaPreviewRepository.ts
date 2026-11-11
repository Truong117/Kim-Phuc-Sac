import { spaPreviewFixture } from "../mocks/spaPreview";
import type { SpaPreviewRepository } from "../types";

export const spaPreviewRepository: SpaPreviewRepository = {
  readSnapshot: () => structuredClone(spaPreviewFixture),
};
