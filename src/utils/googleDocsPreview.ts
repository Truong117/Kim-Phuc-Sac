import type { WeeklyPlanGoogleDocsPreviewTab } from "../types/weeklyPlanning";

const DOCUMENT_ID_PATTERN = /^[A-Za-z0-9_-]{20,200}$/;

export const isValidGoogleDocsUrl = (value: string): boolean => {
  try {
    const url = new URL(value.trim());
    const match = url.pathname.match(/^\/document\/d\/([^/]+)(?:\/.*)?$/);

    return (
      url.protocol === "https:" &&
      url.hostname.toLowerCase() === "docs.google.com" &&
      url.username === "" &&
      url.password === "" &&
      (url.port === "" || url.port === "443") &&
      match !== null &&
      DOCUMENT_ID_PATTERN.test(match[1])
    );
  } catch {
    return false;
  }
};

export type GoogleDocsPreviewErrorKey =
  | "network"
  | "sessionExpired"
  | "forbidden"
  | "notFound"
  | "changed"
  | "invalidUrl"
  | "validation"
  | "rateLimited"
  | "providerResponse"
  | "unavailable"
  | "unknown";

export const getGoogleDocsPreviewErrorKey = (
  status: number,
): GoogleDocsPreviewErrorKey => {
  if (status === 0) return "network";
  if (status === 401 || status === 419) return "sessionExpired";
  if (status === 403) return "forbidden";
  if (status === 404) return "notFound";
  if (status === 409) return "changed";
  if (status === 422) return "validation";
  if (status === 429) return "rateLimited";
  if (status === 502) return "providerResponse";
  if (status === 503) return "unavailable";

  return "unknown";
};

export type GoogleDocsPreviewTreeNode = WeeklyPlanGoogleDocsPreviewTab & {
  children: GoogleDocsPreviewTreeNode[];
};

export const buildGoogleDocsTabTree = (
  tabs: readonly WeeklyPlanGoogleDocsPreviewTab[],
): GoogleDocsPreviewTreeNode[] => {
  const roots: GoogleDocsPreviewTreeNode[] = [];
  const nodes = new Map<string, GoogleDocsPreviewTreeNode>();

  [...tabs]
    .sort((first, second) => first.order - second.order)
    .forEach((tab) => {
      if (nodes.has(tab.tab_id)) return;

      const node: GoogleDocsPreviewTreeNode = { ...tab, children: [] };
      const parent = tab.parent_tab_id
        ? nodes.get(tab.parent_tab_id)
        : undefined;

      if (parent && parent.tab_id !== node.tab_id) {
        parent.children.push(node);
      } else {
        roots.push(node);
      }

      nodes.set(tab.tab_id, node);
    });

  return roots;
};

type TimeoutHandle = ReturnType<typeof globalThis.setTimeout>;

type ActiveGoogleDocsPreviewRequest = {
  controller: AbortController;
  timeout: TimeoutHandle | null;
};

export type GoogleDocsPreviewRequestCallbacks<TResult> = {
  onStart: () => void;
  onSuccess: (result: TResult) => void;
  onError: (error: unknown) => void;
  onTimeout: () => void;
  onSettled: () => void;
};

export class GoogleDocsPreviewRequestLifecycle<TResult> {
  private activeRequest: ActiveGoogleDocsPreviewRequest | null = null;
  private readonly timeoutMs: number;
  private readonly setTimer: (
    callback: () => void,
    delay: number,
  ) => TimeoutHandle;
  private readonly clearTimer: (handle: TimeoutHandle) => void;

  constructor(
    timeoutMs: number,
    setTimer: (callback: () => void, delay: number) => TimeoutHandle = (
      callback,
      delay,
    ) => globalThis.setTimeout(callback, delay),
    clearTimer: (handle: TimeoutHandle) => void = (handle) =>
      globalThis.clearTimeout(handle),
  ) {
    this.timeoutMs = timeoutMs;
    this.setTimer = setTimer;
    this.clearTimer = clearTimer;
  }

  run(
    request: (signal: AbortSignal) => Promise<TResult>,
    callbacks: GoogleDocsPreviewRequestCallbacks<TResult>,
  ): Promise<void> {
    this.cancel();

    const activeRequest: ActiveGoogleDocsPreviewRequest = {
      controller: new AbortController(),
      timeout: null,
    };
    this.activeRequest = activeRequest;
    callbacks.onStart();
    activeRequest.timeout = this.setTimer(
      () => {
        if (!this.isActive(activeRequest)) return;

        this.activeRequest = null;
        this.clearRequestTimeout(activeRequest);
        activeRequest.controller.abort();
        callbacks.onTimeout();
        callbacks.onSettled();
      },
      Math.max(1, this.timeoutMs),
    );

    return request(activeRequest.controller.signal)
      .then((result) => {
        if (this.isActive(activeRequest)) callbacks.onSuccess(result);
      })
      .catch((error: unknown) => {
        if (this.isActive(activeRequest)) callbacks.onError(error);
      })
      .finally(() => {
        if (!this.isActive(activeRequest)) return;

        this.activeRequest = null;
        this.clearRequestTimeout(activeRequest);
        callbacks.onSettled();
      });
  }

  cancel(): void {
    const activeRequest = this.activeRequest;
    this.activeRequest = null;
    if (!activeRequest) return;

    this.clearRequestTimeout(activeRequest);
    activeRequest.controller.abort();
  }

  private isActive(request: ActiveGoogleDocsPreviewRequest): boolean {
    return this.activeRequest === request;
  }

  private clearRequestTimeout(request: ActiveGoogleDocsPreviewRequest): void {
    if (request.timeout === null) return;

    this.clearTimer(request.timeout);
    request.timeout = null;
  }
}
