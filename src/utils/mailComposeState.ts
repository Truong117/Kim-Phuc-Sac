import type { MailHistoryEntry } from "@/services/mailService";
import { mailTemplateIds, type MailTemplateId } from "./mailTemplates";

export type MailTemplateState = {
  kind: MailTemplateId | "";
  date: string;
  page: number;
  selected: string;
  sourceLabel: string;
  ready: boolean;
};
export type MailDraft = {
  selectedIds: string[];
  subject: string;
  body: string;
  template?: MailTemplateState;
};
export type MailRun = MailDraft & {
  id: string;
  keys: Record<string, string>;
  results?: MailHistoryEntry[];
  missing?: string[];
  checked?: boolean;
  readError?: boolean;
};
export type MailComposeState = { draft: MailDraft; runs: MailRun[] };
export const emptyMailDraft = (): MailDraft => ({
  selectedIds: [],
  subject: "",
  body: "",
});
export const hasMailDraft = (draft: MailDraft) =>
  Boolean(
    draft.subject ||
    draft.body ||
    draft.selectedIds.length ||
    draft.template?.kind,
  );
export const finishedMailRun = (run: MailRun) =>
  Boolean(
    run.checked &&
    !run.missing?.length &&
    run.results?.length === run.selectedIds.length &&
    run.results.every((entry) =>
      ["accepted", "failed", "cancelled"].includes(entry.status),
    ),
  );
export const resumableMailRun = (run: MailRun) =>
  Boolean(
    run.checked &&
    !run.readError &&
    (run.missing?.length ||
      run.results?.some((entry) => entry.status === "queued")),
  );
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
function draftOf(value: unknown): MailDraft {
  const source = value as Partial<MailDraft> | null;
  const template = source?.template;
  return {
    selectedIds: Array.isArray(source?.selectedIds)
      ? source.selectedIds
          .filter(
            (id): id is string => typeof id === "string" && /^\d+$/.test(id),
          )
          .slice(0, 50)
      : [],
    subject: typeof source?.subject === "string" ? source.subject : "",
    body: typeof source?.body === "string" ? source.body : "",
    ...(template &&
    (template.kind === "" || mailTemplateIds.includes(template.kind)) &&
    /^\d{4}-\d{2}-\d{2}$/.test(template.date)
      ? {
          template: {
            kind: template.kind,
            date: template.date,
            page:
              Number.isInteger(template.page) && template.page > 0
                ? template.page
                : 1,
            selected:
              typeof template.selected === "string" ? template.selected : "",
            sourceLabel:
              typeof template.sourceLabel === "string"
                ? template.sourceLabel
                : "",
            ready: template.ready === true,
          },
        }
      : {}),
  };
}
export function parseMailComposeState(
  saved: string | null,
  legacy: string | null,
): MailComposeState {
  if (saved) {
    const value = JSON.parse(saved) as { draft?: unknown; runs?: unknown[] };
    if (!Array.isArray(value.runs)) throw new Error("MAIL_STORAGE_INVALID");
    return {
      draft: draftOf(value.draft),
      runs: value.runs.map((raw) => {
        const run = raw as MailRun;
        const keys = Object.fromEntries(
          Object.entries(run.keys ?? {}).filter(
            ([id, key]) =>
              /^\d+$/.test(id) && typeof key === "string" && uuid.test(key),
          ),
        );
        if (
          Object.keys(keys).length !== Object.keys(run.keys ?? {}).length ||
          !Object.keys(keys).length ||
          Object.keys(keys).length > 50 ||
          typeof run.id !== "string"
        )
          throw new Error("MAIL_STORAGE_INVALID");
        // A saved result is not authoritative: always re-read without processing.
        return {
          ...draftOf(run),
          id: run.id,
          keys,
          selectedIds: Object.keys(keys),
        };
      }),
    };
  }
  if (!legacy) return { draft: emptyMailDraft(), runs: [] };
  const old = JSON.parse(legacy);
  const keys = Object.fromEntries(
    Object.entries(old.keys ?? old).filter(
      ([id, key]) =>
        /^\d+$/.test(id) && typeof key === "string" && uuid.test(key),
    ),
  ) as Record<string, string>;
  const draft = draftOf(old);
  return Object.keys(keys).length
    ? {
        draft: emptyMailDraft(),
        runs: [
          {
            ...draft,
            id: Object.values(keys)[0],
            selectedIds: Object.keys(keys),
            keys,
          },
        ],
      }
    : { draft, runs: [] };
}
