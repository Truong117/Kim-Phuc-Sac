import { useEffect } from "react";
import { useTranslation } from "react-i18next";

const guards = new Map<symbol, () => string>();
const beforeUnload = (event: BeforeUnloadEvent) => {
  event.preventDefault();
  event.returnValue = "";
};
const beforeNavigate = (event: MouseEvent) => {
  if (
    event.defaultPrevented ||
    event.button !== 0 ||
    event.metaKey ||
    event.ctrlKey ||
    event.shiftKey ||
    event.altKey
  )
    return;
  const anchor =
    event.target instanceof Element ? event.target.closest("a[href]") : null;
  if (
    !(anchor instanceof HTMLAnchorElement) ||
    anchor.target === "_blank" ||
    anchor.hasAttribute("download")
  )
    return;
  if (new URL(anchor.href).href === window.location.href) return;
  const message = guards.values().next().value?.();
  if (message && !window.confirm(message)) {
    event.preventDefault();
    event.stopPropagation();
  }
};

export default function useAccountUnsavedChanges(dirty: boolean) {
  const { t } = useTranslation();

  useEffect(() => {
    if (!dirty) return;

    const key = Symbol();
    guards.set(key, () => t("account.navigation.unsaved"));
    if (guards.size === 1) {
      window.addEventListener("beforeunload", beforeUnload);
      document.addEventListener("click", beforeNavigate, true);
    }
    return () => {
      guards.delete(key);
      if (guards.size === 0) {
        window.removeEventListener("beforeunload", beforeUnload);
        document.removeEventListener("click", beforeNavigate, true);
      }
    };
  }, [dirty, t]);
}
