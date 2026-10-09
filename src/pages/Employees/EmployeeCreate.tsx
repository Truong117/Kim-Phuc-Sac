import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import EmployeeForm from "@/components/employees/EmployeeForm";
import Button from "@/components/ui/button/Button";
import { ApiServiceError, type ValidationErrors } from "@/services/apiClient";
import { createUser, getUserReferences } from "@/services/userService";
import type {
  EmployeeFormValues,
  UserReferences,
} from "@/types/userManagement";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { useNavigate } from "react-router";

const initialReferences: UserReferences = {
  roles: [],
  departments: [],
  locations: [],
};

const isAbortError = (error: unknown) =>
  error instanceof DOMException && error.name === "AbortError";

export default function EmployeeCreate() {
  const { t } = useTranslation("common", { keyPrefix: "userManagement" });
  const navigate = useNavigate();
  const [references, setReferences] =
    useState<UserReferences>(initialReferences);
  const [isLoading, setIsLoading] = useState(true);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [requestError, setRequestError] = useState<string | null>(null);
  const [serverErrors, setServerErrors] = useState<ValidationErrors>();
  const [requestVersion, setRequestVersion] = useState(0);

  useEffect(() => {
    const abortController = new AbortController();

    getUserReferences(abortController.signal)
      .then((nextReferences) => {
        setReferences(nextReferences);
        setLoadError(null);
      })
      .catch((error: unknown) => {
        if (!isAbortError(error)) setLoadError(t("errors.references"));
      })
      .finally(() => {
        if (!abortController.signal.aborted) setIsLoading(false);
      });

    return () => abortController.abort();
  }, [requestVersion, t]);

  const retry = () => {
    setIsLoading(true);
    setRequestVersion((current) => current + 1);
  };

  const clearRequestFeedback = () => {
    setRequestError(null);
    setServerErrors(undefined);
  };

  const handleSubmit = async (values: EmployeeFormValues) => {
    setIsSubmitting(true);
    clearRequestFeedback();

    try {
      const createdUser = await createUser({
        name: values.name,
        email: values.email,
        password: values.password,
        password_confirmation: values.passwordConfirmation,
        department_id: values.departmentId ? Number(values.departmentId) : null,
        location_id: values.locationId ? Number(values.locationId) : null,
        role_id: Number(values.roleId),
      });

      navigate(`/employees/${createdUser.id}`, {
        replace: true,
        state: { created: true },
      });
      return true;
    } catch (error) {
      if (error instanceof ApiServiceError) {
        setServerErrors(error.validationErrors);

        if (error.status === 403) {
          setRequestError(t("errors.forbidden"));
        } else if (error.status === 0) {
          setRequestError(t("errors.network"));
        } else if (error.status === 409) {
          setRequestError(error.message);
        } else if (error.status !== 422) {
          setRequestError(t("errors.save"));
        } else if (!error.validationErrors) {
          setRequestError(t("errors.validation"));
        }
      } else {
        setRequestError(t("errors.save"));
      }
      return false;
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <>
      <PageMeta
        title={`${t("create.title")} | KIM PHỤC SẮC`}
        description={t("create.metaDescription")}
      />
      <PageBreadCrumb pageTitle={t("create.title")} />
      <p className="-mt-4 mb-6 text-theme-sm text-gray-500 dark:text-gray-400">
        {t("create.subtitle")}
      </p>

      {isLoading ? (
        <div
          role="status"
          className="rounded-2xl border border-gray-200 bg-white px-6 py-14 text-center text-theme-sm text-gray-500 dark:border-gray-800 dark:bg-white/3 dark:text-gray-400"
        >
          {t("create.loading")}
        </div>
      ) : loadError ? (
        <section className="rounded-2xl border border-error-200 bg-white px-6 py-12 text-center dark:border-error-800 dark:bg-white/3">
          <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
            {t("errors.title")}
          </h2>
          <p className="mt-2 text-theme-sm text-error-600 dark:text-error-400">
            {loadError}
          </p>
          <Button
            type="button"
            variant="outline"
            className="mt-5"
            onClick={retry}
          >
            {t("actions.retry")}
          </Button>
        </section>
      ) : (
        <EmployeeForm
          mode="create"
          departments={references.departments}
          locations={references.locations}
          roles={references.roles}
          isSubmitting={isSubmitting}
          serverErrors={serverErrors}
          requestError={requestError}
          onInteraction={clearRequestFeedback}
          onSubmit={handleSubmit}
        />
      )}
    </>
  );
}
