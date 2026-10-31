import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import ComponentCard from "@/components/common/ComponentCard";
import AccountEmailForm from "@/components/account/AccountEmailForm";
import Button from "@/components/ui/button/Button";
import { useAuth } from "@/hooks/useAuth";
import useAccountUnsavedChanges from "@/hooks/useAccountUnsavedChanges";
import {
  cancelEmailChange,
  confirmEmailChange,
  getAccountEmailState,
  requestEmailChange,
  type AccountEmailState,
} from "@/services/accountService";
import { ApiServiceError } from "@/services/apiClient";
import { parseEmailChangeFragment } from "@/utils/accountEmailChange";

interface AccountEmailSectionProps {
  revision: number;
}

export default function AccountEmailSection({
  revision,
}: AccountEmailSectionProps) {
  const { t } = useTranslation("common", { keyPrefix: "account.emailChange" });
  const { currentUser, updateCurrentUser } = useAuth();
  const [state, setState] = useState<AccountEmailState | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);
  const [reload, setReload] = useState(0);
  const [token, setToken] = useState<string | null>(null);
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  useAccountUnsavedChanges(Boolean(email || password));

  useEffect(() => {
    const consumeFragment = () => {
      const fragment = window.location.hash;
      if (!fragment.includes("email-change")) return;
      const parsed = parseEmailChangeFragment(fragment);
      setToken(parsed);
      setError(parsed ? null : t("invalidLink"));
      setSuccess(null);
      window.history.replaceState(
        window.history.state,
        "",
        window.location.pathname + window.location.search,
      );
    };
    consumeFragment();
    window.addEventListener("hashchange", consumeFragment);
    return () => window.removeEventListener("hashchange", consumeFragment);
  }, [t]);

  useEffect(() => {
    const controller = new AbortController();
    getAccountEmailState(controller.signal)
      .then((value) => {
        if (controller.signal.aborted) return;
        setState(value);
        setLoadError(false);
      })
      .catch((request: unknown) => {
        if (controller.signal.aborted) return;
        if (!(request instanceof DOMException && request.name === "AbortError"))
          setLoadError(true);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [reload, currentUser?.email, revision]);

  useEffect(() => {
    const refreshOnFocus = () => setReload((value) => value + 1);
    window.addEventListener("focus", refreshOnFocus);
    return () => {
      window.removeEventListener("focus", refreshOnFocus);
    };
  }, []);

  const refresh = () => {
    setLoading(true);
    setReload((value) => value + 1);
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setSuccess(null);
    if (!password) {
      setError(t("passwordRequired"));
      return;
    }
    setBusy(true);
    try {
      if (token) {
        const user = await confirmEmailChange(token, password);
        updateCurrentUser(user);
        setToken(null);
        setSuccess(t("confirmed"));
      } else {
        await requestEmailChange(email.trim(), password);
        setSuccess(t("requested"));
      }
      setEmail("");
      refresh();
    } catch (request) {
      if (request instanceof ApiServiceError) {
        setError(
          request.status === 429
            ? t("rateLimited")
            : request.validationErrors?.current_password
              ? t("passwordIncorrect")
              : request.validationErrors?.email
                ? t("emailInvalid")
                : request.validationErrors?.token
                  ? t("invalidLink")
                  : t("error"),
        );
      } else setError(t("error"));
    } finally {
      setPassword("");
      setBusy(false);
    }
  };

  const cancel = async () => {
    setBusy(true);
    setError(null);
    setSuccess(null);
    try {
      await cancelEmailChange();
      setToken(null);
      setPassword("");
      setSuccess(t("cancelled"));
      refresh();
    } catch {
      setError(t("error"));
    } finally {
      setBusy(false);
    }
  };

  return (
    <ComponentCard title={t("title")} desc={t("description")} compact>
      {loading ? (
        <p
          role="status"
          className="text-theme-sm text-gray-500 dark:text-gray-400"
        >
          {t("loading")}
        </p>
      ) : loadError ? (
        <div className="space-y-3">
          <p
            role="alert"
            className="text-theme-sm text-error-600 dark:text-error-400"
          >
            {t("error")}
          </p>
          <Button type="button" size="sm" variant="outline" onClick={refresh}>
            {t("retry")}
          </Button>
        </div>
      ) : (
        <div className="space-y-4">
          <p className="text-theme-xs text-gray-500 dark:text-gray-400">
            {state?.email_verified ? t("verified") : t("legacyEmail")}
          </p>
          {state?.pending && (
            <div className="space-y-2 rounded-xl border border-warning-200 bg-warning-50 p-4 text-theme-sm text-warning-800 dark:border-warning-800 dark:bg-warning-500/10 dark:text-warning-200">
              <p className="font-medium break-all">
                {t("pending", { email: state.pending.email })}
              </p>
              <p>
                {t("expires", {
                  time: new Date(state.pending.expires_at).toLocaleString(
                    "vi-VN",
                  ),
                })}
              </p>
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={busy}
                onClick={cancel}
              >
                {t("cancel")}
              </Button>
            </div>
          )}
          <AccountEmailForm
            confirming={Boolean(token)}
            pendingEmail={state?.pending?.email}
            email={email}
            password={password}
            busy={busy}
            onSubmit={submit}
            onEmailChange={(value) => {
              setEmail(value);
              setError(null);
              setSuccess(null);
            }}
            onPasswordChange={(value) => {
              setPassword(value);
              setError(null);
            }}
            onDismiss={() => {
              setToken(null);
              setPassword("");
              setError(null);
            }}
          />
        </div>
      )}
      <div aria-live="polite" aria-atomic="true">
        {error && (
          <p
            role="alert"
            className="text-theme-sm text-error-600 dark:text-error-400"
          >
            {error}
          </p>
        )}
        {success && (
          <p
            role="status"
            className="text-theme-sm text-success-600 dark:text-success-400"
          >
            {success}
          </p>
        )}
      </div>
    </ComponentCard>
  );
}
