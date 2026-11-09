import { createPortal } from "react-dom";
import { useId, useMemo } from "react";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";
import { Modal } from "@/components/ui/modal";
import Button from "@/components/ui/button/Button";
import AnalyticsTrend from "./AnalyticsTrend";
import type {
  FacebookAnalyticsData,
  FacebookAnalyticsPost,
  FacebookTopic,
} from "@/types/facebookAnalytics";
import {
  analyticsNumber,
  analyticsSeries,
  facebookTopics,
  observationDate,
  postMilestoneAt,
} from "@/utils/facebookAnalytics";
interface Props {
  post: FacebookAnalyticsPost;
  data: FacebookAnalyticsData;
  onClose: () => void;
  onTopic: (topic: FacebookTopic | null) => void;
  busy: boolean;
}
export default function AnalyticsPostDetail({
  post,
  data,
  onClose,
  onTopic,
  busy,
}: Props) {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  const title = useId();
  const points = useMemo(
    () => analyticsSeries([post], data.from_date, data.to_date),
    [post, data.from_date, data.to_date],
  );
  return createPortal(
    <Modal
      isOpen
      onClose={onClose}
      ariaLabelledBy={title}
      showCloseButton={false}
      aboveNavigation
      className="mx-3 max-w-2xl p-5 kps-ai-theme sm:p-7"
    >
      <div className="custom-scrollbar max-h-[85dvh] space-y-5 overflow-y-auto pe-2">
        <div className="flex items-center justify-between gap-3">
          <h2
            id={title}
            className="text-lg font-semibold text-gray-900 dark:text-white"
          >
            {t("postDetail")}
          </h2>
          <Button variant="outline" size="sm" onClick={onClose}>
            {t("close")}
          </Button>
        </div>
        {post.picture && (
          <img
            src={post.picture}
            alt=""
            referrerPolicy="no-referrer"
            className="max-h-72 w-full rounded-xl bg-gray-50 object-contain dark:bg-gray-800"
          />
        )}
        <div>
          <p className="text-xs text-gray-500 dark:text-gray-400">
            {t(`types.${post.type}`)} · {observationDate(post.published_at)}
          </p>
          <p className="mt-2 text-sm break-words whitespace-pre-line text-gray-800 dark:text-gray-100">
            {post.message || t("untitled")}
          </p>
          <a
            href={post.url}
            target="_blank"
            rel="noopener noreferrer"
            className="mt-3 inline-block text-sm text-kps-primary underline dark:text-brand-300"
          >
            {t("openOriginal")}
          </a>
        </div>
        <label className="block space-y-2 text-sm text-gray-600 dark:text-gray-300">
          <span>{t("topic")}</span>
          <select
            value={post.topic ?? ""}
            onChange={(e) =>
              onTopic((e.target.value || null) as FacebookTopic | null)
            }
            disabled={busy}
            className="w-full rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900"
          >
            <option value="">{t("topics.none")}</option>
            {facebookTopics.map((k) => (
              <option key={k} value={k}>
                {t(`topics.${k}`)}
              </option>
            ))}
          </select>
        </label>
        <div className="grid grid-cols-3 gap-3">
          {(["likes", "comments", "media_views"] as const).map((k) => (
            <div key={k} className="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
              <p className="text-xs text-gray-400">{t(`metrics.${k}`)}</p>
              <p className="mt-1 text-xl font-semibold text-gray-800 dark:text-white">
                {analyticsNumber(post.current[k])}
              </p>
              <p className="text-xs text-kps-primary dark:text-brand-300">
                {t("delta", { value: analyticsNumber(post.observed_delta[k]) })}
              </p>
            </div>
          ))}
        </div>
        <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
          {t("observationWindow", {
            count: post.sample_count,
            from: observationDate(post.observed_from),
            to: observationDate(post.observed_to),
          })}
        </p>
        <section className="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-gray-800">
          <h3 className="text-sm font-medium text-gray-800 dark:text-gray-100">
            {t("review.milestoneDetail")}
          </h3>
          <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
            {t("review.milestoneDetailNote")}
          </p>
          <div className="grid grid-cols-3 gap-2">
            {([1, 3, 7] as const).map((days) => (
              <div
                key={days}
                className="min-w-0 rounded-lg bg-gray-50 p-2 dark:bg-gray-800"
              >
                <span className="text-xs font-semibold text-kps-primary dark:text-brand-300">
                  D{days}
                </span>
                <p className="mt-1 text-xs break-words text-gray-500 dark:text-gray-400">
                  {observationDate(postMilestoneAt(post.published_at, days))}
                </p>
                <p className="mt-1 text-lg text-gray-400">—</p>
              </div>
            ))}
          </div>
        </section>
        <AnalyticsTrend metric="likes" points={points} />
        <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
          {t("trendPolicy")}
        </p>
        <h3 className="font-medium text-gray-800 dark:text-gray-100">
          {t("reports")}
        </h3>
        <div className="flex flex-wrap gap-3">
          {post.report_ids.length ? (
            post.report_ids.map((id) => (
              <Link
                key={id}
                to={`/reports/${id}`}
                className="text-sm text-kps-primary underline dark:text-brand-300"
              >
                {t("reportNumber", { id })}
              </Link>
            ))
          ) : (
            <p className="text-sm text-gray-500 dark:text-gray-400">
              {t("notLinked")}
            </p>
          )}
        </div>
        <p className="rounded-xl bg-warning-50 p-3 text-xs leading-relaxed text-warning-800 dark:bg-warning-500/10 dark:text-warning-400">
          {t("noAttribution")}
        </p>
      </div>
    </Modal>,
    document.body,
  );
}
