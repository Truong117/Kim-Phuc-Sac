import { isEvidenceUrl } from "@/utils/reportEvidence";
import ReportStatusBadge from "@/components/reports/ReportStatusBadge";
import type { ReportWorkItem } from "@/types/reports";
import { useTranslation } from "react-i18next";
import { useEffect } from "react";
import { useLocation } from "react-router";

interface ReportWorkItemDetailsProps {
  items: ReportWorkItem[];
}

export default function ReportWorkItemDetails({
  items,
}: ReportWorkItemDetailsProps) {
  const { hash } = useLocation();
  useEffect(() => {
    if (!/^#work-item-\d+$/.test(hash)) return;
    document.getElementById(hash.slice(1))?.scrollIntoView({ block: "center" });
  }, [hash, items]);
  const { t: pickerT } = useTranslation("common", {
    keyPrefix: "facebookPicker",
  });
  const { t } = useTranslation("common", {
    keyPrefix: "reportDetail.items",
  });
  const { t: taskT } = useTranslation("common", { keyPrefix: "taskProgress" });

  return (
    <div className="space-y-4">
      {items.map((item, index) => (
        <article
          key={item.id}
          id={`work-item-${item.id}`}
          className="scroll-mt-24 rounded-xl border border-gray-200 bg-gray-50/60 p-4 target:ring-2 target:ring-brand-500 sm:p-5 dark:border-gray-800 dark:bg-gray-900/50 dark:target:ring-brand-400"
        >
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <h3 className="font-semibold text-gray-900 dark:text-white">
              {t("itemTitle", { number: index + 1 })}
            </h3>
            <ReportStatusBadge status={item.status} />
          </div>
          {item.task_link && (
            <p className="mt-3 text-theme-xs text-kps-primary dark:text-sidebar-selected">
              {taskT("linked", { id: item.task_link.task_id })} ·{" "}
              {item.task_link.snapshot.title}
            </p>
          )}
          <dl className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div>
              <dt className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                {t("content")}
              </dt>
              <dd className="mt-1 text-theme-sm whitespace-pre-wrap text-gray-800 dark:text-white/90">
                {item.content}
              </dd>
            </div>
            {item.result.trim() && (
              <div>
                <dt className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                  {t("result")}
                </dt>
                <dd className="mt-1 text-theme-sm whitespace-pre-wrap text-gray-800 dark:text-white/90">
                  {item.result}
                </dd>
              </div>
            )}
            {item.note && (
              <div className="lg:col-span-2">
                <dt className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                  {t("note")}
                </dt>
                <dd className="mt-1 text-theme-sm whitespace-pre-wrap text-gray-700 dark:text-gray-300">
                  {item.note}
                </dd>
              </div>
            )}
            {item.issue?.trim() && (
              <div className="lg:col-span-2">
                <dt className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                  {t("issue")}
                </dt>
                <dd className="mt-1 text-theme-sm break-words whitespace-pre-wrap text-gray-700 dark:text-gray-300">
                  {item.issue}
                </dd>
              </div>
            )}
            {Boolean(item.evidence_links?.length) && (
              <div className="lg:col-span-2">
                <dt className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                  {t("evidence")}
                </dt>
                <dd className="mt-1 space-y-1 text-theme-sm">
                  {item.evidence_links?.map((url, index) => (
                    <div key={`${index}-${url}`}>
                      {isEvidenceUrl(url) ? (
                        <a
                          className="break-all text-brand-600 underline dark:text-brand-400"
                          href={url}
                          target="_blank"
                          rel="noopener noreferrer"
                        >
                          {url}
                        </a>
                      ) : (
                        <span className="break-all text-gray-700 dark:text-gray-300">
                          {url}
                        </span>
                      )}
                      {item.facebook_selections
                        ?.filter((row) => row.source_url === url)
                        .map((row) => (
                          <p
                            key={row.external_id}
                            className="mt-1 text-theme-xs text-success-700 dark:text-success-400"
                          >
                            {pickerT("confirmed", { page: row.page_name })}{" "}
                            <a
                              href={row.post_url}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="underline"
                            >
                              {pickerT("open")}
                            </a>
                          </p>
                        ))}
                    </div>
                  ))}
                  <p className="text-theme-xs text-gray-500 dark:text-gray-400">
                    {t("unverified")}
                  </p>
                </dd>
              </div>
            )}
          </dl>
        </article>
      ))}
    </div>
  );
}
