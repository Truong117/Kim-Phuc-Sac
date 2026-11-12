import { useTranslation } from "react-i18next";
import type { SpaPreviewError } from "../types";
export default function SpaFormError({ error }: { error?: SpaPreviewError }) {
  const { t } = useTranslation();
  return error ? (
    <p
      role="alert"
      className="rounded-lg border border-error-200 bg-error-50 p-3 text-sm text-error-700 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400"
    >
      {t(`spa.flow.errors.${error}`)}
    </p>
  ) : null;
}
