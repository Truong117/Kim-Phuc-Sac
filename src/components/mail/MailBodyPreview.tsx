import { useTranslation } from "react-i18next";

export default function MailBodyPreview({
  html,
  text,
}: {
  html: string;
  text: string;
}) {
  const { t } = useTranslation();
  return (
    <div className="min-w-0 space-y-3">
      <iframe
        title={t("mailCompose.formattedPreview")}
        srcDoc={html}
        sandbox=""
        referrerPolicy="no-referrer"
        className="h-[60vh] w-full rounded-lg border border-gray-200 bg-white dark:border-gray-700"
      />
      <details className="text-sm text-gray-600 dark:text-gray-300">
        <summary className="cursor-pointer">
          {t("mailCompose.textVersion")}
        </summary>
        <p className="mt-3 break-words whitespace-pre-wrap">{text}</p>
      </details>
    </div>
  );
}
