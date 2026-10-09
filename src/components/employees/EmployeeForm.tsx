import ComponentCard from "@/components/common/ComponentCard";
import Select from "@/components/form/Select";
import Form from "@/components/form/Form";
import Input from "@/components/form/input/InputField";
import Label from "@/components/form/Label";
import Button from "@/components/ui/button/Button";
import type { ValidationErrors } from "@/services/apiClient";
import type { EmployeeFormValues, ReferenceItem } from "@/types/userManagement";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { useNavigate } from "react-router";

type EmployeeFormMode = "create" | "edit";

type ClientErrors = Partial<Record<keyof EmployeeFormValues, string>>;

interface EmployeeFormProps {
  mode: EmployeeFormMode;
  initialValues?: EmployeeFormValues;
  departments: ReferenceItem[];
  locations: ReferenceItem[];
  roles: ReferenceItem[];
  isSubmitting: boolean;
  serverErrors?: ValidationErrors;
  requestError?: string | null;
  successMessage?: string | null;
  onInteraction?: () => void;
  onDirtyChange?: (isDirty: boolean) => void;
  onSubmit: (values: EmployeeFormValues) => Promise<boolean>;
}

const emptyValues: EmployeeFormValues = {
  name: "",
  email: "",
  password: "",
  passwordConfirmation: "",
  departmentId: "",
  locationId: "",
  roleId: "",
};

const kpsFocusClasses =
  "focus:!border-kps-primary focus:!ring-kps-primary/20 dark:focus:!border-kps-primary dark:focus:!ring-kps-primary/30";

const toOptions = (items: ReferenceItem[]) =>
  items.map((item) => ({ value: String(item.id), label: item.name }));

const firstServerError = (
  errors: ValidationErrors | undefined,
  field: string,
) => errors?.[field]?.[0];

const profileSignature = (values: EmployeeFormValues) =>
  JSON.stringify({
    name: values.name.trim(),
    email: values.email.trim().toLocaleLowerCase("en-US"),
    departmentId: values.departmentId,
    locationId: values.locationId,
  });

