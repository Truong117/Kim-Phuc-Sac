import { useCallback, useEffect, useId, useRef, useState } from "react";
import { createPortal } from "react-dom";
import { useTranslation } from "react-i18next";
import { Modal } from "@/components/ui/modal";
import Button from "@/components/ui/button/Button";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import { ApiServiceError, apiRequest } from "@/services/apiClient";
import type { FacebookPostCandidate, FacebookSelection } from "@/types/reports";

type Batch = {
  data: FacebookPostCandidate[];
  pages: { id: number; name: string }[];
  entry_id: number | null;
  next_cursor: string | null;
};
type Props = {
  sourceUrl?: string;
  reportDate?: string;
  existingUrls: string[];
  onClose: () => void;
  onConfirm: (posts: FacebookSelection[]) => void;
};
const inputClass =
  "h-11 w-full rounded-lg border border-gray-300 bg-white px-3 text-theme-sm text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200";
const dateText = (value: string) =>
  new Intl.DateTimeFormat("vi-VN", {
    dateStyle: "short",
    timeStyle: "short",
    timeZone: "Asia/Ho_Chi_Minh",
  }).format(new Date(value));

export default function FacebookPostPicker({
  sourceUrl,
  reportDate,
  existingUrls,
  onClose,
  onConfirm,
}: Props) {
  const { t } = useTranslation("common", { keyPrefix: "facebookPicker" });
  const titleId = useId();
  const end =
    reportDate ??
    new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Ho_Chi_Minh" }).format(
      new Date(),
    );
  const startDate = new Date(`${end}T00:00:00Z`);
  startDate.setUTCDate(startDate.getUTCDate() - 6);
  const [draft, setDraft] = useState({
    from: startDate.toISOString().slice(0, 10),
    to: end,
    entry: "",
  });
  const [filter, setFilter] = useState(draft);
  const [keyword, setKeyword] = useState("");
  const [kind, setKind] = useState("all");
  const [batch, setBatch] = useState<Batch>({
    data: [],
    pages: [],
    entry_id: null,
    next_cursor: null,
  });
  const [selected, setSelected] = useState<FacebookPostCandidate[]>([]);
  const [preview, setPreview] = useState<FacebookPostCandidate | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const controller = useRef<AbortController | null>(null);
  const previewRef = useRef<HTMLElement>(null);
  const max = sourceUrl ? 1 : Math.max(0, 10 - existingUrls.length);

  const load = useCallback(
    async (cursor?: string) => {
      controller.current?.abort();
      const request = new AbortController();
      controller.current = request;
      setLoading(true);
      setError("");
      const timer = window.setTimeout(() => request.abort(), 15_000);
      try {
        const query = new URLSearchParams({
          from_date: filter.from,
          to_date: filter.to,
        });
        if (filter.entry) query.set("entry_id", filter.entry);
        if (sourceUrl) query.set("source_url", sourceUrl);
        if (cursor) query.set("cursor", cursor);
        const result = await apiRequest<Batch>(
          `/api/reports/facebook-posts?${query}`,
          { signal: request.signal },
        );
        if (request.signal.aborted) return;
        setBatch((current) => ({
          ...result,
          pages: result.pages ?? [],
          data: [
            ...new Map(
              [...(cursor ? current.data : []), ...(result.data ?? [])].map(
                (post) => [post.external_id, post],
              ),
            ).values(),
          ].sort((a, b) => b.created_time.localeCompare(a.created_time)),
        }));
      } catch (cause) {
        if (controller.current !== request) return;
        const code = cause instanceof ApiServiceError ? cause.code : undefined;
        setError(
          code === "SOCIAL_RECONNECT_REQUIRED"
            ? "expired"
            : cause instanceof ApiServiceError && cause.status === 429
              ? "rateLimited"
              : "loadFailed",
        );
      } finally {
        window.clearTimeout(timer);
        if (controller.current === request) setLoading(false);
      }
    },
    [filter, sourceUrl],
  );

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void load();
    }, 0);
    return () => {
      window.clearTimeout(timer);
      const current = controller.current;
      controller.current = null;
      current?.abort();
    };
  }, [load]);

  const visible = batch.data.filter(
    (post) =>
      (kind === "all" ||
        (kind === "video"
          ? ["video", "reel"].includes(post.type)
          : post.type === kind)) &&
      `${post.message} ${post.post_url}`
        .toLocaleLowerCase("vi")
        .includes(keyword.trim().toLocaleLowerCase("vi")),
  );
  const name = (post: FacebookPostCandidate) =>
    post.message.trim() || t(`types.${post.type}`);
  const toggle = (post: FacebookPostCandidate) =>
    setSelected((current) =>
      current.some((row) => row.external_id === post.external_id)
        ? current.filter((row) => row.external_id !== post.external_id)
        : sourceUrl
          ? [post]
          : current.length < max
            ? [...current, post]
            : current,
    );

  return createPortal(
    <Modal
      aboveNavigation
      isOpen
      onClose={onClose}
      ariaLabelledBy={titleId}
      showCloseButton={false}
      className="mx-3 max-w-5xl p-4 sm:p-6"
    >
      <div className="flex max-h-[85dvh] flex-col text-gray-800 dark:text-gray-200">
        <h2 id={titleId} className="text-lg font-semibold">
          {t("title")}
        </h2>
        <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
          {t(sourceUrl ? "matchHint" : "addHint")}
        </p>
        {sourceUrl && (
          <p className="mt-1 line-clamp-2 break-all text-theme-xs">
            {sourceUrl}
          </p>
        )}
        <div className="mt-4 min-h-0 flex-1 overflow-y-auto pe-1 custom-scrollbar">
          <div className="grid gap-3 sm:grid-cols-3">
            <div>
              <Label htmlFor={`${titleId}-page`}>{t("page")}</Label>
              <select
                id={`${titleId}-page`}
                className={inputClass}
                value={draft.entry || batch.entry_id || ""}
                onChange={(event) =>
                  setDraft({ ...draft, entry: event.target.value })
                }
              >
                {batch.pages.map((page) => (
                  <option key={page.id} value={page.id}>
                    {page.name}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <Label htmlFor={`${titleId}-from`}>{t("from")}</Label>
              <Input
                id={`${titleId}-from`}
                type="date"
                value={draft.from}
                onChange={(event) =>
                  setDraft({ ...draft, from: event.target.value })
                }
              />
            </div>
            <div>
              <Label htmlFor={`${titleId}-to`}>{t("to")}</Label>
              <Input
                id={`${titleId}-to`}
                type="date"
                value={draft.to}
                onChange={(event) =>
                  setDraft({ ...draft, to: event.target.value })
                }
              />
            </div>
          </div>
          <div className="my-3 flex flex-wrap gap-2">
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={
                !draft.from || !draft.to || draft.to < draft.from || loading
              }
              onClick={() => {
                setBatch((current) => ({
                  ...current,
                  data: [],
                  next_cursor: null,
                }));
                setSelected([]);
                setPreview(null);
                setFilter({ ...draft });
              }}
            >
              {t("apply")}
            </Button>
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={loading}
              onClick={() => {
                setSelected([]);
                setPreview(null);
                void load();
              }}
            >
              {t("reload")}
            </Button>
          </div>
          <div className="mb-3 grid gap-3 sm:grid-cols-2">
            <div>
              <Label htmlFor={`${titleId}-search`}>{t("search")}</Label>
              <Input
                id={`${titleId}-search`}
                value={keyword}
                onChange={(event) => setKeyword(event.target.value)}
                placeholder={t("searchHint")}
              />
            </div>
            <div>
              <Label htmlFor={`${titleId}-kind`}>{t("kind")}</Label>
              <select
                id={`${titleId}-kind`}
                value={kind}
                className={inputClass}
                onChange={(event) => setKind(event.target.value)}
              >
                {["all", "post", "video", "reel"].map((value) => (
                  <option key={value} value={value}>
                    {t(`types.${value}`)}
                  </option>
                ))}
              </select>
            </div>
          </div>
          <p className="mb-3 text-theme-xs text-gray-500 dark:text-gray-400">
            {t("loadedHint", { count: batch.data.length })}
          </p>
          {error && (
            <p role="alert" className="mb-3 text-error-600 dark:text-error-400">
              {t(error)}
            </p>
          )}
          <div className="grid gap-4 lg:grid-cols-2">
            <div>
              <ul
                className="divide-y divide-gray-200 dark:divide-gray-700"
                aria-label={t("list")}
              >
                {visible.map((post) => {
                  const checked = selected.some(
                    (row) => row.external_id === post.external_id,
                  );
                  const exists =
                    !sourceUrl && existingUrls.includes(post.source_url);
                  return (
                    <li
                      key={post.external_id}
                      className={`flex items-center gap-3 rounded-lg p-3 ${checked ? "bg-brand-50 dark:bg-brand-950" : "bg-gray-50 dark:bg-gray-800"}`}
                    >
                      {post.picture && (
                        <img
                          src={post.picture}
                          alt=""
                          loading="lazy"
                          referrerPolicy="no-referrer"
                          className="size-12 shrink-0 rounded-lg object-cover"
                          onError={(event) => {
                            event.currentTarget.hidden = true;
                          }}
                        />
                      )}
                      <div className="min-w-0 flex-1">
                        <button
                          type="button"
                          aria-label={t("previewNamed", {
                            name: name(post).slice(0, 100),
                          })}
                          className="line-clamp-2 text-start text-theme-sm font-medium underline focus-visible:outline-2 focus-visible:outline-brand-500"
                          onMouseEnter={() => setPreview(post)}
                          onFocus={() => setPreview(post)}
                          onClick={() => {
                            setPreview(post);
                            if (
                              window.matchMedia("(max-width: 1023px)").matches
                            )
                              requestAnimationFrame(() =>
                                previewRef.current?.scrollIntoView({
                                  block: "nearest",
                                  behavior: "smooth",
                                }),
                              );
                          }}
                        >
                          {name(post)}
                        </button>
                        <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
                          {dateText(post.created_time)} ·{" "}
                          {t(`types.${post.type}`)}
                        </p>
                        {exists && (
                          <p className="text-theme-xs">{t("alreadyAdded")}</p>
                        )}
                      </div>
                      <input
                        type="checkbox"
                        className="size-5 shrink-0 accent-brand-600"
                        aria-label={t("selectNamed", {
                          name: name(post).slice(0, 100),
                        })}
                        checked={checked}
                        disabled={
                          loading ||
                          exists ||
                          (!sourceUrl && !checked && selected.length >= max)
                        }
                        onChange={() => toggle(post)}
                      />
                    </li>
                  );
                })}
              </ul>
              {!loading && !visible.length && (
                <p className="py-6 text-theme-sm">
                  {t(batch.pages.length ? "empty" : "noPages")}
                </p>
              )}
              {loading && (
                <p role="status" className="py-3">
                  {t("loading")}
                </p>
              )}
              {batch.next_cursor && (
                <Button
                  type="button"
                  className="mt-3"
                  size="sm"
                  variant="outline"
                  disabled={loading}
                  onClick={() => void load(batch.next_cursor!)}
                >
                  {t("more")}
                </Button>
              )}
            </div>
            <section
              ref={previewRef}
              aria-label={t("preview")}
              className="h-fit rounded-xl border border-gray-200 bg-white p-4 lg:sticky lg:top-0 dark:border-gray-700 dark:bg-gray-900"
            >
              {preview ? (
                <>
                  <p className="font-semibold">{preview.page_name}</p>
                  <p className="my-2 text-theme-xs">
                    {dateText(preview.created_time)} ·{" "}
                    {t(`types.${preview.type}`)}
                  </p>
                  {preview.picture && (
                    <img
                      key={preview.picture}
                      src={preview.picture}
                      alt={t("previewImage")}
                      referrerPolicy="no-referrer"
                      className="mb-3 max-h-64 w-full rounded-lg object-contain"
                      onError={(event) => {
                        event.currentTarget.hidden = true;
                      }}
                    />
                  )}
                  <p className="break-words whitespace-pre-wrap text-theme-sm">
                    {preview.message || t("noText")}
                  </p>
                  <a
                    href={preview.post_url}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="mt-3 inline-block text-brand-600 underline dark:text-brand-400"
                  >
                    {t("open")}
                  </a>
                  <p className="mt-3 text-theme-xs text-gray-500 dark:text-gray-400">
                    {t("previewHint")}
                  </p>
                </>
              ) : (
                <p className="text-theme-sm text-gray-500 dark:text-gray-400">
                  {t("previewEmpty")}
                </p>
              )}
            </section>
          </div>
        </div>
        <div className="mt-4 flex flex-wrap justify-end gap-3 border-t border-gray-200 pt-4 dark:border-gray-700">
          <span
            className="me-auto self-center text-theme-sm"
            aria-live="polite"
          >
            {t("selected", { count: selected.length, max })}
          </span>
          <Button type="button" size="sm" variant="outline" onClick={onClose}>
            {t("cancel")}
          </Button>
          <Button
            type="button"
            size="sm"
            disabled={!selected.length || loading || Boolean(error)}
            onClick={() => {
              onConfirm(selected);
            }}
          >
            {t(sourceUrl ? "confirmMatch" : "confirm", {
              count: selected.length,
            })}
          </Button>
        </div>
      </div>
    </Modal>,
    document.body,
  );
}
