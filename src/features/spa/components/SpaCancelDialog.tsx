import { spaFieldClasses } from "@/features/spa/config";
import { useState } from "react";
import { useTranslation } from "react-i18next";
import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import Button from "@/components/ui/button/Button";
import useSpaForm from "../hooks/useSpaForm";
import { useSpaPreview } from "../hooks/useSpaPreviewSnapshot";
import type { SpaAppointment } from "../types";
import SpaDialog from "./SpaDialog";
import SpaFormError from "./SpaFormError";

export default function SpaCancelDialog({
  appointment,
  onClose,
  onSaved,
}: {
  appointment: SpaAppointment;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { t } = useTranslation();
  const { execute } = useSpaPreview();
  const [reason, setReason] = useState("");
  const {
    ref: formRef,
    error,
    setError,
    fail,
    close,
  } = useSpaForm(Boolean(reason), onClose);
  return (
    <SpaDialog title={t("spa.flow.cancelBooking")} onClose={close}>
      <form
        ref={formRef}
        className="space-y-4"
        noValidate
        onSubmit={(event) => {
          event.preventDefault();
          const error = execute({ kind: "cancel", id: appointment.id, reason });
          if (error) fail(error);
          else onSaved();
        }}
      >
        <p className="font-medium text-gray-900 dark:text-white">
          {appointment.id} · {appointment.customerName}
        </p>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          {t("spa.flow.cancelHint")}
        </p>
        <SpaFormError error={error} />
        <div>
          <Label htmlFor="spa-cancel-reason">
            {t("spa.flow.cancelReason")}
          </Label>
          <TextArea
            id="spa-cancel-reason"
            placeholder={t("spa.flow.cancelReason")}
            value={reason}
            onChange={(value) => {
              setReason(value);
              setError(undefined);
            }}
            required
            error={error === "cancelReasonRequired"}
            className={spaFieldClasses}
          />
        </div>
        <div className="flex flex-wrap justify-end gap-3">
          <Button type="button" variant="outline" onClick={close}>
            {t("spa.flow.keepBooking")}
          </Button>
          <Button type="submit" variant="destructive">
            {t("spa.flow.confirmCancel")}
          </Button>
        </div>
      </form>
    </SpaDialog>
  );
}
