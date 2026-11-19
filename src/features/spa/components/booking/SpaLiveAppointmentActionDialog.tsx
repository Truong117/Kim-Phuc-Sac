import { useId, useState } from "react";
import { useTranslation } from "react-i18next";
import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import Button from "@/components/ui/button/Button";
import { isDateOnly } from "@/utils/dateOnly";
import { spaFieldClasses } from "../../config";
import useSpaLiveForm from "../../hooks/useSpaLiveForm";
import {
  cancelSpaAppointment,
  rescheduleSpaAppointment,
  type SpaSavedAppointment,
} from "../../services/spaBookingService";
import SpaDialog from "../SpaDialog";
import { SpaAction } from "../business/SpaBusinessUi";
import SpaLiveBookingTimeFields from "./SpaLiveBookingTimeFields";
import SpaLiveFormError from "./SpaLiveFormError";

export default function SpaLiveAppointmentActionDialog({
  action,
  appointment,
  onClose,
  onSaved,
}: {
  action: "reschedule" | "cancel";
  appointment: SpaSavedAppointment;
  onClose: () => void;
  onSaved: (value: SpaSavedAppointment) => void;
}) {
  const { t } = useTranslation();
  const reasonId = useId();
  const [date, setDate] = useState(appointment.date);
  const [time, setTime] = useState(appointment.start_time);
  const [reason, setReason] = useState("");
  const { formRef, error, saving, clear, fail, save, close } = useSpaLiveForm(
    date !== appointment.date ||
      time !== appointment.start_time ||
      Boolean(reason),
    onClose,
  );
  const isCancel = action === "cancel";
  const invalid = error?.fields ?? [];
  return (
    <SpaDialog
      title={t(isCancel ? "spa.flow.cancelBooking" : "spa.live.reschedule")}
      description={t(
        isCancel
          ? "spa.live.cancelDescription"
          : "spa.live.rescheduleDescription",
      )}
      onClose={close}
    >
      <form
        ref={formRef}
        className="space-y-5"
        noValidate
        onSubmit={(event) => {
          event.preventDefault();
          if (isCancel) {
            if (!reason.trim()) {
              fail("cancelReasonRequired", "reason");
              return;
            }
            void save(
              () =>
                cancelSpaAppointment(appointment.id, {
                  version: appointment.version,
                  reason: reason.trim(),
                }),
              onSaved,
            );
            return;
          }
          if (!isDateOnly(date) || !time) {
            fail(
              "dateTimeRequired",
              ...(!isDateOnly(date) ? ["date"] : []),
              ...(!time ? ["start_time"] : []),
            );
            return;
          }
          void save(
            () =>
              rescheduleSpaAppointment(appointment.id, {
                version: appointment.version,
                date,
                start_time: time,
              }),
            onSaved,
          );
        }}
      >
        <SpaLiveFormError error={error?.key} />
        <fieldset disabled={saving} className="min-w-0 space-y-4">
          {isCancel ? (
            <div>
              <Label htmlFor={reasonId}>{t("spa.flow.cancelReason")}</Label>
              <TextArea
                id={reasonId}
                value={reason}
                onChange={(value) => {
                  setReason(value);
                  clear();
                }}
                placeholder={t("spa.flow.cancelReason")}
                maxLength={500}
                className={spaFieldClasses}
                error={invalid.includes("reason")}
                required
              />
            </div>
          ) : (
            <SpaLiveBookingTimeFields
              date={date}
              time={time}
              onDate={(value) => {
                setDate(value);
                clear();
              }}
              onTime={(value) => {
                setTime(value);
                clear();
              }}
              invalid={invalid}
            />
          )}
        </fieldset>
        <div className="flex flex-wrap justify-end gap-2">
          <SpaAction secondary onClick={close} disabled={saving}>
            {t(isCancel ? "spa.flow.keepBooking" : "spa.flow.back")}
          </SpaAction>
          <Button
            type="submit"
            size="sm"
            disabled={
              saving ||
              (!isCancel &&
                date === appointment.date &&
                time === appointment.start_time)
            }
            className={
              isCancel
                ? "!bg-error-600 hover:!bg-error-700"
                : "!bg-kps-primary hover:!bg-kps-primary-hover"
            }
          >
            {t(
              saving
                ? "spa.live.saving"
                : isCancel
                  ? "spa.live.confirmCancel"
                  : "spa.live.saveChanges",
            )}
          </Button>
        </div>
      </form>
    </SpaDialog>
  );
}
