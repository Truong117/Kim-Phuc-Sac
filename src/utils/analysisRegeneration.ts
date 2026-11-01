export interface PendingRegeneration {
  key: string;
  sourceId: number;
}

// Only an operation pointer is retained. Feedback and report content never enter browser storage.
export function readPendingRegeneration(
  storage: Pick<Storage, "getItem">,
  name: string,
): PendingRegeneration | null {
  try {
    const value: unknown = JSON.parse(storage.getItem(name) ?? "null");
    if (typeof value !== "object" || value === null) return null;
    const { key, sourceId } = value as Partial<PendingRegeneration>;
    return typeof key === "string" &&
      /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(
        key,
      ) &&
      Number.isSafeInteger(sourceId) &&
      sourceId! > 0
      ? { key, sourceId: sourceId! }
      : null;
  } catch {
    return null;
  }
}

export const feedbackLength = (feedback: string) => Array.from(feedback).length;
