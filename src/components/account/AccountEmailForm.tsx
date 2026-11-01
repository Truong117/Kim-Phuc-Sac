import type { FormEvent } from "react";
import { useTranslation } from "react-i18next";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import Button from "@/components/ui/button/Button";

export interface AccountEmailErrors {
  email?: string;
  current_password?: string;
}

interface AccountEmailFormProps {
  email: string;
  password: string;
  busy: boolean;
  errors: AccountEmailErrors;
  onEmailChange: (value: string) => void;
  onPasswordChange: (value: string) => void;
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
}

export default function AccountEmailForm({
  email,
  password,
  busy,
  errors,
  onEmailChange,
  onPasswordChange,
  onSubmit,
}: AccountEmailFormProps) {
  const { t } = useTranslation("common", { keyPrefix: "account.emailChange" });

  return (
    <form onSubmit={onSubmit} className="space-y-4" aria-busy={busy} noValidate>
      <fieldset disabled={busy} className="space-y-4">
        <div>
          <Label htmlFor="account-new-email">{t("newEmail")}</Label>
          <Input
            id="account-new-email"
            name="email"
            type="email"
            required
            maxLength={255}
            value={email}
            autoComplete="email"
            onChange={(event) => onEmailChange(event.target.value)}
            error={Boolean(errors.email)}
            hint={errors.email ?? t("emailHint")}
            aria-invalid={Boolean(errors.email)}
          />
        </div>
        <div>
          <Label htmlFor="account-email-password">{t("currentPassword")}</Label>
          <Input
            id="account-email-password"
            name="current_password"
            type="password"
            required
            value={password}
            autoComplete="current-password"
            showPasswordToggle
            onChange={(event) => onPasswordChange(event.target.value)}
            error={Boolean(errors.current_password)}
            hint={errors.current_password}
            aria-invalid={Boolean(errors.current_password)}
          />
        </div>
        <Button
          type="submit"
          size="sm"
          disabled={busy}
          className="!bg-kps-primary hover:!bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none disabled:!bg-kps-primary dark:!bg-kps-primary dark:hover:!bg-kps-primary-hover"
        >
          {busy ? t("saving") : t("save")}
        </Button>
      </fieldset>
    </form>
  );
}
