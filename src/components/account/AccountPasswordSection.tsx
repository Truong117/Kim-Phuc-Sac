import ComponentCard from "@/components/common/ComponentCard";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import { useAuth } from "@/hooks/useAuth";
import Button from "@/components/ui/button/Button";
import { updatePassword } from "@/services/accountService";
import { ApiServiceError } from "@/services/apiClient";
import { useState } from "react";
import { useTranslation } from "react-i18next";

const initialValues = {
  currentPassword: "",
  password: "",
  passwordConfirmation: "",
};

export default function AccountPasswordSection() {
  const { t } = useTranslation("common", { keyPrefix: "account.password" });
  const { updateCurrentUser } = useAuth();
  const [values, setValues] = useState(initialValues);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const setField = (field: keyof typeof values, value: string) => {
    setValues((current) => ({ ...current, [field]: value }));
    setError(null);
    setSuccess(null);
  };

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setSuccess(null);

    if (!values.currentPassword) {
      setError(t("currentRequired"));
      return;
    }
    if (values.password.length < 8 || values.password.length > 72) {
      setError(t("length"));
      return;
    }
    if (values.password !== values.passwordConfirmation) {
      setError(t("confirmationMismatch"));
      return;
    }

    setIsSubmitting(true);
    try {
      const updatedUser = await updatePassword({
        current_password: values.currentPassword,
        password: values.password,
        password_confirmation: values.passwordConfirmation,
      });
      updateCurrentUser(updatedUser);
      setValues(initialValues);
      setSuccess(t("success"));
    } catch (request) {
      if (request instanceof ApiServiceError) {
        const firstValidationError = Object.values(
          request.validationErrors ?? {},
        )[0]?.[0];
        setError(
          firstValidationError ??
            (request.status === 0 ? t("networkError") : t("saveError")),
        );
      } else {
        setError(t("saveError"));
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <ComponentCard title={t("title")} desc={t("description")} compact>
      <form
        onSubmit={handleSubmit}
        className="space-y-4"
        aria-busy={isSubmitting}
      >
        <fieldset disabled={isSubmitting} className="space-y-4">
          <div>
            <Label htmlFor="account-current-password">{t("current")}</Label>
            <Input
              id="account-current-password"
              name="current_password"
              type="password"
              value={values.currentPassword}
              autoComplete="current-password"
              required
              showPasswordToggle
              onChange={(event) =>
                setField("currentPassword", event.target.value)
              }
              className="focus:border-kps-primary focus:ring-kps-primary/20 dark:focus:border-kps-primary"
            />
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <div>
              <Label htmlFor="account-new-password">{t("new")}</Label>
              <Input
                id="account-new-password"
                name="password"
                type="password"
                value={values.password}
                autoComplete="new-password"
                required
                minLength={8}
                maxLength={72}
                showPasswordToggle
                onChange={(event) => setField("password", event.target.value)}
                className="focus:border-kps-primary focus:ring-kps-primary/20 dark:focus:border-kps-primary"
              />
            </div>
            <div>
              <Label htmlFor="account-password-confirmation">
                {t("confirmation")}
              </Label>
              <Input
                id="account-password-confirmation"
                name="password_confirmation"
                type="password"
                value={values.passwordConfirmation}
                autoComplete="new-password"
                required
                minLength={8}
                maxLength={72}
                showPasswordToggle
                onChange={(event) =>
                  setField("passwordConfirmation", event.target.value)
                }
                className="focus:border-kps-primary focus:ring-kps-primary/20 dark:focus:border-kps-primary"
              />
            </div>
          </div>

          <div className="flex flex-col items-start justify-between gap-3 sm:flex-row sm:items-center">
            <div aria-live="polite">
              {error && (
                <p
                  role="alert"
                  className="text-theme-sm text-error-600 dark:text-error-400"
                >
                  {error}
                </p>
              )}
              {success && (
                <p className="text-theme-sm text-success-600 dark:text-success-400">
                  {success}
                </p>
              )}
            </div>
            <Button
              type="submit"
              size="sm"
              disabled={isSubmitting}
              className="!bg-kps-primary hover:!bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none disabled:!bg-kps-primary dark:!bg-kps-primary dark:hover:!bg-kps-primary-hover"
            >
              {isSubmitting ? t("saving") : t("save")}
            </Button>
          </div>
        </fieldset>
      </form>
    </ComponentCard>
  );
}
