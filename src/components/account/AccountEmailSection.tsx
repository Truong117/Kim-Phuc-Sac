import { useState, type FormEvent } from "react";
import { useTranslation } from "react-i18next";
import ComponentCard from "@/components/common/ComponentCard";
import AccountEmailForm, {
  type AccountEmailErrors,
} from "@/components/account/AccountEmailForm";
import { useAuth } from "@/hooks/useAuth";
import { updateEmail } from "@/services/accountService";
import { ApiServiceError } from "@/services/apiClient";

export default function AccountEmailSection() {
  const { t } = useTranslation("common", { keyPrefix: "account.emailChange" });
  const { currentUser, updateCurrentUser } = useAuth();
  const [expectedEmail, setExpectedEmail] = useState(currentUser?.email ?? "");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState<AccountEmailErrors>({});
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (busy) return;
    setError(null);
    setSuccess(null);
    const normalizedEmail = email.trim().toLocaleLowerCase("en-US");
    const validation: AccountEmailErrors = {};
    if (
      normalizedEmail.length > 255 ||
      !/^\S+@\S+\.\S+$/.test(normalizedEmail)
    ) {
      validation.email = t("emailInvalid");
    }
    if (!password) validation.current_password = t("passwordRequired");
    setErrors(validation);
    if (Object.keys(validation).length) return;

    setBusy(true);
    try {
      const user = await updateEmail(normalizedEmail, password, expectedEmail);
      updateCurrentUser(user);
      setExpectedEmail(user.email);
      setEmail("");
      setSuccess(t("success"));
    } catch (request) {
      if (request instanceof ApiServiceError) {
        if (request.validationErrors?.current_password) {
          setErrors({ current_password: t("passwordIncorrect") });
        } else if (request.validationErrors?.email) {
          setErrors({
            email: request.validationErrors.email[0] ?? t("emailInvalid"),
          });
        } else {
          setError(
            request.status === 409
              ? t("conflict")
              : request.status === 429
                ? t("rateLimited")
                : t("error"),
          );
        }
      } else setError(t("error"));
    } finally {
      setPassword("");
      setBusy(false);
    }
  };

  return (
    <ComponentCard title={t("title")} desc={t("description")} compact>
      <AccountEmailForm
        email={email}
        password={password}
        busy={busy}
        errors={errors}
        onSubmit={submit}
        onEmailChange={(value) => {
          setEmail(value);
          setErrors((current) => ({ ...current, email: undefined }));
          setError(null);
          setSuccess(null);
        }}
        onPasswordChange={(value) => {
          setPassword(value);
          setErrors((current) => ({ ...current, current_password: undefined }));
          setError(null);
          setSuccess(null);
        }}
      />
      <div aria-live="polite" aria-atomic="true">
        {(error || errors.email || errors.current_password) && (
          <p
            role="alert"
            className="text-theme-sm text-error-600 dark:text-error-400"
          >
            {error ?? errors.email ?? errors.current_password}
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
