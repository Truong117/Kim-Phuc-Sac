import type {
  SpaSkinPhotoInput,
  SpaPhotoStage,
  SpaPreviewError,
} from "./types";
import { spaPhotoLimits } from "./skinPhotos";
/** Browser-only preview: normalize orientation, resize and re-encode to discard source metadata. */
export async function prepareSkinPhoto(
  file: File,
  stage: SpaPhotoStage,
  caption: string,
  source: "camera" | "library",
): Promise<SpaSkinPhotoInput> {
  if (!["image/jpeg", "image/png", "image/webp"].includes(file.type))
    throw new Error("photoTypeInvalid" satisfies SpaPreviewError);
  if (file.size < 1 || file.size > spaPhotoLimits.fileBytes)
    throw new Error("photoFileTooLarge" satisfies SpaPreviewError);
  if (caption.length > 500)
    throw new Error("photoCaptionTooLong" satisfies SpaPreviewError);
  let bitmap: ImageBitmap | undefined;
  const canvas = document.createElement("canvas");
  try {
    bitmap = await createImageBitmap(file);
    const ratio = Math.min(
      1,
      spaPhotoLimits.edge / Math.max(bitmap.width, bitmap.height),
    );
    canvas.width = Math.max(1, Math.round(bitmap.width * ratio));
    canvas.height = Math.max(1, Math.round(bitmap.height * ratio));
    const context = canvas.getContext("2d");
    if (!context) throw new Error();
    context.fillStyle = "white";
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise<Blob>((resolve, reject) =>
      canvas.toBlob(
        (blob) => (blob ? resolve(blob) : reject(new Error())),
        "image/jpeg",
        0.88,
      ),
    );
    if (blob.size > spaPhotoLimits.previewBytes)
      throw new Error("photoFileTooLarge");
    return {
      id: crypto.randomUUID(),
      url: URL.createObjectURL(blob),
      name: `${file.name.replace(/\.[^.]+$/, "").slice(0, 120) || "photo"}.jpg`,
      width: canvas.width,
      height: canvas.height,
      bytes: blob.size,
      stage,
      caption: caption.trim(),
      source,
    };
  } catch (error) {
    if (error instanceof Error && error.message === "photoFileTooLarge")
      throw error;
    throw new Error("photoUnreadable" satisfies SpaPreviewError, {
      cause: error,
    });
  } finally {
    bitmap?.close();
    canvas.width = 0;
    canvas.height = 0;
  }
}
