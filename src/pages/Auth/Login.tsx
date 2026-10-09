import AuthLoadingScreen from "@/components/auth/AuthLoadingScreen";
import BrandMark from "@/components/common/BrandMark";
import PageMeta from "@/components/common/PageMeta";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import Button from "@/components/ui/button/Button";
import { useAuth } from "@/hooks/useAuth";
import { AuthServiceError } from "@/services/authService";
import type { LoginCredentials } from "@/types/auth";
import { useState, type FormEvent } from "react";
import { useTranslation } from "react-i18next";
import { Navigate, useLocation, useNavigate } from "react-router";

type FieldErrors = Partial<Record<keyof LoginCredentials, string>>;

type LoginLocationState = {
  from?: {
    pathname?: string;
    search?: string;
    hash?: string;
  };
};

const loginInputFocusClasses =
  "focus:!border-kps-primary focus:!ring-kps-primary/20 dark:focus:!border-kps-primary dark:focus:!ring-kps-primary/30";

const getRedirectPath = (state: LoginLocationState | null) => {
  const pathname = state?.from?.pathname;

  if (!pathname?.startsWith("/") || pathname.startsWith("//")) {
    return "/dashboard";
  }

  return `${pathname}${state?.from?.search ?? ""}${state?.from?.hash ?? ""}`;
};

export default function Login() {
  const { t } = useTranslation();
  const { isAuthenticated, isInitializing, login } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();
  const [credentials, setCredentials] = useState<LoginCredentials>({
    email: "",
    password: "",
  });
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({});
  const [requestError, setRequestError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  if (isInitializing) {
    return <AuthLoadingScreen />;
  }

  if (isAuthenticated) {
    return <Navigate to="/dashboard" replace />;
  }

  const validate = () => {
    const errors: FieldErrors = {};

    if (!credentials.email.trim()) {
      errors.email = t("auth.login.validation.emailRequired");
    } else if (!/^\S+@\S+\.\S+$/.test(credentials.email)) {
      errors.email = t("auth.login.validation.emailInvalid");
    }

    if (!credentials.password) {
      errors.password = t("auth.login.validation.passwordRequired");
    }

    setFieldErrors(errors);
    return Object.keys(errors).length === 0;
  };

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setRequestError(null);

    if (!validate()) {
      return;
    }

    setIsSubmitting(true);

    try {
      await login({
        email: credentials.email.trim(),
        password: credentials.password,
      });

      navigate(
        getRedirectPath(location.state as LoginLocationState | null),
        { replace: true },
      );
    } catch (error) {
      if (error instanceof AuthServiceError) {
        if ([401, 422].includes(error.status)) {
          setRequestError(t("auth.login.errors.invalidCredentials"));
        } else if (error.status === 419) {
          setRequestError(t("auth.login.errors.sessionExpired"));
        } else if (error.status === 0) {
          setRequestError(t("auth.login.errors.network"));
        } else {
          setRequestError(t("auth.login.errors.server"));
        }
      } else {
        setRequestError(t("auth.login.errors.unexpected"));
      }
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <>
      <PageMeta
        title={t("auth.login.metaTitle")}
        description={t("auth.login.metaDescription")}
      />

      <main className="flex min-h-screen items-center justify-center bg-gray-50 px-4 py-10 dark:bg-gray-950">
        <section className="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-lg sm:p-8 dark:border-gray-800 dark:bg-gray-900">
          <h1 className="sr-only">{t("auth.login.title")}</h1>
          <BrandMark size="large" className="justify-center" />
          <p className="mt-5 text-center text-theme-sm text-gray-500 dark:text-gray-400">
            {t("auth.login.subtitle")}
          </p>

          <form className="mt-7 space-y-5" onSubmit={handleSubmit} noValidate>
            <div>
              <Label htmlFor="email">{t("auth.login.email.label")}</Label>
              <Input
                id="email"
                name="email"
                type="email"
                value={credentials.email}
                onChange={(event) => {
                  setCredentials((current) => ({
                    ...current,
                    email: event.target.value,
                  }));
                  setFieldErrors((current) => ({ ...current, email: undefined }));
                  setRequestError(null);
                }}
                placeholder={t("auth.login.email.placeholder")}
                autoComplete="username"
                disabled={isSubmitting}
                error={Boolean(fieldErrors.email)}
                hint={fieldErrors.email}
                className={fieldErrors.email ? "" : loginInputFocusClasses}
                aria-invalid={Boolean(fieldErrors.email)}
              />
            </div>

            <div>
              <Label htmlFor="password">
                {t("auth.login.password.label")}
              </Label>
              <Input
                id="password"
                name="password"
                type="password"
                value={credentials.password}
                onChange={(event) => {
                  setCredentials((current) => ({
                    ...current,
                    password: event.target.value,
                  }));
                  setFieldErrors((current) => ({
                    ...current,
                    password: undefined,
                  }));
                  setRequestError(null);
                }}
                placeholder={t("auth.login.password.placeholder")}
                autoComplete="current-password"
                showPasswordToggle
                disabled={isSubmitting}
                error={Boolean(fieldErrors.password)}
                hint={fieldErrors.password}
                className={fieldErrors.password ? "" : loginInputFocusClasses}
                aria-invalid={Boolean(fieldErrors.password)}
              />
            </div>

            {requestError && (
              <div
                role="alert"
                className="rounded-lg border border-error-200 bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400"
              >
                {requestError}
              </div>
            )}

            <Button
              type="submit"
              className="w-full !bg-kps-primary !text-white transition-colors duration-200 hover:!bg-kps-primary-hover focus-visible:!bg-kps-primary focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:ring-offset-2 focus-visible:ring-offset-white focus-visible:outline-none disabled:!bg-kps-primary disabled:!opacity-55 disabled:hover:!bg-kps-primary dark:focus-visible:ring-kps-primary/50 dark:focus-visible:ring-offset-gray-900"
              disabled={isSubmitting}
            >
              {isSubmitting
                ? t("auth.login.submitting")
                : t("auth.login.submit")}
            </Button>
          </form>
        </section>
      </main>
    </>
  );
}
