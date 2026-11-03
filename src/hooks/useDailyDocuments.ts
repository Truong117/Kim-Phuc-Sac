import { useEffect, useState } from "react";
import { listDailyDocuments } from "@/services/dailyDocumentService";
import type { DailyDocumentsPage } from "@/types/dailyDocument";

export default function useDailyDocuments(
  date: string,
  page: number,
  revision: number,
) {
  const key = `${date}:${page}:${revision}`;
  const [state, setState] = useState<{
    key: string;
    data?: DailyDocumentsPage;
    error?: string;
  }>();
  useEffect(() => {
    const controller = new AbortController();
    let timer: ReturnType<typeof setTimeout> | undefined;
    async function load() {
      try {
        const data = await listDailyDocuments(date, page, controller.signal);
        if (controller.signal.aborted) return;
        setState({ key, data });
        if (
          (data.summary?.processing ?? 0) > 0 ||
          data.reports.some(
            (row) =>
              row.document &&
              ["pending", "running"].includes(row.document.status),
          )
        ) {
          timer = setTimeout(() => void load(), 5000);
        }
      } catch (error) {
        if (!controller.signal.aborted)
          setState({
            key,
            error: error instanceof Error ? error.message : String(error),
          });
      }
    }
    void load();
    return () => {
      controller.abort();
      clearTimeout(timer);
    };
  }, [date, page, key]);
  return {
    data: state?.key === key ? state.data : undefined,
    error: state?.key === key ? state.error : undefined,
  };
}
