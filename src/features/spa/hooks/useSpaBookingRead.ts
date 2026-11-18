import { useEffect, useReducer, useState } from "react";

export default function useSpaBookingRead<T>(
  request: (signal: AbortSignal) => Promise<T>,
  delay = 0,
) {
  const [revision, reload] = useReducer((value: number) => value + 1, 0);
  const [result, setResult] = useState<{
    request: typeof request;
    revision: number;
    data?: T;
    failed?: boolean;
  }>();
  useEffect(() => {
    const controller = new AbortController();
    const load = () => {
      void request(controller.signal).then(
        (data) => {
          if (!controller.signal.aborted)
            setResult({ request, revision, data });
        },
        () => {
          if (!controller.signal.aborted)
            setResult({ request, revision, failed: true });
        },
      );
    };
    const timer = delay ? window.setTimeout(load, delay) : undefined;
    if (!delay) load();
    return () => {
      controller.abort();
      if (timer !== undefined) window.clearTimeout(timer);
    };
  }, [delay, request, revision]);
  const current =
    result?.request === request && result.revision === revision
      ? result
      : undefined;
  return {
    data: current?.data,
    failed: current?.failed === true,
    loading: current === undefined,
    reload,
  };
}
