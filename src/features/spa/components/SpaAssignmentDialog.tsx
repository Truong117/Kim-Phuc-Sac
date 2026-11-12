import { spaFieldClasses } from "@/features/spa/config";
import { useState } from "react";
import { useTranslation } from "react-i18next";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import Input from "@/components/form/input/InputField";
import Button from "@/components/ui/button/Button";
import useSpaForm from "../hooks/useSpaForm";
import { useSpaPreview } from "../hooks/useSpaPreviewSnapshot";
import type { SpaAppointment, SpaServiceTask } from "../types";
import SpaDialog from "./SpaDialog";
import SpaFormError from "./SpaFormError";

export default function SpaAssignmentDialog({
  appointment,
  task,
  onClose,
  onSaved,
}: {
  appointment: SpaAppointment;
  task: SpaServiceTask;
  onClose: () => void;
  onSaved: () => void;
}) {
  const { t } = useTranslation();
  const { snapshot, execute } = useSpaPreview();
  const initial = {
    technicianId: task.technicianId,
    bedId: task.bedId ?? "",
    startTime: task.startTime,
  };
  const [values, setValues] = useState(initial);
  const {
    ref: formRef,
    error,
    setError,
    fail,
    close,
  } = useSpaForm(JSON.stringify(values) !== JSON.stringify(initial), onClose);
  const update = (key: keyof typeof values, value: string) => {
    setValues((current) => ({ ...current, [key]: value }));
    setError(undefined);
  };
  return (
    <SpaDialog
      title={t(
        task.status === "assigned" ? "spa.flow.reassign" : "spa.flow.assign",
      )}
      onClose={close}
    >
      <form
        ref={formRef}
        className="space-y-5"
        noValidate
        onSubmit={(event) => {
          event.preventDefault();
          const error = execute({
            kind: "assign",
            id: appointment.id,
            taskId: task.id,
            ...values,
          });
          if (error) fail(error);
          else onSaved();
        }}
      >
        <div className="rounded-xl bg-gray-50 p-4 text-sm text-gray-700 dark:bg-gray-800 dark:text-gray-200">
          <p className="font-semibold">
            {appointment.customerName} · {appointment.id}
          </p>
          <p className="mt-2">
            {t(`spa.services.${task.serviceKey}`)} ·{" "}
            {t("spa.minutes", { count: task.durationMinutes })}
          </p>
          <p className="mt-2">
            {appointment.date} ·{" "}
            {t("spa.flow.receivedBy", {
              name: appointment.receivedBy?.name ?? t("spa.flow.unknownActor"),
            })}
          </p>
        </div>
        <SpaFormError error={error} />
        <div>
          <Label htmlFor="spa-assign-technician">
            {t("spa.flow.technician")}
          </Label>
          <Select
            id="spa-assign-technician"
            placeholder={t("spa.flow.chooseTechnician")}
            value={values.technicianId}
            onChange={(value) => update("technicianId", value)}
            options={snapshot.technicians.map((item) => ({
              value: item.id,
              label: item.name,
            }))}
            required
            className={spaFieldClasses}
            error={
              error === "technicianRequired" || error === "technicianConflict"
            }
          />
        </div>
        <div>
          <Label htmlFor="spa-assign-bed">{t("spa.flow.bed")}</Label>
          <Select
            id="spa-assign-bed"
            placeholder={t("spa.flow.chooseBed")}
            value={values.bedId}
            onChange={(value) => update("bedId", value)}
            options={snapshot.beds.map((item) => ({
              value: item.id,
              label: t("spa.flow.bedLabel", {
                room: item.room,
                bed: item.number,
              }),
            }))}
            required
            className={spaFieldClasses}
            error={error === "bedRequired" || error === "bedConflict"}
          />
        </div>
        <div>
          <Label htmlFor="spa-assign-time">
            {t("spa.flow.assignmentTime")}
          </Label>
          <Input
            id="spa-assign-time"
            type="time"
            value={values.startTime}
            onInput={(event) => update("startTime", event.currentTarget.value)}
            className={spaFieldClasses}
            required
            error={error === "timeInvalid" || error === "customerConflict"}
            aria-invalid={
              error === "timeInvalid" || error === "customerConflict"
            }
          />
        </div>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          {t("spa.flow.conflictHint")}
        </p>
        <div className="flex flex-wrap justify-end gap-3">
          <Button type="button" variant="outline" onClick={close}>
            {t("spa.flow.back")}
          </Button>
          <Button
            type="submit"
            className="!bg-kps-primary hover:!bg-kps-primary-hover"
          >
            {t("spa.flow.saveAssignment")}
          </Button>
        </div>
      </form>
    </SpaDialog>
  );
}
