import ComponentCard from "@/components/common/ComponentCard";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import { useAuth } from "@/hooks/useAuth";
import Button from "@/components/ui/button/Button";
import { updateProfile } from "@/services/accountService";
import { ApiServiceError } from "@/services/apiClient";
import type { AuthUser } from "@/types/auth";
import { useState } from "react";
import { useTranslation } from "react-i18next";

interface ProfilePersonalInfoProps {
  user: AuthUser;
}

export default function ProfilePersonalInfo({
  user,
}: ProfilePersonalInfoProps) {
  const { t } = useTranslation("common", { keyPrefix: "profile.personal" });
  const { updateCurrentUser } = useAuth();
  const [name, setName] = useState(user.name);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    const normalizedName = name.trim();

    setError(null);
    setSuccess(null);

    if (!normalizedName) {
      setError(t("nameRequired"));
      return;
    }

    setIsSubmitting(true);
    try {
      const updatedUser = await updateProfile(normalizedName, user.name);
      updateCurrentUser(updatedUser);
      setName(updatedUser.name);
      setSuccess(t("success"));
    } catch (request) {
      if (request instanceof ApiServiceError) {
        setError(
          (request.status === 409
            ? t("conflict")
            : request.validationErrors?.name?.[0]) ??
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
        <div>
          <Label htmlFor="profile-name">{t("name")}</Label>
          <Input
            id="profile-name"
            name="name"
            value={name}
            maxLength={255}
            autoComplete="name"
            required
            disabled={isSubmitting}
            aria-invalid={Boolean(error)}
            aria-describedby={error ? "profile-name-error" : undefined}
            onChange={(event) => {
              setName(event.target.value);
              setError(null);
              setSuccess(null);
            }}
            className="focus:border-kps-primary focus:ring-kps-primary/20 dark:focus:border-kps-primary"
          />
        </div>

        <div className="flex flex-col items-start justify-between gap-3 sm:flex-row sm:items-center">
          <div aria-live="polite">
            {error && (
              <p
                id="profile-name-error"
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
          <div className="flex gap-3">
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={isSubmitting || name === user.name}
              onClick={() => {
                setName(user.name);
                setError(null);
                setSuccess(null);
              }}
            >
              {t("reset")}
            </Button>
            <Button
              type="submit"
              size="sm"
              disabled={isSubmitting || name.trim() === user.name}
              className="!bg-kps-primary hover:!bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none disabled:!bg-kps-primary dark:!bg-kps-primary dark:hover:!bg-kps-primary-hover"
            >
              {isSubmitting ? t("saving") : t("save")}
            </Button>
          </div>
        </div>
      </form>
    </ComponentCard>
  );
}
