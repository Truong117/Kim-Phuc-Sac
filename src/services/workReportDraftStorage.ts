import type { WorkItemFormData } from "@/types/reports";
import type { QuickReportDrafts } from "@/utils/quickReport";
import { validTaskLink } from "@/utils/taskProgress";

export const WORK_REPORT_DRAFT_SCHEMA_VERSION = 2;
export const WORK_REPORT_DRAFT_TTL_MS = 7 * 24 * 60 * 60 * 1000;
const WORK_REPORT_DRAFT_KEY_PREFIX = "kps:work-report:draft:v2:";
const LEGACY_DRAFT_KEY_PREFIX = "kps:work-report:draft:v1:";

export type WorkReportEditorMode = "quick" | "detailed";

export type WorkReportDraftContext = {
  userId: string;
  reportDate: string;
};

export type WorkReportDraftData = {
  taskDrafts?: WorkItemFormData[];
  items: WorkItemFormData[];
  quickDrafts: QuickReportDrafts;
  editorMode: WorkReportEditorMode;
  nextDayPlan?: string;
};

export type WorkReportDraftRecord = WorkReportDraftContext & {
  schemaVersion: 1 | typeof WORK_REPORT_DRAFT_SCHEMA_VERSION;
  revision: string;
  writerId: string;
  updatedAt: string;
  serverReportId: number | null;
  serverUpdatedAt: string | null;
  data: WorkReportDraftData;
};

export type DraftLoadResult =
  | { status: "found"; draft: WorkReportDraftRecord }
  | {
      status: "missing" | "expired" | "invalid" | "unsupported" | "unavailable";
    };

export type DraftWriteResult =
  | { status: "saved"; draft: WorkReportDraftRecord }
  | { status: "removed" }
  | { status: "conflict"; current: WorkReportDraftRecord | null }
  | { status: "unavailable" };

export interface DraftStorageLike {
  readonly length: number;
  key(index: number): string | null;
  getItem(key: string): string | null;
  setItem(key: string, value: string): void;
  removeItem(key: string): void;
}

export type SaveDraftOptions = {
  writerId: string;
  expectedRevision: string | null;
  serverReportId: number | null;
  serverUpdatedAt: string | null;
  force?: boolean;
};

export interface WorkReportDraftAdapter {
  load(context: WorkReportDraftContext): DraftLoadResult;
  save(
    context: WorkReportDraftContext,
    data: WorkReportDraftData,
    options: SaveDraftOptions,
  ): DraftWriteResult;
  remove(
    context: WorkReportDraftContext,
    expectedRevision: string | null,
    force?: boolean,
  ): DraftWriteResult;
  cleanupExpired(): void;
}

const isString = (value: unknown): value is string => typeof value === "string";

const isWorkItem = (value: unknown): value is WorkItemFormData => {
  if (!value || typeof value !== "object") return false;
  const item = value as Record<string, unknown>;

  return (
    isString(item.clientId) &&
    (item.taskLink === undefined || validTaskLink(item.taskLink)) &&
    (item.id === undefined ||
      (Number.isInteger(item.id) && Number(item.id) > 0)) &&
    (item.issue === undefined || isString(item.issue)) &&
    (item.evidenceLinks === undefined || isString(item.evidenceLinks)) &&
    (item.facebookSelections === undefined ||
      (Array.isArray(item.facebookSelections) &&
        item.facebookSelections.length <= 10 &&
        item.facebookSelections.every(
          (row) =>
            row &&
            typeof row === "object" &&
            isString(row.source_url) &&
            isString(row.selection_token) &&
            isString(row.post_url) &&
            isString(row.page_name) &&
            isString(row.external_id) &&
            Number.isInteger(row.entry_id),
        ))) &&
    isString(item.content) &&
    isString(item.result) &&
    isString(item.note) &&
    (item.status === "" ||
      item.status === "COMPLETED" ||
      item.status === "IN_PROGRESS" ||
      item.status === "BLOCKED")
  );
};

const isQuickDrafts = (value: unknown): value is QuickReportDrafts => {
  if (!value || typeof value !== "object") return false;
  const drafts = value as Record<string, unknown>;

  return (
    isString(drafts.COMPLETED) &&
    isString(drafts.IN_PROGRESS) &&
    isString(drafts.BLOCKED)
  );
};

