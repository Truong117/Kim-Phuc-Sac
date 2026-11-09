import { useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link } from "react-router";
import PageMeta from "@/components/common/PageMeta";
import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import Button from "@/components/ui/button/Button";
import AnalyticsDataStatus from "@/components/social-analytics/AnalyticsDataStatus";
import AnalyticsOverview from "@/components/social-analytics/AnalyticsOverview";
import AnalyticsPostWorkspace from "@/components/social-analytics/AnalyticsPostWorkspace";
import AnalyticsActions from "@/components/social-analytics/AnalyticsActions";
import AnalyticsPostDetail from "@/components/social-analytics/AnalyticsPostDetail";
import { useAuth } from "@/hooks/useAuth";
import { useAuthorization } from "@/hooks/useAuthorization";
import { useFacebookAnalytics } from "@/hooks/useFacebookAnalytics";
import { useModal } from "@/hooks/useModal";
import { AiIcon, DocsIcon, GridIcon } from "@/icons";
import {
  analyzeFacebook,
  saveFacebookTopic,
  syncFacebookAnalytics,
} from "@/services/facebookAnalyticsService";
import type { FacebookTopic } from "@/types/facebookAnalytics";
import { addDaysToDateOnly, dateOnlyInTimeZone } from "@/utils/dateOnly";
import { observationDate } from "@/utils/facebookAnalytics";

