import { useCallback, useEffect, useRef, useState } from "react";
import {
  getFacebookAnalytics,
  getFacebookAnalyticsPages,
} from "@/services/facebookAnalyticsService";
import type {
  FacebookAnalyticsData,
  FacebookAnalyticsPage,
} from "@/types/facebookAnalytics";

export function useFacebookAnalytics(
  pageId: number | null,
  from: string,
  to: string,
) {
  const [pages, setPages] = useState<FacebookAnalyticsPage[]>([]);
  const [pagesState, setPagesState] = useState("loading");
  const [canManage, setCanManage] = useState(false);
  const [pagesVersion, setPagesVersion] = useState(0);
  const [response, setResponse] = useState<{
    key: string;
    data: FacebookAnalyticsData;
  } | null>(null);
  const [errorKey, setErrorKey] = useState<string | null>(null);
  const [loadingKey, setLoadingKey] = useState<string | null>(null);
  const [revision, setRevision] = useState(0);
  const selected = pages.find((p) => p.id === pageId) ?? pages[0];
  const id = selected?.id ?? null;
  const key = `${id}:${from}:${to}`;
  const current = useRef(key);
  useEffect(() => {
    current.current = key;
    return () => {
      current.current = "";
    };
  }, [key]);
  useEffect(() => {
    const controller = new AbortController();
    let active = true;
    void getFacebookAnalyticsPages(
      AbortSignal.any([controller.signal, AbortSignal.timeout(45000)]),
    )
      .then((result) => {
        if (!active) return;
        setPages(result.data);
        setCanManage(result.can_manage);
        setPagesState("ready");
      })
      .catch(() => {
        if (active) {
          setPages([]);
          setPagesState("failed");
        }
      });
    return () => {
      active = false;
      controller.abort();
    };
  }, [pagesVersion]);
  useEffect(() => {
    if (id == null) return;
    const controller = new AbortController();
    let active = true;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let polls = 0;
    async function fetchData() {
      if (id == null) return;
      setLoadingKey(key);
      try {
        const result = await getFacebookAnalytics(
          id,
          from,
          to,
          AbortSignal.any([controller.signal, AbortSignal.timeout(45000)]),
        );
        if (!active) return;
        setResponse({ key, data: result.data });
        setErrorKey(null);
        const busy =
          ["queued", "running"].includes(result.data.sync.status) &&
          !result.data.sync.interrupted;
        const aiBusy =
          ["queued", "running"].includes(result.data.analysis?.status ?? "") &&
          !result.data.analysis?.interrupted;
        if ((busy || aiBusy) && polls++ < 60)
          timer = setTimeout(() => void fetchData(), 5000);
      } catch {
        if (active) {
          setErrorKey(key);
          setResponse(null);
        }
      } finally {
        if (active) setLoadingKey(null);
      }
    }
    void fetchData();
    return () => {
      active = false;
      controller.abort();
      clearTimeout(timer);
    };
  }, [id, from, to, revision, key]);
  useEffect(() => {
    let last = Date.now();
    function recheck() {
      if (document.visibilityState !== "visible" || Date.now() - last < 5000)
        return;
      last = Date.now();
      setPagesVersion((n) => n + 1);
      setRevision((n) => n + 1);
    }
    window.addEventListener("focus", recheck);
    document.addEventListener("visibilitychange", recheck);
    return () => {
      window.removeEventListener("focus", recheck);
      document.removeEventListener("visibilitychange", recheck);
    };
  }, []);
  const refresh = useCallback(() => setRevision((n) => n + 1), []);
  const reloadPages = useCallback(() => {
    setPagesState("loading");
    setPagesVersion((n) => n + 1);
  }, []);
  return {
    pages,
    pagesState,
    canManage,
    selected,
    key,
    current,
    data: response?.key === key ? response.data : null,
    loading: loadingKey === key,
    error: errorKey === key,
    refresh,
    reloadPages,
  };
}
