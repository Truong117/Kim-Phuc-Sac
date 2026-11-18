import { useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import { useWeeklyPlanV3NavigationSafety } from "@/utils/weeklyPlanV3NavigationSafety";
import { ApiServiceError } from "@/services/apiClient";

type FormError = { key: string; fields: string[] };

export default function useSpaLiveForm(dirty: boolean, onClose: () => void) {
  const { t } = useTranslation();
  const formRef = useRef<HTMLFormElement>(null);
  const submitting = useRef(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<FormError>();
  const warning = t("spa.flow.discard");
  useWeeklyPlanV3NavigationSafety(dirty || saving, warning);
  const clear = () => setError(undefined);
  const fail = (key: string, ...fields: string[]) => {
    setError({ key, fields });
    requestAnimationFrame(() => {
      const field = formRef.current?.querySelector<HTMLElement>(
        '[aria-invalid="true"]',
      );
      field?.scrollIntoView({ block: "center" });
      field?.focus({ preventScroll: true });
    });
  };
  const save = async <T>(
    write: () => Promise<T>,
    onSaved: (value: T) => void,
  ) => {
    if (submitting.current) return;
    submitting.current = true;
    clear();
    setSaving(true);
    try {
      onSaved(await write());
    } catch (cause) {
      if (cause instanceof ApiServiceError && cause.status === 403) {
        fail("accessDenied");
      } else if (cause instanceof ApiServiceError && cause.status === 409) {
        fail(
          [
            "SPA_APPOINTMENT_STALE",
            "SPA_APPOINTMENT_CANCELLED",
            "SPA_APPOINTMENT_REQUEST_CONFLICT",
            "SPA_CUSTOMER_STALE",
          ].includes(cause.code ?? cause.message)
            ? "stale"
            : "saveError",
        );
      } else if (cause instanceof ApiServiceError && cause.status === 422) {
        const fields = Object.keys(cause.validationErrors ?? {});
        if (fields.includes("start_time")) {
          fail("invalidOrOverlappingTime", ...fields);
        } else if (
          fields.includes("phone") ||
          fields.includes("customer.phone")
        ) {
          // Both forms validate and normalize these fields before saving.
          fail("phoneDuplicate", ...fields);
        } else {
          fail("validationError", ...fields);
        }
      } else {
        fail("saveError");
      }
    } finally {
      submitting.current = false;
      setSaving(false);
    }
  };
  const close = () => {
    if (!submitting.current && (!dirty || window.confirm(warning))) onClose();
  };
  return { formRef, error, saving, clear, fail, save, close };
}