export default function EmployeeForm({
  mode,
  initialValues,
  departments,
  locations,
  roles,
  isSubmitting,
  serverErrors,
  requestError,
  successMessage,
  onInteraction,
  onDirtyChange,
  onSubmit,
}: EmployeeFormProps) {
  const { t } = useTranslation("common", {
    keyPrefix: "userManagement.form",
  });
  const navigate = useNavigate();
  const [values, setValues] = useState<EmployeeFormValues>(
    initialValues ?? emptyValues,
  );
  const [savedProfileSignature, setSavedProfileSignature] = useState(() =>
    profileSignature(initialValues ?? emptyValues),
  );
  const [clientErrors, setClientErrors] = useState<ClientErrors>({});
  const isProfileDirty =
    mode === "edit" && profileSignature(values) !== savedProfileSignature;

  useEffect(() => {
    onDirtyChange?.(isProfileDirty);
  }, [isProfileDirty, onDirtyChange]);

  useEffect(
    () => () => {
      onDirtyChange?.(false);
    },
    [onDirtyChange],
  );

  const setField = <Key extends keyof EmployeeFormValues>(
    field: Key,
    value: EmployeeFormValues[Key],
  ) => {
    setValues((current) => ({ ...current, [field]: value }));
    setClientErrors((current) => ({ ...current, [field]: undefined }));
    onInteraction?.();
  };

  const validate = () => {
    const errors: ClientErrors = {};
    const normalizedEmail = values.email.trim();

    if (!values.name.trim()) errors.name = t("validation.nameRequired");
    if (!normalizedEmail) {
      errors.email = t("validation.emailRequired");
    } else if (!/^\S+@\S+\.\S+$/.test(normalizedEmail)) {
      errors.email = t("validation.emailInvalid");
    }

    if (mode === "create") {
      if (!values.password) {
        errors.password = t("validation.passwordRequired");
      } else if (values.password.length < 8 || values.password.length > 72) {
        errors.password = t("validation.passwordLength");
      }

      if (!values.passwordConfirmation) {
        errors.passwordConfirmation = t(
          "validation.passwordConfirmationRequired",
        );
      } else if (values.passwordConfirmation !== values.password) {
        errors.passwordConfirmation = t("validation.passwordMismatch");
      }

      if (!values.roleId) errors.roleId = t("validation.roleRequired");
    }

    setClientErrors(errors);
    return Object.keys(errors).length === 0;
  };

  const handleSubmit = async () => {
    if (mode === "edit" && !isProfileDirty) return;
    if (!validate()) return;

    const normalizedValues = {
      ...values,
      name: values.name.trim(),
      email: values.email.trim().toLocaleLowerCase("en-US"),
    };
    const wasSaved = await onSubmit(normalizedValues);

    if (mode === "edit" && wasSaved) {
      setSavedProfileSignature(profileSignature(normalizedValues));
    }
  };

  const nameError = clientErrors.name ?? firstServerError(serverErrors, "name");
  const emailError =
    clientErrors.email ?? firstServerError(serverErrors, "email");
  const departmentError = firstServerError(serverErrors, "department_id");
  const locationError = firstServerError(serverErrors, "location_id");
  const passwordError =
    clientErrors.password ?? firstServerError(serverErrors, "password");
  const passwordConfirmationError =
    clientErrors.passwordConfirmation ??
    firstServerError(serverErrors, "password_confirmation");
  const roleError =
    clientErrors.roleId ?? firstServerError(serverErrors, "role_id");

  return (
    <Form onSubmit={() => void handleSubmit()} className="space-y-6">
      <ComponentCard
        title={t("profileTitle")}
        desc={t("profileDescription")}
        compact={mode === "edit"}
      >
        <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
          <div>
            <Label htmlFor="employee-name">
              {t("name.label")} <span aria-hidden="true">*</span>
            </Label>
            <Input
              id="employee-name"
              name="name"
              value={values.name}
              placeholder={t("name.placeholder")}
              autoComplete="name"
              maxLength={255}
              required
              disabled={isSubmitting}
              error={Boolean(nameError)}
              hint={nameError}
              className={nameError ? "" : kpsFocusClasses}
              onChange={(event) => setField("name", event.target.value)}
            />
          </div>

          <div>
            <Label htmlFor="employee-email">
              {t("email.label")} <span aria-hidden="true">*</span>
            </Label>
            <Input
              id="employee-email"
              name="email"
              type="email"
              value={values.email}
              placeholder={t("email.placeholder")}
              autoComplete="email"
              maxLength={255}
              required
              disabled={isSubmitting}
              error={Boolean(emailError)}
              hint={emailError}
              className={emailError ? "" : kpsFocusClasses}
              onChange={(event) => setField("email", event.target.value)}
            />
          </div>

          <div>
            <Label htmlFor="employee-department">{t("department.label")}</Label>
            <Select
              id="employee-department"
              name="department_id"
              options={toOptions(departments)}
              value={values.departmentId}
              placeholder={t("department.placeholder")}
              allowClear
              disabled={isSubmitting}
              error={Boolean(departmentError)}
              hint={departmentError}
              className={departmentError ? "" : kpsFocusClasses}
              onChange={(value) => setField("departmentId", value)}
            />
          </div>

          <div>
            <Label htmlFor="employee-location">{t("location.label")}</Label>
            <Select
              id="employee-location"
              name="location_id"
              options={toOptions(locations)}
              value={values.locationId}
              placeholder={
                locations.length === 0
                  ? t("location.empty")
                  : t("location.placeholder")
              }
              allowClear
              disabled={isSubmitting || locations.length === 0}
              error={Boolean(locationError)}
              hint={locationError}
              className={locationError ? "" : kpsFocusClasses}
              onChange={(value) => setField("locationId", value)}
            />
          </div>
        </div>

        {mode === "edit" && (
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div className="min-w-0 flex-1">
              {requestError && (
                <p
                  role="alert"
                  className="inline-flex rounded-lg bg-error-50 px-3 py-2 text-theme-sm text-error-700 dark:bg-error-500/10 dark:text-error-400"
                >
                  {requestError}
                </p>
              )}
              {successMessage && (
                <p
                  role="status"
                  className="inline-flex rounded-lg bg-success-50 px-3 py-2 text-theme-sm text-success-700 dark:bg-success-500/10 dark:text-success-400"
                >
                  {successMessage}
                </p>
              )}
            </div>
            <Button
              type="submit"
              className="w-full !bg-kps-primary hover:!bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none disabled:!bg-kps-primary sm:w-auto"
              disabled={isSubmitting || !isProfileDirty}
            >
              {isSubmitting ? t("submitting") : t("save")}
            </Button>
          </div>
        )}
      </ComponentCard>

      {mode === "create" && (
        <>
          <ComponentCard
            title={t("passwordTitle")}
            desc={t("passwordDescription")}
          >
            <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
              <div>
                <Label htmlFor="employee-password">
                  {t("password.label")} <span aria-hidden="true">*</span>
                </Label>
                <Input
                  id="employee-password"
                  name="password"
                  type="password"
                  value={values.password}
                  placeholder={t("password.placeholder")}
                  autoComplete="new-password"
                  showPasswordToggle
                  minLength={8}
                  maxLength={72}
                  required
                  disabled={isSubmitting}
                  error={Boolean(passwordError)}
                  hint={passwordError}
                  className={passwordError ? "" : kpsFocusClasses}
                  onChange={(event) => setField("password", event.target.value)}
                />
              </div>

              <div>
                <Label htmlFor="employee-password-confirmation">
                  {t("passwordConfirmation.label")}{" "}
                  <span aria-hidden="true">*</span>
                </Label>
                <Input
                  id="employee-password-confirmation"
                  name="password_confirmation"
                  type="password"
                  value={values.passwordConfirmation}
                  placeholder={t("passwordConfirmation.placeholder")}
                  autoComplete="new-password"
                  showPasswordToggle
                  minLength={8}
                  maxLength={72}
                  required
                  disabled={isSubmitting}
                  error={Boolean(passwordConfirmationError)}
                  hint={passwordConfirmationError}
                  className={passwordConfirmationError ? "" : kpsFocusClasses}
                  onChange={(event) =>
                    setField("passwordConfirmation", event.target.value)
                  }
                />
              </div>
            </div>
          </ComponentCard>

          <ComponentCard title={t("roleTitle")} desc={t("roleDescription")}>
            <div className="max-w-xl">
              <Label htmlFor="employee-role">
                {t("role.label")} <span aria-hidden="true">*</span>
              </Label>
              <Select
                id="employee-role"
                name="role_id"
                options={toOptions(roles)}
                value={values.roleId}
                placeholder={t("role.placeholder")}
                required
                disabled={isSubmitting}
                error={Boolean(roleError)}
                hint={roleError}
                className={roleError ? "" : kpsFocusClasses}
                onChange={(value) => setField("roleId", value)}
              />
            </div>
          </ComponentCard>
        </>
      )}

      {mode === "create" && requestError && (
        <div
          role="alert"
          className="rounded-lg border border-error-200 bg-error-50 px-4 py-3 text-theme-sm text-error-700 dark:border-error-800 dark:bg-error-500/10 dark:text-error-400"
        >
          {requestError}
        </div>
      )}
      {mode === "create" && successMessage && (
        <div
          role="status"
          className="rounded-lg border border-success-200 bg-success-50 px-4 py-3 text-theme-sm text-success-700 dark:border-success-800 dark:bg-success-500/10 dark:text-success-400"
        >
          {successMessage}
        </div>
      )}

      {mode === "create" && (
        <div className="flex flex-col-reverse gap-3 rounded-2xl border border-gray-200 bg-white p-5 sm:flex-row sm:justify-end dark:border-gray-800 dark:bg-white/3">
          <Button
            type="button"
            variant="outline"
            className="w-full sm:w-auto"
            disabled={isSubmitting}
            onClick={() => navigate("/employees")}
          >
            {t("cancel")}
          </Button>
          <Button
            type="submit"
            className="w-full !bg-kps-primary hover:!bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none disabled:!bg-kps-primary sm:w-auto"
            disabled={isSubmitting}
          >
            {isSubmitting ? t("submitting") : t("create")}
          </Button>
        </div>
      )}
    </Form>
  );
}
