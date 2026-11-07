import { Link } from "react-router";
import { useAuthorization } from "@/hooks/useAuthorization";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { apiRequest, ApiServiceError } from "@/services/apiClient";
import Button from "@/components/ui/button/Button";
import SocialConnectionForm, {
  type SocialCredentials,
} from "./SocialConnectionForm";

const socialErrorKeys: Readonly<Record<string, string>> = {
  SOCIAL_RECONNECT_REQUIRED: "integrationsUi.social.errors.reconnectRequired",
  SOCIAL_RATE_LIMITED: "integrationsUi.social.errors.rateLimited",
  SOCIAL_NOT_CONFIGURED: "integrationsUi.social.errors.notConfigured",
  SOCIAL_PERMISSION_OR_OBJECT_UNAVAILABLE:
    "integrationsUi.social.errors.permissionOrObject",
  SOCIAL_PROVIDER_UNAVAILABLE:
    "integrationsUi.social.errors.providerUnavailable",
  SOCIAL_PRINCIPAL_UNAVAILABLE:
    "integrationsUi.social.errors.principalUnavailable",
};

function actionErrorKey(error: unknown): string {
  if (error instanceof ApiServiceError) {
    if (error.status === 429) return "integrationsUi.social.errors.rateLimited";
    if (error.validationErrors?.page_id)
      return "integrationsUi.social.errors.invalidPageId";
    if (error.validationErrors?.access_token)
      return "integrationsUi.social.errors.invalidToken";
    if (error.code && socialErrorKeys[error.code])
      return socialErrorKeys[error.code];
    if (error.status === 409) return "integrationsUi.social.errors.conflict";
  }
  return "integrationsUi.social.actionError";
}
interface Metrics {
  likes: number | null;
  comments: number | null;
  media_views: number | null;
}
interface Post {
  post_id: number;
  url: string;
  report_ids: number[];
  sample_count: number;
  observed_from: string | null;
  observed_to: string | null;
  current: Metrics;
  observed_delta: Metrics;
  coverage: string;
}
interface SocialData {
  enabled: boolean;
  connection: null | {
    page_id: string;
    page_name: string;
    status: string;
    error_code: string | null;
    synced_at: string | null;
    sync_counts: null | {
      links: number;
      matched_links: number;
      sampled: number;
      deferred: number;
      unresolved_share_links?: number;
      pending_share_links?: number;
      pending_post_aliases?: number;
      unverified_post_aliases?: number;
    };
  };
  posts: Post[];
}
export default function SocialMetricsPanel({
  entryId,
  canManage,
}: {
  entryId: string;
  canManage: boolean;
}) {
  const { t } = useTranslation();
  const [data, setData] = useState<SocialData | null>(null);
  const { canNavigate } = useAuthorization();
  const [days, setDays] = useState("7");
  const [reload, setReload] = useState(0);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [failed, setFailed] = useState(false);
  const [showCredentials, setShowCredentials] = useState(false);
  const [actionFailed, setActionFailed] = useState(false);
  useEffect(() => {
    const controller = new AbortController();
    apiRequest<{ data: SocialData }>(
      `/api/integrations/${entryId}/social?days=${days}`,
      { signal: controller.signal },
    )
      .then((result) => {
        if (!controller.signal.aborted) {
          setData(result.data);
          setFailed(false);
        }
      })
      .catch(() => {
        if (!controller.signal.aborted) {
          setFailed(true);
          setData(null);
        }
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [entryId, days, reload]);
  function refresh() {
    setLoading(true);
    setReload((value) => value + 1);
  }
  async function action(
    kind: "connect" | "disconnect" | "sync",
    credentials?: SocialCredentials,
  ) {
    if (busy || (kind === "connect" && !credentials)) return;
    setBusy(true);
    setMessage("");
    setActionFailed(false);
    try {
      await apiRequest(
        `/api/integrations/${entryId}/social/${kind === "sync" ? "sync" : "connect"}`,
        {
          method: kind === "disconnect" ? "DELETE" : "POST",
          ...(kind === "connect" && credentials
            ? {
                body: JSON.stringify({
                  page_id: credentials.pageId,
                  access_token: credentials.accessToken,
                }),
              }
            : {}),
        },
      );
      setMessage(
        t(
          kind === "sync"
            ? "integrationsUi.social.queued"
            : kind === "disconnect"
              ? "integrationsUi.social.disconnectedNotice"
              : "integrationsUi.social.saved",
        ),
      );
      if (kind !== "sync") setShowCredentials(false);
      refresh();
    } catch (error) {
      setActionFailed(true);
      setMessage(t(actionErrorKey(error)));
    } finally {
      setBusy(false);
    }
  }
  const count = (value: number | null, delta = false) =>
    value === null
      ? "—"
      : `${delta && value > 0 ? "+" : ""}${value.toLocaleString("vi-VN")}`;
  return (
    <section className="space-y-4 border-t border-gray-200 pt-5 dark:border-gray-700">
      {canNavigate("social.analytics") && (
        <Link
          to={`/social-analytics/facebook?page=${entryId}`}
          className="inline-block text-sm text-kps-primary underline dark:text-brand-300"
        >
          {t("facebookAnalytics.openDashboard")}
        </Link>
      )}
      <h3 className="font-semibold text-gray-800 dark:text-gray-100">
        {t("integrationsUi.social.title")}
      </h3>
      <p className="text-xs leading-relaxed text-gray-500 dark:text-gray-400">
        {t("integrationsUi.social.notice")}
      </p>
      <div className="flex flex-wrap items-center gap-2">
        <select
          aria-label={t("integrationsUi.social.period")}
          value={days}
          onChange={(e) => {
            setDays(e.target.value);
            setLoading(true);
          }}
          className="rounded-lg border border-gray-300 bg-white p-2 text-sm text-gray-700 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200"
        >
          {[1, 7, 30].map((value) => (
            <option key={value} value={value}>
              {t("integrationsUi.social.days", { count: value })}
            </option>
          ))}
        </select>
        <Button
          variant="outline"
          size="sm"
          disabled={loading || busy}
          onClick={refresh}
        >
          {t("integrationsUi.reload")}
        </Button>
      </div>
      {loading && (
        <p role="status" className="text-sm text-gray-500 dark:text-gray-400">
          {t("integrationsUi.loading")}
        </p>
      )}
      {failed && (
        <p role="alert" className="text-sm text-error-600 dark:text-error-400">
          {t("integrationsUi.social.loadError")}
        </p>
      )}
      {message && (
        <p
          role={actionFailed ? "alert" : "status"}
          className={
            actionFailed
              ? "text-sm text-error-600 dark:text-error-400"
              : "text-sm text-gray-600 dark:text-gray-300"
          }
        >
          {message}
        </p>
      )}
      {data && !loading && (
        <>
          {!data.enabled && (
            <p className="text-sm text-warning-700 dark:text-warning-400">
              {t("integrationsUi.social.disabled")}
            </p>
          )}
          {data.connection && (
            <div className="space-y-2 rounded-xl bg-gray-50 p-3 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-200">
              <p>
                {data.connection.page_name} ·{" "}
                {t(`integrationsUi.social.status.${data.connection.status}`)}
              </p>
              <p>
                {t("integrationsUi.social.lastSync")}:{" "}
                {data.connection.synced_at
                  ? new Date(data.connection.synced_at).toLocaleString("vi-VN")
                  : "—"}
              </p>
              {data.connection.error_code &&
                data.connection.status !== "disconnected" && (
                  <p
                    role="alert"
                    className="text-error-600 dark:text-error-400"
                  >
                    {t(
                      socialErrorKeys[data.connection.error_code] ??
                        "integrationsUi.social.syncError",
                    )}{" "}
                    ({data.connection.error_code})
                  </p>
                )}
              {data.connection.sync_counts && (
                <p>
                  {t(
                    "integrationsUi.social.coverage",
                    data.connection.sync_counts,
                  )}
                </p>
              )}
              {Boolean(
                (data.connection.sync_counts?.unresolved_share_links ?? 0) +
                (data.connection.sync_counts?.pending_share_links ?? 0),
              ) && (
                <p className="text-warning-700 dark:text-warning-400">
                  {t("integrationsUi.social.shareWarnings", {
                    unresolved:
                      data.connection.sync_counts?.unresolved_share_links ?? 0,
                    pending:
                      data.connection.sync_counts?.pending_share_links ?? 0,
                  })}
                </p>
              )}
              {Boolean(
                (data.connection.sync_counts?.pending_post_aliases ?? 0) +
                (data.connection.sync_counts?.unverified_post_aliases ?? 0),
              ) && (
                <p className="text-warning-700 dark:text-warning-400">
                  {t("integrationsUi.social.postAliasWarnings", {
                    pending:
                      data.connection.sync_counts?.pending_post_aliases ?? 0,
                    unverified:
                      data.connection.sync_counts?.unverified_post_aliases ?? 0,
                  })}
                </p>
              )}
            </div>
          )}
          {canManage && (
            <div className="flex flex-wrap gap-2">
              <Button
                variant="outline"
                size="sm"
                disabled={
                  busy ||
                  !data.enabled ||
                  data.connection?.status !== "connected"
                }
                onClick={() => void action("sync")}
              >
                {t("integrationsUi.social.sync")}
              </Button>
              {data.connection?.status !== "disconnected" &&
                data.connection && (
                  <Button
                    variant="outline"
                    size="sm"
                    disabled={busy}
                    onClick={() => void action("disconnect")}
                  >
                    {t("integrationsUi.social.disconnect")}
                  </Button>
                )}
            </div>
          )}
          {canManage && data.enabled && !showCredentials && (
            <Button
              variant="outline"
              size="sm"
              disabled={busy}
              onClick={() => setShowCredentials(true)}
            >
              {t("integrationsUi.social.configureConnection")}
            </Button>
          )}
          {canManage && data.enabled && showCredentials && (
            <SocialConnectionForm
              key={entryId + ":" + (data.connection?.page_id ?? "")}
              initialPageId={data.connection?.page_id ?? ""}
              busy={busy}
              onConnect={(credentials) => action("connect", credentials)}
              onClose={() => setShowCredentials(false)}
            />
          )}
          <div className="overflow-x-auto">
            <table className="w-full text-start text-xs">
              <thead>
                <tr>
                  {["post", "likes", "comments", "mediaViews"].map((key) => (
                    <th
                      key={key}
                      className="p-2 text-start text-gray-600 dark:text-gray-300"
                    >
                      {t(`integrationsUi.social.${key}`)}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {data.posts.map((post, index) => (
                  <tr
                    key={post.post_id}
                    className="border-t border-gray-100 dark:border-gray-800"
                  >
                    <td className="min-w-40 p-2 text-gray-600 dark:text-gray-300">
                      <a
                        className="text-kps-primary underline"
                        href={post.url}
                        target="_blank"
                        rel="noopener noreferrer"
                      >
                        {t("integrationsUi.social.postNumber", {
                          index: index + 1,
                        })}
                      </a>
                      <p className="mt-1">
                        {post.observed_from && post.observed_to
                          ? `${new Date(post.observed_from.replace(" ", "T") + (post.observed_from.includes("Z") ? "" : "Z")).toLocaleString("vi-VN")} → ${new Date(post.observed_to.replace(" ", "T") + (post.observed_to.includes("Z") ? "" : "Z")).toLocaleString("vi-VN")}`
                          : t("integrationsUi.social.noSamples")}
                      </p>
                      {post.report_ids.map((id, i) => (
                        <a
                          key={id}
                          className="me-2 mt-1 inline-block text-kps-primary underline"
                          href={`/reports/${id}`}
                        >
                          {t("integrationsUi.social.reportNumber", {
                            index: i + 1,
                          })}
                        </a>
                      ))}
                    </td>
                    {(["likes", "comments", "media_views"] as const).map(
                      (key) => (
                        <td
                          key={key}
                          className="p-2 whitespace-nowrap text-gray-700 dark:text-gray-200"
                        >
                          <p>{count(post.current[key])}</p>
                          <p className="mt-1 text-gray-500 dark:text-gray-400">
                            Δ {count(post.observed_delta[key], true)}
                          </p>
                        </td>
                      ),
                    )}
                  </tr>
                ))}
                {data.posts.length === 0 && (
                  <tr>
                    <td
                      colSpan={4}
                      className="p-4 text-center text-gray-500 dark:text-gray-400"
                    >
                      {t("integrationsUi.social.empty")}
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </>
      )}
    </section>
  );
}
