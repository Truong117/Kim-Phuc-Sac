import ComponentCard from "@/components/common/ComponentCard";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import { useAuth } from "@/hooks/useAuth";
import { updateProfile } from "@/services/accountService";
import { ApiServiceError } from "@/services/apiClient";
import type { AuthUser } from "@/types/auth";
import { useState } from "react";
import { useTranslation } from "react-i18next";

interface ProfilePersonalInfoProps {
  user: AuthUser;
}

export default function ProfilePersonalInfo({ user }: ProfilePersonalInfoProps) {
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
      const updatedUser = await updateProfile(normalizedName);
      updateCurrentUser(updatedUser);
      setName(updatedUser.name);
      setSuccess(t("success"));
    } catch (request) {
      if (request instanceof ApiServiceError) {
        setError(
          request.validationErrors?.name?.[0] ??
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
      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="profile-name">{t("name")}</Label>
          <Input
            id="profile-name"
            name="name"
            value={name}
            maxLength={255}
            autoComplete="name"
            onChange={(event) => {
              setName(event.target.value);
              setError(null);
              setSuccess(null);
            }}
            className="focus:border-kps-primary focus:ring-kps-primary/20 dark:focus:border-kps-primary"
          />
        </div>

        <div>
          <Label htmlFor="profile-email">{t("email")}</Label>
          <Input
            id="profile-email"
            name="email"
            type="email"
            value={user.email}
            readOnly
            aria-readonly="true"
            className="cursor-default bg-gray-50 text-gray-500 dark:bg-gray-800 dark:text-gray-400"
          />
          <p className="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400">
            {t("emailNotice")}
          </p>
        </div>

        <div className="flex flex-col items-start justify-between gap-3 sm:flex-row sm:items-center">
          <div aria-live="polite">
            {error && (
              <p className="text-theme-sm text-error-600 dark:text-error-400">
                {error}
              </p>
            )}
            {success && (
              <p className="text-theme-sm text-success-600 dark:text-success-400">
                {success}
              </p>
            )}
          </div>
          <button
            type="submit"
            disabled={isSubmitting || name.trim() === user.name}
            className="inline-flex items-center justify-center rounded-lg bg-kps-primary px-4 py-2.5 text-theme-sm font-semibold text-white transition-colors hover:bg-kps-primary-hover focus-visible:ring-2 focus-visible:ring-kps-primary/40 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
          >
            {isSubmitting ? t("saving") : t("save")}
          </button>
        </div>
      </form>
    </ComponentCard>
  );
}