const isDraftRecord = (value: unknown): value is WorkReportDraftRecord => {
  if (!value || typeof value !== "object") return false;
  const draft = value as Record<string, unknown>;
  const data = draft.data as Record<string, unknown> | undefined;

  return (
    (draft.schemaVersion === 1 ||
      draft.schemaVersion === WORK_REPORT_DRAFT_SCHEMA_VERSION) &&
    isString(draft.userId) &&
    isString(draft.reportDate) &&
    isString(draft.revision) &&
    isString(draft.writerId) &&
    isString(draft.updatedAt) &&
    !Number.isNaN(Date.parse(draft.updatedAt)) &&
    (draft.serverReportId === null ||
      (typeof draft.serverReportId === "number" &&
        Number.isInteger(draft.serverReportId))) &&
    (draft.serverUpdatedAt === null || isString(draft.serverUpdatedAt)) &&
    Boolean(data) &&
    (data?.nextDayPlan === undefined || isString(data.nextDayPlan)) &&
    (data?.editorMode === "quick" || data?.editorMode === "detailed") &&
    Array.isArray(data?.items) &&
    data.items.every(isWorkItem) &&
    (data.taskDrafts === undefined ||
      (Array.isArray(data.taskDrafts) &&
        data.taskDrafts.length <= 50 &&
        data.taskDrafts.every(
          (row) => isWorkItem(row) && validTaskLink(row.taskLink),
        ))) &&
    isQuickDrafts(data?.quickDrafts)
  );
};

export const workReportDraftKey = ({
  userId,
  reportDate,
}: WorkReportDraftContext): string =>
  `${WORK_REPORT_DRAFT_KEY_PREFIX}${encodeURIComponent(userId)}:${reportDate}`;

export const draftDataSignature = (data: WorkReportDraftData): string =>
  JSON.stringify(data);

export const hasMeaningfulDraftData = (data: WorkReportDraftData): boolean =>
  Boolean(data.taskDrafts?.length) ||
  data.items.some(
    (item) =>
      Boolean(item.content.trim()) ||
      Boolean(item.result.trim()) ||
      Boolean(item.note.trim()) ||
      Boolean(item.issue?.trim()) ||
      Boolean(item.evidenceLinks?.trim()) ||
      Boolean(item.status),
  ) ||
  Boolean(data.nextDayPlan?.trim()) ||
  Object.values(data.quickDrafts).some((value) => Boolean(value.trim()));

export const parseWorkReportDraftValue = (
  value: string | null,
  context: WorkReportDraftContext,
  now: number = Date.now(),
): DraftLoadResult => {
  if (value === null) return { status: "missing" };

  try {
    const draft: unknown = JSON.parse(value);
    if (
      draft &&
      typeof draft === "object" &&
      "schemaVersion" in draft &&
      ![1, 2].includes(Number(draft.schemaVersion))
    )
      return { status: "unsupported" };
    if (!isDraftRecord(draft)) return { status: "invalid" };
    if (
      draft.userId !== context.userId ||
      draft.reportDate !== context.reportDate
    ) {
      return { status: "invalid" };
    }
    if (now - Date.parse(draft.updatedAt) > WORK_REPORT_DRAFT_TTL_MS) {
      return { status: "expired" };
    }

    return { status: "found", draft };
  } catch {
    return { status: "invalid" };
  }
};

const defaultRevisionFactory = () => {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
    return crypto.randomUUID();
  }
  return `draft-${Date.now()}-${Math.random().toString(36).slice(2)}`;
};

export class WorkReportDraftStorage implements WorkReportDraftAdapter {
  private readonly storage: DraftStorageLike;
  private readonly now: () => number;
  private readonly revisionFactory: () => string;

  constructor(
    storage: DraftStorageLike,
    now: () => number = Date.now,
    revisionFactory: () => string = defaultRevisionFactory,
  ) {
    this.storage = storage;
    this.now = now;
    this.revisionFactory = revisionFactory;
  }

  private legacyKey(context: WorkReportDraftContext): string {
    return workReportDraftKey(context).replace(
      WORK_REPORT_DRAFT_KEY_PREFIX,
      LEGACY_DRAFT_KEY_PREFIX,
    );
  }

