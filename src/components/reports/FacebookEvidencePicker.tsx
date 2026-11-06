import { useState } from "react";
import { useTranslation } from "react-i18next";
import { useModal } from "@/hooks/useModal";
import Button from "@/components/ui/button/Button";
import type { FacebookSelection } from "@/types/reports";
import { parseEvidenceLinks, isFacebookEvidence } from "@/utils/reportEvidence";
import FacebookPostPicker from "./FacebookPostPicker";

type Props = {
  value: string;
  selections: FacebookSelection[];
  reportDate?: string;
  disabled?: boolean;
  onChange: (value: string, selections: FacebookSelection[]) => void;
};
export default function FacebookEvidencePicker({
  value,
  selections,
  reportDate,
  disabled,
  onChange,
}: Props) {
  const { t } = useTranslation("common", { keyPrefix: "facebookPicker" });
  const { isOpen, openModal, closeModal } = useModal();
  const [source, setSource] = useState<string | undefined>();
  const urls = parseEvidenceLinks(value);
  const choices = selections.filter((row) => urls.includes(row.source_url));
  const open = (url?: string) => {
    setSource(url);
    openModal();
  };
  const confirm = (posts: FacebookSelection[]) => {
    if (disabled || (source && !urls.includes(source))) return;
    const nextUrls = source
      ? urls
      : [...new Set([...urls, ...posts.map((post) => post.source_url)])];
    if (nextUrls.length > 10) return;
    const replaced = new Set(posts.map((post) => post.source_url));
    onChange(nextUrls.join("\n"), [
      ...choices.filter((row) => !replaced.has(row.source_url)),
      ...posts,
    ]);
    closeModal();
  };
  return (
    <div className="mt-2 space-y-2" data-facebook-evidence-picker>
      <Button
        type="button"
        size="sm"
        variant="outline"
        disabled={disabled || urls.length >= 10}
        onClick={() => open()}
      >
        {t("choose")}
      </Button>
      {urls.filter(isFacebookEvidence).map((url) => {
        const choice = choices.find((row) => row.source_url === url);
        return (
          <div
            key={url}
            className="rounded-lg bg-gray-50 p-2 text-theme-xs dark:bg-gray-800"
          >
            <p className="break-all text-gray-600 dark:text-gray-300">
              {t("linkNumber", { number: urls.indexOf(url) + 1 })}
            </p>
            {choice && (
              <p className="my-1 text-success-700 dark:text-success-400">
                {t("confirmed", { page: choice.page_name })}{" "}
                <a
                  className="underline"
                  href={choice.post_url}
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  {t("open")}
                </a>
              </p>
            )}
            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                size="sm"
                variant="outline"
                disabled={disabled}
                onClick={() => open(url)}
              >
                {t(choice ? "change" : "chooseForLink")}
              </Button>
              {choice && (
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={disabled}
                  onClick={() =>
                    onChange(
                      value,
                      choices.filter((row) => row.source_url !== url),
                    )
                  }
                >
                  {t("remove")}
                </Button>
              )}
            </div>
          </div>
        );
      })}
      {isOpen && (
        <FacebookPostPicker
          sourceUrl={source}
          reportDate={reportDate}
          existingUrls={urls}
          onClose={closeModal}
          onConfirm={confirm}
        />
      )}
    </div>
  );
}
