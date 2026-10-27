import Input from "@/components/form/input/InputField";
import Button from "@/components/ui/button/Button";
import { DocsIcon } from "@/icons";
import { ApiServiceError } from "@/services/apiClient";
import {
  claimGoogleDocsSourceRequest,
  releaseGoogleDocsSourceRequest,
} from "@/services/googleDocsSourceRequest";
import {
  importWeeklyPlanGoogleDocs,
  previewWeeklyPlanGoogleDocs,
} from "@/services/weeklyPlanningService";
import type {
  WeeklyPlan,
  WeeklyPlanGoogleDocsPreview,
} from "@/types/weeklyPlanning";
import {
  buildGoogleDocsTabTree,
  getGoogleDocsPreviewErrorKey,
  GoogleDocsPreviewRequestLifecycle,
  isValidGoogleDocsUrl,
  type GoogleDocsPreviewTreeNode,
} from "@/utils/googleDocsPreview";
import { useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";

const REQUEST_TIMEOUT_MS = 60_000;
const numberFormatter = new Intl.NumberFormat("vi-VN");

type TabTreeProps = {
  nodes: GoogleDocsPreviewTreeNode[];
  passagesLabel: (count: number) => string;
  charactersLabel: (count: number) => string;
};

function TabTree({ nodes, passagesLabel, charactersLabel }: TabTreeProps) {
  return (
    <ul className="space-y-2" role="list">
      {nodes.map((node) => (
        <li key={node.tab_id}>
          <div className="rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 dark:border-gray-700 dark:bg-gray-900/60">
            <p className="text-theme-sm font-semibold text-gray-800 dark:text-white/90">
              {node.title}
            </p>
            <p className="mt-1 text-theme-xs text-gray-500 dark:text-gray-400">
              {passagesLabel(node.passage_count)} ·{" "}
              {charactersLabel(node.character_count)}
            </p>
          </div>
          {node.children.length > 0 && (
            <div className="ms-4 mt-2 border-s border-gray-200 ps-3 dark:border-gray-700">
              <TabTree
                nodes={node.children}
                passagesLabel={passagesLabel}
                charactersLabel={charactersLabel}
              />
            </div>
          )}
        </li>
      ))}
    </ul>
  );
}

type Props = {
  onImported: (plan: WeeklyPlan) => void;
};

export default function GoogleDocsPreviewPanel({ onImported }: Props) {
  const { t } = useTranslation("common", {
    keyPrefix: "weeklyPlanning.googleDocsPreview",
  });
  const [documentUrl, setDocumentUrl] = useState("");
  const [touched, setTouched] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [preview, setPreview] = useState<WeeklyPlanGoogleDocsPreview | null>(
    null,
  );
  const [requestId, setRequestId] = useState<string | null>(null);
  const [importing, setImporting] = useState(false);
  const importingRef = useRef(false);
  const importController = useRef<AbortController | null>(null);
  const requestLifecycle =
    useRef<GoogleDocsPreviewRequestLifecycle<WeeklyPlanGoogleDocsPreview> | null>(
      null,
    );
  if (requestLifecycle.current === null) {
    requestLifecycle.current = new GoogleDocsPreviewRequestLifecycle(
      REQUEST_TIMEOUT_MS,
    );
  }

  useEffect(
    () => () => {
      requestLifecycle.current?.cancel();
      importController.current?.abort();
      importController.current = null;
      releaseGoogleDocsSourceRequest(importingRef);
    },
    [],
  );

  const trimmedUrl = documentUrl.trim();
  const urlError = touched
    ? trimmedUrl === ""
      ? t("urlRequired")
      : !isValidGoogleDocsUrl(trimmedUrl)
        ? t("errors.invalidUrl")
        : undefined
    : undefined;

  const readDocument = () => {
    setTouched(true);
    setError(null);
    setPreview(null);

    if (!isValidGoogleDocsUrl(trimmedUrl)) {
      requestLifecycle.current?.cancel();
      setLoading(false);
      return;
    }

    void requestLifecycle.current?.run(
      (signal) => previewWeeklyPlanGoogleDocs(trimmedUrl, signal),
      {
        onStart: () => {
          setError(null);
          setPreview(null);
          setLoading(true);
        },
        onSuccess: (nextPreview) => {
          setPreview(nextPreview);
          setRequestId(crypto.randomUUID());
        },
        onError: (nextError) => {
          setError(
            nextError instanceof ApiServiceError
              ? t(`errors.${getGoogleDocsPreviewErrorKey(nextError.status)}`)
              : t("errors.unknown"),
          );
        },
        onTimeout: () => setError(t("errors.timeout")),
        onSettled: () => setLoading(false),
      },
    );
  };

  const tabTree = preview ? buildGoogleDocsTabTree(preview.tabs) : [];

  const confirmSource = async () => {
    if (
      !preview ||
      !requestId ||
      !claimGoogleDocsSourceRequest(importingRef)
    )
      return;

    const controller = new AbortController();
    importController.current?.abort();
    importController.current = controller;
    setImporting(true);
    setError(null);
    try {
      const plan = await importWeeklyPlanGoogleDocs(
        trimmedUrl,
        preview.snapshot_sha256,
        requestId,
        controller.signal,
      );
      if (
        importController.current === controller &&
        !controller.signal.aborted
      )
        onImported(plan);
    } catch (nextError) {
      if (importController.current !== controller) return;
      if (nextError instanceof DOMException && nextError.name === "AbortError") {
        return;
      }
      if (nextError instanceof ApiServiceError) {
        if (nextError.status === 409) {
          setPreview(null);
          setRequestId(null);
          setError(nextError.message || t("errors.changed"));
        } else {
          setError(t(`errors.${getGoogleDocsPreviewErrorKey(nextError.status)}`));
        }
      } else {
        setError(t("errors.confirm"));
      }
    } finally {
      if (importController.current === controller) {
        importController.current = null;
        releaseGoogleDocsSourceRequest(importingRef);
        setImporting(false);
      }
    }
  };

  return (
    <section className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/3">
      <div className="flex items-start gap-3">
        <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-kps-primary/10 text-kps-primary dark:text-sidebar-selected">
          <DocsIcon className="size-5" aria-hidden="true" />
        </span>
        <div>
          <h2 className="font-semibold text-gray-900 dark:text-white">
            {t("title")}
          </h2>
          <p className="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">
            {t("description")}
          </p>
        </div>
      </div>

      <form
        className="mt-5 space-y-3"
        noValidate
        onSubmit={(event) => {
          event.preventDefault();
          readDocument();
        }}
      >
        <label
          htmlFor="weekly-plan-google-docs-url"
          className="block text-theme-sm font-medium text-gray-700 dark:text-gray-300"
        >
          {t("urlLabel")}
        </label>
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
          <div className="min-w-0 flex-1">
            <Input
              id="weekly-plan-google-docs-url"
              type="url"
              inputMode="url"
              autoComplete="off"
              value={documentUrl}
              placeholder={t("urlPlaceholder")}
              error={Boolean(urlError)}
              hint={urlError ?? t("urlHint")}
              aria-invalid={Boolean(urlError)}
              onBlur={() => setTouched(true)}
              onChange={(event) => {
                requestLifecycle.current?.cancel();
                importController.current?.abort();
                importController.current = null;
                releaseGoogleDocsSourceRequest(importingRef);
                setDocumentUrl(event.target.value);
                setError(null);
                setPreview(null);
                setRequestId(null);
                setLoading(false);
                setImporting(false);
              }}
            />
          </div>
          <Button
            type="submit"
            disabled={loading || importing}
            className="bg-kps-primary hover:bg-kps-primary-hover sm:min-w-36"
            startIcon={
              loading ? (
                <span
                  className="size-4 animate-spin rounded-full border-2 border-white/40 border-t-white"
                  aria-hidden="true"
                />
              ) : undefined
            }
          >
            {loading ? t("loading") : t("submit")}
          </Button>
        </div>
      </form>

      {error && (
        <p
          role="alert"
          className="mt-4 rounded-lg bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
        >
          {error}
        </p>
      )}

      {loading && (
        <div
          role="status"
          aria-live="polite"
          className="mt-4 rounded-xl border border-gray-200 p-4 dark:border-gray-700"
        >
          <div className="h-5 w-2/3 animate-pulse rounded bg-gray-100 dark:bg-gray-800" />
          <div className="mt-3 h-16 animate-pulse rounded bg-gray-100 dark:bg-gray-800" />
          <span className="sr-only">{t("loading")}</span>
        </div>
      )}

      {preview && !loading && (
        <div className="mt-5 space-y-4" aria-live="polite">
          <div className="rounded-xl border border-kps-primary/20 bg-kps-primary/5 p-4 dark:border-kps-primary/30">
            <p className="text-theme-xs font-medium tracking-wide text-kps-primary uppercase dark:text-sidebar-selected">
              {t("documentTitle")}
            </p>
            <h3 className="mt-1 font-semibold text-gray-900 dark:text-white">
              {preview.title}
            </h3>
            {!preview.identity_verified && (
              <p className="mt-2 text-theme-xs text-warning-700 dark:text-warning-400">
                {t("identityUnverified")}
              </p>
            )}
          </div>

          <dl className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            {[
              [t("metrics.tabs"), preview.tab_count],
              [t("metrics.passages"), preview.passage_count],
              [t("metrics.characters"), preview.character_count],
            ].map(([label, value]) => (
              <div
                key={String(label)}
                className="rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-gray-900/60"
              >
                <dt className="text-theme-xs text-gray-500 dark:text-gray-400">
                  {label}
                </dt>
                <dd className="mt-1 font-semibold text-gray-900 dark:text-white">
                  {numberFormatter.format(Number(value))}
                </dd>
              </div>
            ))}
          </dl>

          <div>
            <h3 className="mb-3 text-theme-sm font-semibold text-gray-800 dark:text-white/90">
              {t("tabsTitle")}
            </h3>
            {tabTree.length > 0 ? (
              <TabTree
                nodes={tabTree}
                passagesLabel={(count) => t("tabPassages", { count })}
                charactersLabel={(count) =>
                  t("tabCharacters", {
                    count: numberFormatter.format(count),
                  })
                }
              />
            ) : (
              <p className="rounded-lg bg-gray-50 px-4 py-3 text-theme-sm text-gray-500 dark:bg-gray-900/60 dark:text-gray-400">
                {t("emptyTabs")}
              </p>
            )}
          </div>

          <p className="text-theme-xs text-gray-500 dark:text-gray-400">
            {t("previewOnly")}
          </p>
          <div className="flex flex-col gap-3 border-t border-gray-100 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-gray-800">
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">
              {t("confirmHint")}
            </p>
            <Button
              type="button"
              disabled={importing || !requestId}
              className="bg-kps-primary hover:bg-kps-primary-hover sm:min-w-44"
              onClick={() => void confirmSource()}
            >
              {importing ? t("confirming") : t("confirm")}
            </Button>
          </div>
        </div>
      )}
    </section>
  );
}
