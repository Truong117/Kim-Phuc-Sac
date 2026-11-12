import { spaFieldClasses } from "@/features/spa/config";
import { useState } from "react";
import { useTranslation } from "react-i18next";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import TextArea from "@/components/form/input/TextArea";
import Button from "@/components/ui/button/Button";
import { useSpaPreview } from "../hooks/useSpaPreviewSnapshot";
import useSpaForm from "../hooks/useSpaForm";
import type { SpaCustomer } from "../types";
import SpaDialog from "./SpaDialog";
import SpaFormError from "./SpaFormError";

export default function SpaCustomerDialog({
  customer,
  onClose,
  onSaved,
}: {
  customer?: SpaCustomer;
  onClose: () => void;
  onSaved: (id: string) => void;
}) {
  const { t } = useTranslation();
  const { execute } = useSpaPreview();
  const initial = {
    name: customer?.name ?? "",
    phone: customer?.phone ?? "",
    note: customer?.note ?? "",
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
      title={t(customer ? "spa.flow.editCustomer" : "spa.flow.newCustomer")}
      onClose={close}
    >
      <form
        ref={formRef}
        noValidate
        className="space-y-4"
        onSubmit={(event) => {
          event.preventDefault();
          const id = customer?.id ?? crypto.randomUUID();
          const error = execute({ kind: "customer", id, customer: values });
          if (error) fail(error);
          else onSaved(id);
        }}
      >
        <SpaFormError error={error} />
        <div>
          <Label htmlFor="spa-customer-name">
            {t("spa.flow.customerName")}
          </Label>
          <Input
            id="spa-customer-name"
            value={values.name}
            onChange={(event) => update("name", event.target.value)}
            maxLength={100}
            required
            className={spaFieldClasses}
            aria-invalid={error === "nameRequired"}
            error={error === "nameRequired"}
          />
        </div>
        <div>
          <Label htmlFor="spa-customer-phone">{t("spa.flow.phone")}</Label>
          <Input
            id="spa-customer-phone"
            type="tel"
            value={values.phone}
            onChange={(event) => update("phone", event.target.value)}
            maxLength={24}
            required
            className={spaFieldClasses}
            aria-invalid={
              error === "phoneInvalid" || error === "phoneDuplicate"
            }
            error={error === "phoneInvalid" || error === "phoneDuplicate"}
          />
        </div>
        <div>
          <Label htmlFor="spa-customer-note">
            {t("spa.flow.customerNote")}
          </Label>
          <TextArea
            id="spa-customer-note"
            placeholder={t("spa.flow.optionalNote")}
            value={values.note}
            onChange={(value) => update("note", value)}
            className={spaFieldClasses}
            error={error === "noteTooLong"}
          />
        </div>
        <div className="flex flex-wrap justify-end gap-3">
          <Button type="button" variant="outline" onClick={close}>
            {t("spa.flow.back")}
          </Button>
          <Button
            type="submit"
            className="!bg-kps-primary hover:!bg-kps-primary-hover"
          >
            {t("spa.flow.saveCustomer")}
          </Button>
        </div>
      </form>
    </SpaDialog>
  );
}
