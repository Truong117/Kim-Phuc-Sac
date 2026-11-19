import { useTranslation } from "react-i18next";

export interface SkippedFacebookPost {
  external_id: string;
  url: string;
}

export default function SkippedFacebookPosts({
  posts = [],
  total = posts.length,
}: {
  posts?: SkippedFacebookPost[];
  total?: number;
}) {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  if (total === 0) return null;
  return (
    <details className="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-500/30 dark:bg-warning-500/10 dark:text-warning-400">
      <summary className="cursor-pointer font-medium">
        {t("skippedPosts.title", { count: total })}
      </summary>
      <p className="mt-2 text-xs">{t("skippedPosts.description")}</p>
      <ul className="mt-2 max-h-60 space-y-2 overflow-y-auto text-xs">
        {posts.map((post) => (
          <li key={post.external_id}>
            <a
              href={post.url}
              target="_blank"
              rel="noopener noreferrer"
              className="break-all underline"
            >
              {t("skippedPosts.open", { id: post.external_id })}
            </a>
          </li>
        ))}
      </ul>
      {total > posts.length && (
        <p className="mt-2 text-xs">
          {t("skippedPosts.limited", { count: posts.length })}
        </p>
      )}
    </details>
  );
}