  private readStored(context: WorkReportDraftContext): string | null {
    const current = this.storage.getItem(workReportDraftKey(context));
    const legacy = this.storage.getItem(this.legacyKey(context));
    if (current !== null && legacy !== null) {
      const newer = parseWorkReportDraftValue(current, context, this.now());
      const older = parseWorkReportDraftValue(legacy, context, this.now());
      if (
        newer.status === "found" &&
        older.status === "found" &&
        Date.parse(older.draft.updatedAt) > Date.parse(newer.draft.updatedAt)
      )
        return legacy;
    }
    return current ?? legacy;
  }

  load(context: WorkReportDraftContext): DraftLoadResult {
    const key = workReportDraftKey(context);

    try {
      const result = parseWorkReportDraftValue(
        this.readStored(context),
        context,
        this.now(),
      );
      if (result.status === "expired" || result.status === "invalid") {
        this.storage.removeItem(key);
      }
      return result;
    } catch {
      return { status: "unavailable" };
    }
  }

  save(
    context: WorkReportDraftContext,
    data: WorkReportDraftData,
    options: SaveDraftOptions,
  ): DraftWriteResult {
    const key = workReportDraftKey(context);

    try {
      const currentResult = parseWorkReportDraftValue(
        this.readStored(context),
        context,
        this.now(),
      );
      if (currentResult.status === "unsupported")
        return { status: "unavailable" };
      const current =
        currentResult.status === "found" ? currentResult.draft : null;

      if (
        !options.force &&
        (current?.revision ?? null) !== options.expectedRevision
      ) {
        return { status: "conflict", current };
      }

      const draft: WorkReportDraftRecord = {
        schemaVersion: WORK_REPORT_DRAFT_SCHEMA_VERSION,
        ...context,
        revision: this.revisionFactory(),
        writerId: options.writerId,
        updatedAt: new Date(this.now()).toISOString(),
        serverReportId: options.serverReportId,
        serverUpdatedAt: options.serverUpdatedAt,
        data,
      };
      this.storage.setItem(key, JSON.stringify(draft));

      return { status: "saved", draft };
    } catch {
      return { status: "unavailable" };
    }
  }

  remove(
    context: WorkReportDraftContext,
    expectedRevision: string | null,
    force = false,
  ): DraftWriteResult {
    const key = workReportDraftKey(context);

    try {
      const currentResult = parseWorkReportDraftValue(
        this.readStored(context),
        context,
        this.now(),
      );
      if (currentResult.status === "unsupported")
        return { status: "unavailable" };
      const current =
        currentResult.status === "found" ? currentResult.draft : null;

      if (!force && (current?.revision ?? null) !== expectedRevision) {
        return { status: "conflict", current };
      }

      this.storage.removeItem(key);
      this.storage.removeItem(this.legacyKey(context));
      return { status: "removed" };
    } catch {
      return { status: "unavailable" };
    }
  }

  cleanupExpired(): void {
    try {
      const keys = Array.from({ length: this.storage.length }, (_, index) =>
        this.storage.key(index),
      ).filter(
        (key): key is string =>
          typeof key === "string" &&
          (key.startsWith(WORK_REPORT_DRAFT_KEY_PREFIX) ||
            key.startsWith(LEGACY_DRAFT_KEY_PREFIX)),
      );

      for (const key of keys) {
        const raw = this.storage.getItem(key);
        let shouldRemove = false;
        try {
          const value: unknown = raw === null ? null : JSON.parse(raw);
          if (
            value &&
            typeof value === "object" &&
            "schemaVersion" in value &&
            ![1, 2].includes(Number(value.schemaVersion))
          )
            continue;
          shouldRemove =
            !isDraftRecord(value) ||
            this.now() - Date.parse(value.updatedAt) > WORK_REPORT_DRAFT_TTL_MS;
        } catch {
          shouldRemove = true;
        }

        if (shouldRemove) this.storage.removeItem(key);
      }
    } catch {
      // Storage privacy settings or quota implementations may reject access.
    }
  }
}

export const createBrowserWorkReportDraftStorage =
  (): WorkReportDraftStorage | null => {
    try {
      return typeof window === "undefined"
        ? null
        : new WorkReportDraftStorage(window.localStorage);
    } catch {
      return null;
    }
  };
