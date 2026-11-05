import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { apiRequest } from "@/services/apiClient";
import Button from "@/components/ui/button/Button";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
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
  const [days, setDays] = useState("7");
  const [reload, setReload] = useState(0);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState("");
  const [failed, setFailed] = useState(false);
  const [pageId, setPageId] = useState("");
  const [token, setToken] = useState("");
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
  async function action(kind: "connect" | "disconnect" | "sync") {
    if (busy) return;
    setBusy(true);
    setMessage("");
    try {
      await apiRequest(
        `/api/integrations/${entryId}/social/${kind === "sync" ? "sync" : "connect"}`,
        {
          method: kind === "disconnect" ? "DELETE" : "POST",
          ...(kind === "connect"
            ? {
                body: JSON.stringify({
                  page_id: pageId.trim(),
                  access_token: token.trim(),
                }),
              }
            : {}),
        },
      );
      setMessage(
        t(
          kind === "sync"
            ? "integrationsUi.social.queued"
            : "integrationsUi.social.saved",
        ),
      );
      refresh();
    } catch {
      setMessage(t("integrationsUi.social.actionError"));
    } finally {
      setToken("");
      setBusy(false);
    }
  }
  const count = (value: number | null, delta = false) =>
    value === null
      ? "—"
      : `${delta && value > 0 ? "+" : ""}${value.toLocaleString("vi-VN")}`;
  return (
    <section className="space-y-4 border-t border-gray-200 pt-5 dark:border-gray-700">
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
        <p role="status" className="text-sm text-gray-600 dark:text-gray-300">
          {message}
        </p>
      )}
      {data && (
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
              {data.connection.error_code && (
                <p className="text-error-600 dark:text-error-400">
                  {t("integrationsUi.social.syncError")} (
                  {data.connection.error_code})
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
          {canManage && data.enabled && (
            <form
              onSubmit={(e) => {
                e.preventDefault();
                void action("connect");
              }}
              className="space-y-3 rounded-xl border border-gray-200 p-3 dark:border-gray-700"
            >
              <fieldset disabled={busy} className="space-y-3">
                <p className="text-xs text-gray-500 dark:text-gray-400">
                  {t("integrationsUi.social.connectHint")}
                </p>
                <div>
                  <Label htmlFor="social-page-id">
                    {t("integrationsUi.social.pageId")}
                  </Label>
                  <Input
                    id="social-page-id"
                    required
                    pattern="[0-9]{1,32}"
                    value={pageId}
                    onChange={(e) => setPageId(e.target.value)}
                    autoComplete="off"
                  />
                </div>
                <div>
                  <Label htmlFor="social-token">
                    {t("integrationsUi.social.token")}
                  </Label>
                  <Input
                    id="social-token"
                    required
                    type="password"
                    minLength={16}
                    maxLength={4096}
                    value={token}
                    onChange={(e) => setToken(e.target.value)}
                    autoComplete="off"
                  />
                </div>
                <Button type="submit" variant="outline" size="sm">
                  {t("integrationsUi.social.connect")}
                </Button>
              </fieldset>
            </form>
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
                          className="whitespace-nowrap p-2 text-gray-700 dark:text-gray-200"
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
