import { useTranslation } from "react-i18next";

export default function SpaLiveFormError({ error }: { error?: string }) {
  const { t } = useTranslation();
  if (!error) return null;
  return (
    <p
      role="alert"
      className="rounded-lg border border-error-200 bg-error-50 p-3 text-sm text-error-700 dark:border-error-900 dark:bg-error-500/10 dark:text-error-400"
    >
      {t(`spa.live.${error}`)}
    </p>
  );
}
