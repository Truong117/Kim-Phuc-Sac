import { useId, useState } from "react";
import { useTranslation } from "react-i18next";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import TextArea from "@/components/form/input/TextArea";
import Button from "@/components/ui/button/Button";
import { spaFieldClasses } from "../../config";
import { normalizeSpaPhone } from "../../customerValidation";
import useSpaLiveForm from "../../hooks/useSpaLiveForm";
import {
  createSpaCustomer,
  updateSpaCustomer,
  type SpaSavedCustomer,
} from "../../services/spaBookingService";
import SpaDialog from "../SpaDialog";

interface SpaLiveCustomerDialogProps {
  customer?: SpaSavedCustomer;
  onClose: () => void;
  onSaved: (customer: SpaSavedCustomer) => void;
}

type CustomerFields = Pick<SpaSavedCustomer, "name" | "phone" | "note">;

export default function SpaLiveCustomerDialog({
  customer,
  onClose,
  onSaved,
}: SpaLiveCustomerDialogProps) {
  const { t } = useTranslation();
  const id = useId();
  const initial = {
    name: customer?.name ?? "",
    phone: customer?.phone ?? "",
    note: customer?.note ?? "",
  };
  const [values, setValues] = useState(initial);
  const { formRef, close, clear, fail, save, saving, error } = useSpaLiveForm(
    values.name !== initial.name ||
      values.phone !== initial.phone ||
      values.note !== initial.note,
    onClose,
  );
  const update = (key: keyof CustomerFields, value: string) => {
    setValues((current) => ({ ...current, [key]: value }));
    clear();
  };

  const fieldErrors = {
    name: error?.fields.includes("name")
      ? t("spa.live.customerNameInvalid")
      : undefined,
    phone: error?.fields.includes("phone")
      ? t(
          error.key === "phoneInvalid"
            ? "spa.live.phoneInvalid"
            : "spa.live.phoneDuplicate",
        )
      : undefined,
    note: error?.fields.includes("note")
      ? t("spa.flow.errors.noteTooLong")
      : undefined,
  };

  const submit = () => {
    const input = {
      name: values.name.trim(),
      phone: normalizeSpaPhone(values.phone),
      note: values.note.trim(),
    };
    if (!input.name || input.name.length > 100)
      return fail("customerNameInvalid", "name");
    if (!/^\d{10,15}$/.test(input.phone)) return fail("phoneInvalid", "phone");
    if (input.note.length > 1000) return fail("validationError", "note");
    void save(
      () =>
        customer
          ? updateSpaCustomer(customer.id, {
              ...input,
              version: customer.version,
            })
          : createSpaCustomer(input),
      onSaved,
    );
  };

  return (
    <SpaDialog
      title={t(customer ? "spa.flow.editCustomer" : "spa.live.newCustomer")}
      description={t("spa.live.customerFormDescription")}
      onClose={close}
    >
      <form
        ref={formRef}
        noValidate
        aria-busy={saving}
        className="space-y-4"
        onSubmit={(event) => {
          event.preventDefault();
          submit();
        }}
      >
        {error && (
          <p
            role="alert"
            className="text-sm text-error-700 dark:text-error-400"
          >
            {t(`spa.live.${error.key}`)}
          </p>
        )}
        <div className="grid gap-4 sm:grid-cols-2">
          <div>
            <Label htmlFor={`${id}-name`}>{t("spa.flow.customerName")}</Label>
            <Input
              id={`${id}-name`}
              autoComplete="off"
              value={values.name}
              onChange={(event) => update("name", event.target.value)}
              maxLength={100}
              required
              disabled={saving}
              aria-invalid={Boolean(fieldErrors.name)}
              error={Boolean(fieldErrors.name)}
              hint={fieldErrors.name}
              className={spaFieldClasses}
            />
          </div>
          <div>
            <Label htmlFor={`${id}-phone`}>{t("spa.concept.phone")}</Label>
            <Input
              id={`${id}-phone`}
              type="tel"
              autoComplete="off"
              value={values.phone}
              onChange={(event) => update("phone", event.target.value)}
              maxLength={24}
              required
              disabled={saving}
              aria-invalid={Boolean(fieldErrors.phone)}
              error={Boolean(fieldErrors.phone)}
              hint={fieldErrors.phone}
              className={spaFieldClasses}
            />
          </div>
        </div>
        <div>
          <Label htmlFor={`${id}-note`}>{t("spa.flow.customerNote")}</Label>
          <TextArea
            id={`${id}-note`}
            value={values.note}
            onChange={(value) => update("note", value)}
            placeholder={t("spa.flow.optionalNote")}
            maxLength={1000}
            disabled={saving}
            error={Boolean(fieldErrors.note)}
            hint={fieldErrors.note}
            className={spaFieldClasses}
          />
        </div>
        <div className="flex flex-wrap justify-end gap-2">
          <Button
            type="button"
            variant="outline"
            disabled={saving}
            onClick={close}
          >
            {t("spa.flow.back")}
          </Button>
          <Button
            type="submit"
            disabled={saving}
            className="!bg-kps-primary hover:!bg-kps-primary-hover"
          >
            {t(saving ? "spa.live.saving" : "spa.live.saveCustomer")}
          </Button>
        </div>
      </form>
    </SpaDialog>
  );
}
