import { useCallback, useEffect } from "react";
import { useBeforeUnload } from "react-router";

type NavigationEvent = {
  defaultPrevented?: boolean;
  button?: number;
  metaKey?: boolean;
  ctrlKey?: boolean;
  shiftKey?: boolean;
  altKey?: boolean;
  target?: unknown;
  state?: unknown;
  preventDefault: () => void;
  stopPropagation?: () => void;
  stopImmediatePropagation?: () => void;
};

type NavigationTarget = {
  href?: string;
  target?: string;
  hasAttribute?: (name: string) => boolean;
};

export type WeeklyPlanV3NavigationHost = {
  location: { href: string };
  history: {
    state: unknown;
    go: (delta: number) => void;
    pushState: (
      data: unknown,
      unused: string,
      url?: string | URL | null,
    ) => void;
  };
  confirm: (message?: string) => boolean;
  addEventListener: (
    type: "click" | "popstate",
    listener: (event: NavigationEvent) => void,
    options?: boolean | AddEventListenerOptions,
  ) => void;
  removeEventListener: (
    type: "click" | "popstate",
    listener: (event: NavigationEvent) => void,
    options?: boolean | EventListenerOptions,
  ) => void;
};

const historyIndex = (value: unknown): number | null => {
  if (typeof value !== "object" || value === null || !("idx" in value)) {
    return null;
  }

  return typeof value.idx === "number" && Number.isInteger(value.idx)
    ? value.idx
    : null;
};

const closestNavigationTarget = (target: unknown): NavigationTarget | null => {
  if (
    typeof target !== "object" ||
    target === null ||
    !("closest" in target) ||
    typeof target.closest !== "function"
  ) {
    return null;
  }

  return target.closest("a[href]") as NavigationTarget | null;
};

export const installWeeklyPlanV3NavigationSafety = (
  host: WeeklyPlanV3NavigationHost,
  warning: string,
  enabled = true,
): (() => void) => {
  if (!enabled) return () => undefined;

  const currentHref = host.location.href;
  const currentHistoryState = host.history.state;
  const currentIndex = historyIndex(currentHistoryState);
  let restoringHistory = false;

  const onClick = (event: NavigationEvent) => {
    if (
      event.defaultPrevented ||
      (event.button !== undefined && event.button !== 0) ||
      event.metaKey ||
      event.ctrlKey ||
      event.shiftKey ||
      event.altKey
    ) {
      return;
    }

    const target = closestNavigationTarget(event.target);
    if (
      !target?.href ||
      (target.target && target.target !== "_self") ||
      target.hasAttribute?.("download") ||
      new URL(target.href, currentHref).href === currentHref
    ) {
      return;
    }

    if (host.confirm(warning)) return;

    event.preventDefault();
    event.stopPropagation?.();
    event.stopImmediatePropagation?.();
  };

  const onPopState = (event: NavigationEvent) => {
    if (restoringHistory) {
      restoringHistory = false;
      return;
    }
    if (host.confirm(warning)) return;

    event.preventDefault();
    event.stopImmediatePropagation?.();
    const nextIndex = historyIndex(event.state);
    if (
      currentIndex !== null &&
      nextIndex !== null &&
      currentIndex !== nextIndex
    ) {
      restoringHistory = true;
      host.history.go(currentIndex - nextIndex);
      return;
    }

    host.history.pushState(currentHistoryState, "", currentHref);
  };

  host.addEventListener("click", onClick, true);
  host.addEventListener("popstate", onPopState, true);

  return () => {
    host.removeEventListener("click", onClick, true);
    host.removeEventListener("popstate", onPopState, true);
  };
};

export const useWeeklyPlanV3NavigationSafety = (
  enabled: boolean,
  warning: string,
) => {
  useBeforeUnload(
    useCallback(
      (event) => {
        if (!enabled) return;
        event.preventDefault();
        event.returnValue = "";
      },
      [enabled],
    ),
  );

  useEffect(() => {
    if (!enabled || typeof window === "undefined") return;

    return installWeeklyPlanV3NavigationSafety(
      window as unknown as WeeklyPlanV3NavigationHost,
      warning,
      true,
    );
  }, [enabled, warning]);
};
