import { useTranslation } from "react-i18next";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import Button from "@/components/ui/button/Button";

interface AccountEmailFormProps {
  confirming: boolean;
  pendingEmail?: string;
  email: string;
  password: string;
  busy: boolean;
  onEmailChange: (value: string) => void;
  onPasswordChange: (value: string) => void;
  onSubmit: (event: React.FormEvent) => void;
  onDismiss: () => void;
}

export default function AccountEmailForm({
  confirming,
  pendingEmail,
  email,
  password,
  busy,
  onEmailChange,
  onPasswordChange,
  onSubmit,
  onDismiss,
}: AccountEmailFormProps) {
  const { t } = useTranslation("common", { keyPrefix: "account.emailChange" });
  return (
    <form onSubmit={onSubmit} className="space-y-4" aria-busy={busy}>
      <fieldset disabled={busy} className="space-y-4">
        {confirming ? (
          <div className="rounded-xl border border-brand-200 bg-brand-50 p-4 text-theme-sm text-brand-800 dark:border-brand-800 dark:bg-brand-500/10 dark:text-brand-200">
            <p className="font-semibold">{t("confirmTitle")}</p>
            {pendingEmail && (
              <p className="mt-1 font-medium break-all">
                {t("confirmTarget", { email: pendingEmail })}
              </p>
            )}
            <p className="mt-1">{t("confirmDescription")}</p>
          </div>
        ) : (
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
              hint={t("emailHint")}
            />
          </div>
        )}
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
          />
        </div>
        <div className="flex flex-wrap gap-3">
          <Button
            type="submit"
            size="sm"
            disabled={busy}
            className="!bg-kps-primary hover:!bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none disabled:!bg-kps-primary dark:!bg-kps-primary dark:hover:!bg-kps-primary-hover"
          >
            {busy ? t("saving") : confirming ? t("confirm") : t("request")}
          </Button>
          {confirming && (
            <Button
              type="button"
              size="sm"
              variant="outline"
              disabled={busy}
              onClick={onDismiss}
            >
              {t("dismiss")}
            </Button>
          )}
        </div>
      </fieldset>
    </form>
  );
}
