// Native textarea resizing writes an inline height. Only propagate that change,
// so responsive width changes and validation hints do not resize adjacent fields.
export function syncReportTextAreas(root: HTMLElement): () => void {
  const fields = Array.from(root.querySelectorAll("textarea"));
  if (typeof ResizeObserver === "undefined") return () => {};
  const heights = new Map(fields.map((field) => [field, field.style.height]));
  const observer = new ResizeObserver((entries) => {
    for (const entry of entries) {
      const field = entry.target as HTMLTextAreaElement;
      const height = field.style.height;
      if (!height || heights.get(field) === height) continue;
      for (const peer of fields) {
        heights.set(peer, height);
        peer.style.height = height;
      }
      break;
    }
  });
  fields.forEach((field) => observer.observe(field, { box: "border-box" }));
  return () => observer.disconnect();
}
