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
  location: { href: string; replace?: (url: string) => void };
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

const historyGuards = new WeakMap<
  object,
  (event: NavigationEvent) => boolean
>();

// Gate BrowserRouter's own history callback: popstate cannot cancel traversal.
export const guardWeeklyPlanV3HistoryListener =
  (host: object, listener: (event: NavigationEvent) => void) =>
  (event: NavigationEvent) => {
    if (historyGuards.get(host)?.(event) !== false) listener(event);
  };

// Keep Declarative BrowserRouter and its native history implementation. Only
// its popstate subscription is wrapped; rejected POPs never update React state.
export const createWeeklyPlanV3NavigationWindow = (host: Window): Window => {
  const wrappers = new Map<EventListenerOrEventListenerObject, EventListener>();
  return new Proxy(host, {
    get(target, property) {
      if (property === "addEventListener")
        return (
          type: string,
          listener: EventListenerOrEventListenerObject,
          options?: boolean | AddEventListenerOptions,
        ) => {
          if (type !== "popstate")
            return target.addEventListener(type, listener, options);
          const wrapped = guardWeeklyPlanV3HistoryListener(host, (event) => {
            if (typeof listener === "function")
              listener.call(host, event as Event);
            else listener.handleEvent(event as Event);
          }) as EventListener;
          wrappers.set(listener, wrapped);
          target.addEventListener(type, wrapped, options);
        };
      if (property === "removeEventListener")
        return (
          type: string,
          listener: EventListenerOrEventListenerObject,
          options?: boolean | EventListenerOptions,
        ) => {
          const wrapped =
            type === "popstate" ? wrappers.get(listener) : undefined;
          target.removeEventListener(type, wrapped ?? listener, options);
          if (wrapped) wrappers.delete(listener);
        };
      const value = Reflect.get(target, property, target);
      return typeof value === "function" ? value.bind(target) : value;
    },
  });
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
  const currentIndex = historyIndex(host.history.state);
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
      new URL(target.href, currentHref).origin !==
        new URL(currentHref).origin ||
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
      return false;
    }
    if (host.confirm(warning)) return true;

    const nextIndex = historyIndex(event.state);
    if (
      currentIndex !== null &&
      nextIndex !== null &&
      currentIndex !== nextIndex
    ) {
      restoringHistory = true;
      host.history.go(currentIndex - nextIndex);
      return false;
    }

    // Unknown non-router history entries use document replacement and the
    // existing sessionStorage recovery, without adding a duplicate entry.
    host.location.replace?.(currentHref);
    return false;
  };

  host.addEventListener("click", onClick, true);
  historyGuards.set(host, onPopState);

  return () => {
    host.removeEventListener("click", onClick, true);
    if (historyGuards.get(host) === onPopState) historyGuards.delete(host);
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