export default function FacebookAnalytics() {
  const { currentUser } = useAuth();
  return <Workspace key={currentUser?.id ?? "guest"} />;
}
function Workspace() {
  const { t } = useTranslation("common", { keyPrefix: "facebookAnalytics" });
  const { canNavigate } = useAuthorization();
  const today = dateOnlyInTimeZone();
  const [pageId, setPageId] = useState<number | null>(() => {
    const n = Number(new URLSearchParams(window.location.search).get("page"));
    return Number.isInteger(n) && n > 0 ? n : null;
  });
  const [draft, setDraft] = useState(() => ({
    from: addDaysToDateOnly(today, -6),
    to: today,
  }));
  const [period, setPeriod] = useState(draft);
  const [tab, setTab] = useState("overview");
  const [postId, setPostId] = useState<number | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [message, setMessage] = useState<{
    key: string;
    text: string;
    error?: boolean;
    kind?: "sync" | "ai" | "topic";
  } | null>(null);
  const action = useRef<AbortController | null>(null);
  const api = useFacebookAnalytics(pageId, period.from, period.to);
  const modal = useModal();
  useEffect(() => () => action.current?.abort(), [api.key]);
  const data = api.data;
  const selectedPost = data?.posts.find((p) => p.id === postId);
  const waiting =
    data &&
    ["queued", "running"].includes(data.sync.status) &&
    !data.sync.interrupted;
  const actionBusy = busy === api.key;
  function openPost(id: number) {
    if (!data?.posts.some((p) => p.id === id)) {
      setMessage({ key: api.key, text: t("sourceUnavailable"), error: true });
      return;
    }
    setPostId(id);
    modal.openModal();
  }
  async function mutate(
    kind: "sync" | "ai" | "topic",
    topic?: FacebookTopic | null,
  ) {
    if (!api.selected) return;
    action.current?.abort();
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 45000);
    action.current = controller;
    const key = api.key,
      id = api.selected.id;
    setBusy(key);
    setMessage(null);
    try {
      if (kind === "sync") await syncFacebookAnalytics(id, controller.signal);
      else if (kind === "ai")
        await analyzeFacebook(id, period.from, period.to, controller.signal);
      else if (postId != null)
        await saveFacebookTopic(id, postId, topic ?? null, controller.signal);
      if (!controller.signal.aborted && api.current.current === key) {
        setMessage({
          key,
          kind,
          text: t(
            kind === "sync"
              ? "syncQueued"
              : kind === "ai"
                ? "aiQueued"
                : "topicSaved",
          ),
        });
        api.refresh();
      }
    } catch {
      if (api.current.current === key)
        setMessage({ key, text: t("actionError"), error: true });
    } finally {
      window.clearTimeout(timeout);
      if (api.current.current === key) setBusy(null);
    }
  }
  const tabs = [
    ["overview", GridIcon],
    ["content", DocsIcon],
    ["ai", AiIcon],
  ] as const;
  return (
    <>
      <PageMeta title={t("metaTitle")} description={t("subtitle")} />
      <div className="min-w-0 space-y-4 kps-ai-theme">
        <div>
          <p className="text-xs font-semibold tracking-widest text-kps-primary">
            {t("eyebrow")}
          </p>
          <PageBreadCrumb
            pageTitle={t("title")}
            headingLevel={1}
            className="mt-2 mb-2"
          />
          <p className="mt-2 text-sm text-gray-500 dark:text-gray-400">
            {t("subtitle")}
          </p>
        </div>
        {api.pagesState === "failed" ? (
          <div
            role="alert"
            className="rounded-xl border border-error-200 bg-error-50 p-5 text-sm text-error-700 dark:border-error-500/20 dark:bg-error-500/10 dark:text-error-400"
          >
            <p>{t("loadError")}</p>
            <Button
              variant="outline"
              onClick={api.reloadPages}
              className="mt-3"
            >
              {t("retry")}
            </Button>
          </div>
        ) : api.pagesState === "loading" ? (
          <p role="status" className="text-sm text-gray-500 dark:text-gray-400">
            {t("loading")}
          </p>
        ) : api.pages.length === 0 ? (
          <div className="rounded-xl border border-gray-200 bg-white p-8 text-sm text-gray-500 dark:border-gray-800 dark:bg-white/3 dark:text-gray-400">
            <p>{t("noPages")}</p>
            {canNavigate("integrations") && (
              <Link
                to="/integrations"
                className="mt-3 inline-block text-kps-primary underline dark:text-brand-300"
              >
                {t("manageConnections")}
              </Link>
            )}
          </div>
        ) : (
          <>
            <section className="min-w-0 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/3">
              <div className="grid min-w-0 items-end gap-4 sm:grid-cols-2 xl:grid-cols-12">
                <label className="block min-w-0 text-xs text-gray-500 sm:col-span-2 xl:col-span-5 dark:text-gray-400">
                  <span>{t("page")}</span>
                  <select
                    value={api.selected?.id ?? ""}
                    onChange={(e) => {
                      setPageId(Number(e.target.value));
                      modal.closeModal();
                    }}
                    className="mt-2 h-11 w-full min-w-0 rounded-lg border border-gray-200 bg-white px-3 text-sm font-medium text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100"
                  >
                    {api.pages.map((p) => (
                      <option key={p.id} value={p.id}>
                        {p.name}
                      </option>
                    ))}
                  </select>
                </label>
                <div className="min-w-0 space-y-2 xl:col-span-3">
                  <label
                    htmlFor="facebook-analytics-from"
                    className="block text-xs text-gray-500 dark:text-gray-400"
                  >
                    {t("from")}
                  </label>
                  <KpsDatePicker
                    id="facebook-analytics-from"
                    value={draft.from}
                    onChange={(from) => setDraft({ ...draft, from })}
                    ariaLabel={t("from")}
                    placeholder={t("from")}
                    dialogLabel={t("from")}
                    minDate={addDaysToDateOnly(draft.to, -89)}
                    maxDate={draft.to}
                  />
                </div>
                <div className="min-w-0 space-y-2 xl:col-span-3">
                  <label
                    htmlFor="facebook-analytics-to"
                    className="block text-xs text-gray-500 dark:text-gray-400"
                  >
                    {t("to")}
                  </label>
                  <KpsDatePicker
                    id="facebook-analytics-to"
                    value={draft.to}
                    onChange={(to) => setDraft({ ...draft, to })}
                    ariaLabel={t("to")}
                    placeholder={t("to")}
                    dialogLabel={t("to")}
                    minDate={draft.from}
                    maxDate={today}
                  />
                </div>
                <div className="flex items-end sm:col-span-2 xl:col-span-1">
                  <Button
                    size="sm"
                    className="h-11 w-full px-2 text-xs whitespace-nowrap"
                    variant="primary"
                    onClick={() => {
                      if (draft.to > addDaysToDateOnly(draft.from, 89)) {
                        setMessage({
                          key: api.key,
                          text: t("dateLimit"),
                          error: true,
                        });
                        return;
                      }
                      setPeriod(draft);
                    }}
                  >
                    {t("apply")}
                  </Button>
                </div>
              </div>
            </section>
            <div className="flex flex-wrap items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
              <span className="min-w-0 break-words">
                {t("owner", {
                  name: api.selected?.responsible_name ?? t("notAssigned"),
                })}
              </span>
              <div className="flex flex-wrap items-center gap-3">
                <Button
                  size="sm"
                  className="h-11 whitespace-nowrap"
                  variant="outline"
                  onClick={api.refresh}
                  disabled={api.loading}
                >
                  {t("reload")}
                </Button>
                <span>
                  {t("lastSync", {
                    at: observationDate(data?.sync.sampled_at ?? null),
                  })}
                </span>
                <Button
                  size="sm"
                  className="h-11 shrink-0 whitespace-nowrap"
                  onClick={() => void mutate("sync")}
                  disabled={
                    actionBusy ||
                    !!waiting ||
                    !data ||
                    data.connection_status !== "connected" ||
                    !data.principal_available
                  }
                >
                  {waiting ? t("syncPending") : t("sync")}
                </Button>
              </div>
            </div>
            {message?.key === api.key && (
              <p
                role={message.error ? "alert" : "status"}
                className={`rounded-lg p-3 text-sm ${message.error ? "bg-error-50 text-error-700 dark:bg-error-500/10 dark:text-error-400" : "bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-200"}`}
              >
                {message.kind === "sync" && data?.sync.error_code
                  ? t("syncFailed")
                  : message.kind === "sync" &&
                      data?.sync.progress?.phase === "completed"
                    ? t("syncComplete")
                    : message.text}
              </p>
            )}
            {api.error ? (
              <div
                role="alert"
                className="rounded-xl bg-error-50 p-4 text-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
              >
                {t("loadError")}{" "}
                <button
                  type="button"
                  onClick={api.reloadPages}
                  className="underline"
                >
                  {t("retry")}
                </button>
              </div>
            ) : !data ? (
              <p
                role="status"
                className="text-sm text-gray-500 dark:text-gray-400"
              >
                {t("loading")}
              </p>
            ) : (
              <>
                {(data.connection_status !== "connected" ||
                  !data.principal_available) && (
                  <p
                    role="status"
                    className="rounded-xl bg-warning-50 p-4 text-sm text-warning-800 dark:bg-warning-500/10 dark:text-warning-400"
                  >
                    {t("connectionNeeded")}{" "}
                    {canNavigate("integrations") && (
                      <Link to="/integrations" className="underline">
                        {t("manageConnections")}
                      </Link>
                    )}
                  </p>
                )}
                <AnalyticsDataStatus
                  key={api.key}
                  data={data}
                  waiting={!!waiting}
                />
                <div
                  role="tablist"
                  aria-label={t("title")}
                  className="grid grid-cols-3 gap-2 border-b border-gray-200 sm:flex sm:gap-4 dark:border-gray-800"
                >
                  {tabs.map(([key, Icon], index) => (
                    <button
                      key={key}
                      type="button"
                      role="tab"
                      id={`fb-tab-${key}`}
                      aria-selected={tab === key}
                      aria-controls="fb-panel"
                      tabIndex={tab === key ? 0 : -1}
                      onClick={() => setTab(key)}
                      onKeyDown={(e) => {
                        if (
                          ["ArrowLeft", "ArrowRight", "Home", "End"].includes(
                            e.key,
                          )
                        ) {
                          e.preventDefault();
                          const next =
                            e.key === "Home"
                              ? 0
                              : e.key === "End"
                                ? tabs.length - 1
                                : (index +
                                    (e.key === "ArrowRight" ? 1 : -1) +
                                    tabs.length) %
                                  tabs.length;
                          setTab(tabs[next][0]);
                          document
                            .getElementById(`fb-tab-${tabs[next][0]}`)
                            ?.focus();
                        }
                      }}
                      className={`flex min-w-0 items-center justify-center gap-2 border-b-2 px-1 py-3 text-center text-xs font-medium sm:justify-start sm:px-2 sm:text-sm ${tab === key ? "border-kps-primary text-kps-primary dark:text-brand-300" : "border-transparent text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-gray-200"}`}
                    >
                      <Icon className="hidden size-4 shrink-0 sm:block" />
                      {t(`tabs.${key}`)}
                    </button>
                  ))}
                </div>
                <div
                  role="tabpanel"
                  id="fb-panel"
                  aria-labelledby={`fb-tab-${tab}`}
                  aria-busy={api.loading}
                >
                  {tab === "overview" ? (
                    <AnalyticsOverview
                      data={data}
                      onPost={openPost}
                      onContent={() => setTab("content")}
                      onAi={() => setTab("ai")}
                    />
                  ) : tab === "content" ? (
                    <AnalyticsPostWorkspace
                      key={api.key}
                      data={data}
                      onPost={openPost}
                    />
                  ) : (
                    <AnalyticsActions
                      data={data}
                      onPost={openPost}
                      busy={actionBusy}
                      onGenerate={() => void mutate("ai")}
                    />
                  )}
                </div>
              </>
            )}
          </>
        )}
        {modal.isOpen && selectedPost && data && (
          <AnalyticsPostDetail
            post={selectedPost}
            data={data}
            onClose={modal.closeModal}
            onTopic={(topic) => void mutate("topic", topic)}
            busy={actionBusy}
          />
        )}
      </div>
    </>
  );
}
