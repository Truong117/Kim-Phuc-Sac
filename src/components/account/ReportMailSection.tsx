import { useEffect, useRef, useState } from "react";
import { useLocation, useNavigate } from "react-router";
import { useTranslation } from "react-i18next";
import ComponentCard from "@/components/common/ComponentCard";
import Button from "@/components/ui/button/Button";
import {
  confirmReportEmail,
  getReportMailStatus,
  requestReportEmailVerification,
  updateReportMail,
  type ReportMailStatus,
} from "@/services/reportMailService";
import { ApiServiceError } from "@/services/apiClient";

export default function ReportMailSection() {
  const { t } = useTranslation("common", { keyPrefix: "reportMail" });
  const location = useLocation();
  const navigate = useNavigate();
  const [token, setToken] = useState(
    () =>
      /^#report-email-confirm=([a-f0-9]{64})$/.exec(location.hash)?.[1] ?? "",
  );
  const [status, setStatus] = useState<ReportMailStatus | null>(null);
  const [version, setVersion] = useState(0);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const requestKey = useRef<string | null>(null);
  const mounted = useRef(true);
  useEffect(() => {
    // Consume fragments in memory only. GETs and mail-link scanners never confirm consent.
    if (location.hash.startsWith("#report-email-confirm=")) {
      void navigate(
        { pathname: location.pathname, search: location.search, hash: "" },
        { replace: true },
      );
    }
  }, [location.hash, location.pathname, location.search, navigate]);
  useEffect(() => {
    mounted.current = true;
    const controller = new AbortController();
    getReportMailStatus(controller.signal)
      .then((value) => {
        if (!controller.signal.aborted) {
          setStatus(value);
          setError("");
        }
      })
      .catch(() => {
        if (!controller.signal.aborted) setError("loadError");
      });
    return () => {
      mounted.current = false;
      controller.abort();
    };
  }, [version]);

  async function action(kind: "request" | "confirm" | "toggle") {
    if (busy || !status) return;
    setBusy(true);
    setError("");
    setNotice("");
    try {
      let value: ReportMailStatus;
      if (kind === "request") {
        requestKey.current ??= crypto.randomUUID();
        value = await requestReportEmailVerification(
          requestKey.current,
          status.email,
        );
        requestKey.current = null;
      } else if (kind === "confirm") value = await confirmReportEmail(token);
      else value = await updateReportMail(!status.weekly_enabled);
      if (!mounted.current) return;
      setStatus(value);
      if (kind === "confirm") {
        setToken("");
        setNotice("confirmed");
      } else if (kind === "request")
        setNotice(
          value.verification_status === "accepted"
            ? "verificationSent"
            : value.verification_status === "unknown"
              ? "verificationUnknown"
              : "verificationQueued",
        );
      else setNotice(value.weekly_enabled ? "enabledNotice" : "disabledNotice");
    } catch (cause) {
      if (
        kind === "request" &&
        cause instanceof ApiServiceError &&
        cause.status !== 0
      )
        requestKey.current = null;
      if (mounted.current)
        setError(
          cause instanceof ApiServiceError && cause.status === 429
            ? "cooldown"
            : kind === "confirm"
              ? "confirmError"
              : "actionError",
        );
    } finally {
      if (mounted.current) setBusy(false);
    }
  }

  return (
    <ComponentCard title={t("title")}>
      <div className="space-y-4 text-sm text-gray-700 dark:text-gray-300">
        <p className="leading-6">{t("description")}</p>
        {error && (
          <p role="alert" className="text-error-600 dark:text-error-400">
            {t(error)}
          </p>
        )}
        {notice && (
          <p role="status" className="text-success-700 dark:text-success-300">
            {t(notice)}
          </p>
        )}
        {!status ? (
          <Button variant="outline" onClick={() => setVersion((v) => v + 1)}>
            {t("reload")}
          </Button>
        ) : !status.schema_ready ? (
          <p>{t("setupRequired")}</p>
        ) : (
          <>
            <p className="font-medium break-all">{status.email}</p>
            <p
              className={
                status.eligibility === "ready"
                  ? "text-success-700 dark:text-success-300"
                  : "text-gray-600 dark:text-gray-400"
              }
            >
              {t(`eligibility.${status.eligibility}`)}
            </p>
            {!status.auto_enabled && (
              <p className="text-xs leading-6 text-gray-500 dark:text-gray-400">
                {t("automationOff")}
              </p>
            )}
            {!notice && !status.verified && status.verification_status && (
              <p className="text-xs leading-6 text-gray-500 dark:text-gray-400">
                {t(`verification.${status.verification_status}`, {
                  defaultValue: t("verificationUnknown"),
                })}
              </p>
            )}
            {token && (
              <div className="space-y-3 rounded-lg border border-brand-200 bg-brand-50 p-4 dark:border-brand-800 dark:bg-brand-950/30">
                <p>{t("confirmHint", { email: status.email })}</p>
                <Button disabled={busy} onClick={() => void action("confirm")}>
                  {t("confirm")}
                </Button>
              </div>
            )}
            <div className="flex flex-wrap gap-3">
              {!status.verified &&
                status.eligibility !== "placeholder" &&
                status.eligibility !== "invalid_email" && (
                  <Button
                    disabled={busy || !status.can_request}
                    onClick={() => void action("request")}
                  >
                    {t("request")}
                  </Button>
                )}
              {status.verified && (
                <Button
                  disabled={busy}
                  variant="outline"
                  onClick={() => void action("toggle")}
                >
                  {t(status.weekly_enabled ? "disable" : "enable")}
                </Button>
              )}
              <Button
                disabled={busy}
                variant="outline"
                onClick={() => setVersion((v) => v + 1)}
              >
                {t("reload")}
              </Button>
            </div>
            {!status.verified &&
              !status.can_request &&
              status.eligibility === "unverified" && (
                <p className="text-xs text-warning-700 dark:text-warning-300">
                  {t("mailUnavailable")}
                </p>
              )}
          </>
        )}
      </div>
    </ComponentCard>
  );
}
