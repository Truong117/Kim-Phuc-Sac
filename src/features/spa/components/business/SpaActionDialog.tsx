import { useState } from "react";
import { useTranslation } from "react-i18next";
import KpsDatePicker from "@/components/common/KpsDatePicker";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import TextArea from "@/components/form/input/TextArea";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import useSpaForm from "../../hooks/useSpaForm";
import type { SpaPreviewError } from "../../types";
import { spaFieldClasses } from "../../config";
import SpaDialog from "../SpaDialog";
import SpaFormError from "../SpaFormError";
import { SpaHint } from "./SpaBusinessUi";
export interface SpaFormField {
  key: string;
  label: string;
  type?: "text" | "number" | "date" | "time" | "textarea" | "select";
  options?: { value: string; label: string }[];
  errors?: SpaPreviewError[];
  min?: number;
  max?: number;
  step?: number;
  required?: boolean;
}
export interface SpaActionForm {
  title: string;
  hint?: string;
  fields: SpaFormField[];
  initial: Record<string, string>;
  submit: (values: Record<string, string>) => SpaPreviewError | undefined;
}
export default function SpaActionDialog({
  form,
  onClose,
}: {
  form: SpaActionForm;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const [values, setValues] = useState(form.initial);
  const {
    ref: formRef,
    error,
    setError,
    fail,
    close,
  } = useSpaForm(
    JSON.stringify(values) !== JSON.stringify(form.initial),
    onClose,
  );
  const update = (key: string, value: string) => {
    setValues((s) => ({ ...s, [key]: value }));
    setError(undefined);
  };
  return (
    <SpaDialog title={form.title} onClose={close}>
      <form
        ref={formRef}
        noValidate
        className="space-y-4"
        onSubmit={(e) => {
          e.preventDefault();
          const missing = form.fields.find(
            (f) =>
              f.required !== false &&
              f.errors?.length &&
              !values[f.key]?.trim(),
          );
          if (missing?.errors?.[0]) {
            fail(missing.errors[0]);
            return;
          }
          const result = form.submit(values);
          if (result) fail(result);
          else onClose();
        }}
      >
        <SpaFormError
          error={error}
          messages={{
            nameRequired: t("spa.business.nameInvalid"),
            timeInvalid: t("spa.business.timeInvalid"),
            noteTooLong: t("spa.business.contentTooLong"),
          }}
        />
        {form.hint && <SpaHint>{form.hint}</SpaHint>}
        {form.fields.map((f) => {
          const id = `spa-action-${f.key}`,
            invalid = Boolean(error && f.errors?.includes(error));
          return (
            <div key={f.key}>
              <Label htmlFor={id}>{f.label}</Label>
              {f.type === "select" ? (
                <Select
                  id={id}
                  placeholder={t("spa.business.choose")}
                  value={values[f.key] ?? ""}
                  options={f.options ?? []}
                  onChange={(v) => update(f.key, v)}
                  className={spaFieldClasses}
                  error={invalid}
                  required={f.required ?? true}
                />
              ) : f.type === "textarea" ? (
                <TextArea
                  id={id}
                  placeholder={f.label}
                  value={values[f.key] ?? ""}
                  onChange={(v) => update(f.key, v)}
                  className={spaFieldClasses}
                  error={invalid}
                />
              ) : f.type === "date" ? (
                <KpsDatePicker
                  id={id}
                  value={values[f.key] ?? ""}
                  onChange={(v) => update(f.key, v)}
                  ariaLabel={f.label}
                  placeholder={f.label}
                  error={invalid}
                  required
                />
              ) : (
                <Input
                  id={id}
                  type={f.type ?? "text"}
                  value={values[f.key] ?? ""}
                  onInput={(e) => update(f.key, e.currentTarget.value)}
                  min={f.min}
                  max={f.max}
                  step={f.step}
                  required={f.required ?? true}
                  error={invalid}
                  aria-invalid={invalid}
                  className={spaFieldClasses}
                />
              )}
            </div>
          );
        })}
        <div className="flex flex-wrap justify-end gap-3">
          <Button type="button" variant="outline" onClick={close}>
            {t("spa.business.cancel")}
          </Button>
          <Button
            type="submit"
            className="!bg-kps-primary hover:!bg-kps-primary-hover"
          >
            {t("spa.business.save")}
          </Button>
        </div>
      </form>
    </SpaDialog>
  );
}
