import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import Button from "@/components/ui/button/Button";
import useSpaForm from "../hooks/useSpaForm";
import { useSpaPreview } from "../hooks/useSpaPreviewSnapshot";
import { spaFieldClasses } from "../config";
import SpaFormError from "./SpaFormError";
export default function SpaPhotoRemoval({
  appointmentId,
  photoId,
  onClose,
}: {
  appointmentId: string;
  photoId: string;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const { execute } = useSpaPreview();
  const [reason, setReason] = useState("");
  const {
    ref: formRef,
    error,
    setError,
    fail,
  } = useSpaForm(Boolean(reason), onClose);
  useEffect(() => {
    const field = formRef.current?.querySelector("textarea");
    field?.scrollIntoView({ block: "nearest", behavior: "smooth" });
    field?.focus();
  }, [formRef]);
  return (
    <form
      ref={formRef}
      noValidate
      className="space-y-3 rounded-xl border border-error-200 p-3 dark:border-error-800"
      onSubmit={(event) => {
        event.preventDefault();
        const error = execute({
          kind: "photo-remove",
          id: appointmentId,
          photoId,
          reason,
        });
        if (error) fail(error);
        else onClose();
      }}
    >
      <SpaFormError error={error} />
      <Label htmlFor={`spa-photo-remove-${photoId}`}>
        {t("spa.photos.removeReason")}
      </Label>
      <TextArea
        id={`spa-photo-remove-${photoId}`}
        value={reason}
        onChange={(v) => {
          setReason(v);
          setError(undefined);
        }}
        placeholder={t("spa.photos.reasonHint")}
        error={error === "reasonRequired"}
        className={spaFieldClasses}
      />
      <div className="flex flex-wrap gap-2">
        <Button
          type="submit"
          size="sm"
          variant="destructive"
          className="min-h-11"
        >
          {t("spa.photos.confirmRemove")}
        </Button>
        <Button
          type="button"
          size="sm"
          variant="outline"
          className="min-h-11"
          onClick={onClose}
        >
          {t("spa.business.cancel")}
        </Button>
      </div>
    </form>
  );
}
