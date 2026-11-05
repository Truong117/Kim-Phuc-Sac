import { useState } from "react";
import { useTranslation } from "react-i18next";
import Button from "@/components/ui/button/Button";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";

export interface SocialCredentials {
  pageId: string;
  accessToken: string;
}

export default function SocialConnectionForm({
  initialPageId,
  busy,
  onConnect,
  onClose,
}: {
  initialPageId: string;
  busy: boolean;
  onConnect: (credentials: SocialCredentials) => Promise<void>;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const [pageId, setPageId] = useState(initialPageId);
  const [token, setToken] = useState("");

  return (
    <form
      autoComplete="off"
      onSubmit={(event) => {
        event.preventDefault();
        if (busy) return;
        const credentials = {
          pageId: pageId.trim(),
          accessToken: token.trim(),
        };
        setToken("");
        void onConnect(credentials);
      }}
      className="space-y-3 rounded-xl border border-gray-200 p-3 dark:border-gray-700"
    >
      <fieldset disabled={busy} className="space-y-3">
        <p
          id="social-connection-hint"
          className="text-xs text-gray-500 dark:text-gray-400"
        >
          {t("integrationsUi.social.connectHint")}
        </p>
        <div>
          <Label htmlFor="social-page-id">
            {t("integrationsUi.social.pageId")}
          </Label>
          <Input
            id="social-page-id"
            name="facebook-page-id"
            required
            pattern="[0-9]{1,32}"
            inputMode="numeric"
            maxLength={32}
            value={pageId}
            onChange={(event) => setPageId(event.target.value)}
            autoComplete="off"
            spellCheck={false}
          />
        </div>
        <div>
          <Label htmlFor="social-token">
            {t("integrationsUi.social.token")}
          </Label>
          <Input
            id="social-token"
            name="facebook-page-access-token"
            required
            type="password"
            minLength={16}
            maxLength={4096}
            value={token}
            onChange={(event) => setToken(event.target.value)}
            autoComplete="new-password"
            aria-describedby="social-connection-hint"
            autoCapitalize="none"
            spellCheck={false}
          />
        </div>
        <div className="flex flex-wrap gap-2">
          <Button type="submit" variant="outline" size="sm">
            {t("integrationsUi.social.connect")}
          </Button>
          <Button type="button" variant="outline" size="sm" onClick={onClose}>
            {t("integrationsUi.social.closeConnectionForm")}
          </Button>
        </div>
      </fieldset>
    </form>
  );
}
