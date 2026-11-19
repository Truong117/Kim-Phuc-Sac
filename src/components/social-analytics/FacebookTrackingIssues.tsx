import { useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import { ApiServiceError } from "@/services/apiClient";
import {
  getFacebookPostTracking,
  updateFacebookPostTracking,
} from "@/services/facebookAnalyticsService";
import FacebookTrackingAction from "./FacebookTrackingAction";
import {
  isFacebookTrackingStopReason,
  type FacebookPostTracking,
  type FacebookTrackingStopReason,
  type FacebookTrackedPost,
} from "@/types/facebookAnalytics";
import { observationDate } from "@/utils/facebookAnalytics";

export default function FacebookTrackingIssues({
  entryId,
  tracking,
  onRefresh,
}: {
  entryId: number;
  tracking: FacebookPostTracking;
  onRefresh: () => void;
}) {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  // A refreshed overview also refreshes the counts, permissions and first page.
  const [extra, setExtra] = useState<{
    first: FacebookPostTracking;
    data: FacebookPostTracking;
  } | null>(null);
  const list = extra?.first === tracking ? extra.data : tracking;
  const [busy, setBusy] = useState(false);
  const [feedback, setFeedback] = useState<{
    text: string;
    error?: boolean;
  } | null>(null);
  const [acknowledged, setAcknowledged] = useState<FacebookTrackedPost | null>(
    null,
  );
  const request = useRef<AbortController | null>(null);
  useEffect(() => () => request.current?.abort(), []);

  async function run(
    operation: (signal: AbortSignal) => Promise<void>,
    loadPage = false,
  ) {
    if (request.current) return;
    const controller = new AbortController();
    request.current = controller;
    setBusy(true);
    setFeedback(null);
    try {
      await operation(
        AbortSignal.any([controller.signal, AbortSignal.timeout(45000)]),
      );
    } catch (error) {
      if (controller.signal.aborted) return;
      const stale =
        error instanceof ApiServiceError &&
        [403, 404, 409].includes(error.status);
      setFeedback({
        text: t(
          stale
            ? "tracking.stale"
            : loadPage
              ? "tracking.loadError"
              : "tracking.error",
        ),
        error: true,
      });
      if (stale) onRefresh();
    } finally {
      request.current = null;
      if (!controller.signal.aborted) setBusy(false);
    }
  }
  function goToPage(page: number) {
    if (page === 1) {
      setExtra(null);
      return;
    }
    void run(async (signal) => {
      const response = await getFacebookPostTracking(entryId, page, signal);
      if (!signal.aborted) setExtra({ first: tracking, data: response.data });
    }, true);
  }
  function change(
    post: FacebookTrackedPost,
    reason?: FacebookTrackingStopReason,
  ) {
    if (request.current || !tracking.can_manage || !list.can_manage) return;
    const stop = post.status === "unavailable";
    void run(async (signal) => {
      await updateFacebookPostTracking(
        entryId,
        post.external_id,
        {
          entry_version: list.entry_version,
          connection_revision: list.connection_revision,
          expected_version: post.version,
          ...(stop ? { status: "stopped", reason } : { status: "active" }),
        },
        signal,
      );
      if (signal.aborted) return;
      setExtra(null);
      setAcknowledged(post);
      setFeedback({
        text: t(stop ? "tracking.stoppedSuccess" : "tracking.resumedSuccess", {
          id: post.external_id,
        }),
      });
      onRefresh();
    });
  }

  return (
    <>
      {list.unavailable_count + list.stopped_count > 0 && (
        <details className="rounded-lg border border-gray-200 p-3 text-sm text-gray-700 dark:border-gray-700 dark:text-gray-200">
          <summary className="cursor-pointer font-medium">
            {t("tracking.summary", {
              unavailable: list.unavailable_count,
              stopped: list.stopped_count,
            })}
          </summary>
          <ul
            aria-busy={busy}
            className="mt-2 max-h-72 divide-y divide-gray-100 overflow-y-auto text-xs dark:divide-gray-800"
          >
            {list.posts.map((post) => (
              <li
                key={post.external_id}
                className="flex flex-wrap items-center justify-between gap-2 py-2"
              >
                <div className="min-w-0 space-y-1">
                  <a
                    href={post.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-medium break-all text-kps-primary underline dark:text-brand-300"
                  >
                    {t("skippedPosts.open", { id: post.external_id })}
                  </a>
                  {post.status === "stopped" ? (
                    <>
                      <p>
                        {post.stopped_at
                          ? t("tracking.stoppedAt", {
                              at: observationDate(post.stopped_at),
                            })
                          : t("tracking.stopped")}
                      </p>
                      {isFacebookTrackingStopReason(post.reason) && (
                        <p>
                          {t("tracking.recordedReason", {
                            reason: t(`tracking.reasons.${post.reason}`),
                          })}
                        </p>
                      )}
                    </>
                  ) : (
                    <>
                      <p>
                        {post.failures > 0
                          ? t("tracking.failures", { count: post.failures })
                          : t("tracking.failure")}
                      </p>
                      <p>
                        {post.next_retry_at
                          ? t("tracking.retry", {
                              at: observationDate(post.next_retry_at),
                            })
                          : t("tracking.normalRetry")}
                      </p>
                    </>
                  )}
                </div>
                {tracking.can_manage && list.can_manage && (
                  <FacebookTrackingAction
                    key={`${entryId}:${list.entry_version}:${list.connection_revision}:${post.version}:${post.status}`}
                    post={post}
                    onChange={(reason) => change(post, reason)}
                    disabled={
                      busy ||
                      (acknowledged?.external_id === post.external_id &&
                        acknowledged.version === post.version &&
                        acknowledged.status === post.status)
                    }
                  />
                )}
              </li>
            ))}
          </ul>
          {list.last_page > 1 && (
            <div className="mt-2 flex flex-wrap items-center gap-3 text-xs">
              <Button
                size="sm"
                variant="outline"
                disabled={busy || list.page <= 1}
                onClick={() => goToPage(list.page - 1)}
              >
                {t("tracking.previous")}
              </Button>
              <span>
                {t("tracking.page", { page: list.page, last: list.last_page })}
              </span>
              <Button
                size="sm"
                variant="outline"
                disabled={busy || list.page >= list.last_page}
                onClick={() => goToPage(list.page + 1)}
              >
                {t("tracking.next")}
              </Button>
            </div>
          )}
        </details>
      )}
      {feedback && (
        <p
          role={feedback.error ? "alert" : "status"}
          className={
            feedback.error
              ? "text-error-600 dark:text-error-400"
              : "text-gray-700 dark:text-gray-300"
          }
        >
          {feedback.text}
        </p>
      )}
    </>
  );
}
