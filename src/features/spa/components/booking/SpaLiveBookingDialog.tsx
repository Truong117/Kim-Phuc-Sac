import { useId, useState } from "react";
import { useTranslation } from "react-i18next";
import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import Button from "@/components/ui/button/Button";
import { isDateOnly } from "@/utils/dateOnly";
import { spaFieldClasses } from "../../config";
import { normalizeSpaPhone } from "../../customerValidation";
import useSpaLiveForm from "../../hooks/useSpaLiveForm";
import {
  createSpaAppointment,
  type SpaBookingServiceOption,
  type SpaSavedAppointment,
} from "../../services/spaBookingService";
import SpaDialog from "../SpaDialog";
import { SpaAction } from "../business/SpaBusinessUi";
import SpaLiveBookingServiceField from "./SpaLiveBookingServiceField";
import SpaLiveBookingTimeFields from "./SpaLiveBookingTimeFields";
import SpaLiveFormError from "./SpaLiveFormError";

import SpaLiveBookingCustomerFields, {
  type SpaBookingCustomerChoice,
} from "./SpaLiveBookingCustomerFields";

export default function SpaLiveBookingDialog({
  initialDate,
  canCreateCustomer,
  onClose,
  onSaved,
}: {
  initialDate: string;
  canCreateCustomer: boolean;
  onClose: () => void;
  onSaved: (value: SpaSavedAppointment) => void;
}) {
  const { t } = useTranslation();
  const fieldId = useId();
  const [requestId] = useState(() => crypto.randomUUID());
  const [date, setDate] = useState(initialDate);
  const [time, setTime] = useState("");
  const [customer, setCustomer] = useState<SpaBookingCustomerChoice>({
    mode: "existing",
    selected: null,
    name: "",
    phone: "",
    duplicate: null,
  });
  const [service, setService] = useState<SpaBookingServiceOption | null>(null);
  const [note, setNote] = useState("");
  const { formRef, error, saving, clear, fail, save, close } = useSpaLiveForm(
    Boolean(
      customer.selected ||
      customer.name ||
      customer.phone ||
      time ||
      service ||
      note ||
      date !== initialDate,
    ),
    onClose,
  );
  const invalid = error?.fields ?? [];
  return (
    <SpaDialog
      title={t("spa.live.newBooking")}
      description={t("spa.live.bookingFormDescription")}
      onClose={close}
    >
      <form
        ref={formRef}
        className="space-y-5"
        noValidate
        onSubmit={(event) => {
          event.preventDefault();
          const createInline = customer.mode === "new" && canCreateCustomer;
          const customerId =
            customer.mode === "existing" ? customer.selected?.id : undefined;
          const phone = normalizeSpaPhone(customer.phone);
          if (!customerId && !createInline) {
            fail("lookupRequired", "customer_id");
            return;
          }
          if (createInline && !customer.name.trim()) {
            fail("customerNameInvalid", "customer.name");
            return;
          }
          if (createInline && !/^\d{10,15}$/.test(phone)) {
            fail("phoneInvalid", "customer.phone");
            return;
          }
          if (createInline && customer.duplicate) {
            fail("phoneDuplicate", "customer.phone");
            return;
          }
          if (!service) {
            fail("serviceRequired", "service_id");
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
              createSpaAppointment({
                id: requestId,
                ...(customerId
                  ? { customer_id: customerId }
                  : {
                      customer: {
                        name: customer.name.trim(),
                        phone,
                        note: "",
                      },
                    }),
                service_id: service.id,
                date,
                start_time: time,
                note: note.trim(),
              }),
            onSaved,
          );
        }}
      >
        <SpaLiveFormError error={error?.key} />
        <fieldset disabled={saving} className="min-w-0 space-y-5">
          <SpaLiveBookingCustomerFields
            value={customer}
            onChange={(changes) => {
              setCustomer((current) => ({ ...current, ...changes }));
              clear();
            }}
            canCreate={canCreateCustomer}
            invalid={invalid}
            fail={fail}
          />
          <SpaLiveBookingServiceField
            selected={service}
            onSelect={(value) => {
              setService(value);
              clear();
            }}
            invalid={invalid.includes("service_id")}
          />
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
          <div>
            <Label htmlFor={`${fieldId}-note`}>
              {t("spa.flow.bookingNote")}
            </Label>
            <TextArea
              id={`${fieldId}-note`}
              maxLength={1000}
              value={note}
              placeholder={t("spa.flow.optionalNote")}
              onChange={(value) => {
                setNote(value);
                clear();
              }}
              error={invalid.includes("note")}
              className={spaFieldClasses}
            />
          </div>
        </fieldset>
        <div className="flex flex-wrap justify-end gap-2">
          <SpaAction secondary onClick={close} disabled={saving}>
            {t("spa.flow.back")}
          </SpaAction>
          <Button
            type="submit"
            size="sm"
            disabled={saving}
            className="!bg-kps-primary hover:!bg-kps-primary-hover"
          >
            {t(saving ? "spa.live.saving" : "spa.live.saveBooking")}
          </Button>
        </div>
      </form>
    </SpaDialog>
  );
}
