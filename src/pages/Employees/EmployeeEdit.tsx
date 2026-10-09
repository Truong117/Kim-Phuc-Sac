import PageBreadCrumb from "@/components/common/PageBreadCrumb";
import PageMeta from "@/components/common/PageMeta";
import EmployeeAccessStatus from "@/components/employees/EmployeeAccessStatus";
import EmployeeForm from "@/components/employees/EmployeeForm";
import EmployeeRoleManagement from "@/components/employees/EmployeeRoleManagement";
import EmployeeStatusConfirmation from "@/components/employees/EmployeeStatusConfirmation";
import Button from "@/components/ui/button/Button";
import { useAuth } from "@/hooks/useAuth";
import { ApiServiceError, type ValidationErrors } from "@/services/apiClient";
import {
  getUser,
  getUserReferences,
  updateUser,
  updateUserRole,
  updateUserStatus,
} from "@/services/userService";
import type {
  EmployeeFormValues,
  ManagedUser,
  ReferenceItem,
  UserReferences,
} from "@/types/userManagement";
import { useEffect, useState } from "react";
import { useTranslation } from "react-i18next";
import { Link, useLocation, useNavigate, useParams } from "react-router";

type EditLocationState = {
  created?: boolean;
};

type EditPageData = {
  user: ManagedUser;
  references: UserReferences;
};

const isAbortError = (error: unknown) =>
  error instanceof DOMException && error.name === "AbortError";

const includeReference = (
  references: ReferenceItem[],
  current: ReferenceItem | null,
) => {
  if (!current || references.some((item) => item.id === current.id)) {
    return references;
  }

  return [current, ...references];
};

export default function EmployeeEdit() {
  const { t } = useTranslation("common", { keyPrefix: "userManagement" });
  const { currentUser, refreshCurrentUser } = useAuth();
  const { id } = useParams();
  const location = useLocation();
  const navigate = useNavigate();
  const [data, setData] = useState<EditPageData | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [resolvedUserId, setResolvedUserId] = useState<number | null>(null);
  const [requestVersion, setRequestVersion] = useState(0);
  const [isProfileSubmitting, setIsProfileSubmitting] = useState(false);
  const [profileErrors, setProfileErrors] = useState<ValidationErrors>();
  const [profileError, setProfileError] = useState<string | null>(null);
  const [profileSuccess, setProfileSuccess] = useState<string | null>(null);
  const [isRoleSubmitting, setIsRoleSubmitting] = useState(false);
  const [roleError, setRoleError] = useState<string | null>(null);
  const [roleSuccess, setRoleSuccess] = useState<string | null>(null);
  const [isStatusSubmitting, setIsStatusSubmitting] = useState(false);
  const [statusError, setStatusError] = useState<string | null>(null);
  const [statusSuccess, setStatusSuccess] = useState<string | null>(null);
  const [isStatusConfirmationOpen, setIsStatusConfirmationOpen] =
    useState(false);
  const [isProfileDirty, setIsProfileDirty] = useState(false);
  const [isRoleDirty, setIsRoleDirty] = useState(false);
  const userId = Number(id);
  const hasValidUserId = Number.isInteger(userId) && userId > 0;

  useEffect(() => {
    if (!hasValidUserId) return;

    const abortController = new AbortController();

    Promise.all([
      getUser(userId, abortController.signal),
      getUserReferences(abortController.signal),
    ])
      .then(([user, references]) => {
        setData({ user, references });
        setLoadError(null);
        setResolvedUserId(userId);
        setIsProfileDirty(false);
        setIsRoleDirty(false);
      })
      .catch((error: unknown) => {
        if (isAbortError(error)) return;

        if (error instanceof ApiServiceError && error.status === 404) {
          setLoadError(t("errors.notFound"));
        } else if (error instanceof ApiServiceError && error.status === 403) {
          setLoadError(t("errors.forbidden"));
        } else if (error instanceof ApiServiceError && error.status === 0) {
          setLoadError(t("errors.network"));
        } else {
          setLoadError(t("errors.loadDetail"));
        }
        setResolvedUserId(userId);
      })
      .finally(() => {
        if (!abortController.signal.aborted) setIsLoading(false);
      });

    return () => abortController.abort();
  }, [hasValidUserId, requestVersion, t, userId]);

  const retry = () => {
    setIsLoading(true);
    setRequestVersion((current) => current + 1);
  };

  const mutationErrorMessage = (error: ApiServiceError) => {
    if (error.status === 403) return t("errors.forbidden");
    if (error.status === 0) return t("errors.network");
    if (error.status === 409 && error.message) return error.message;
    if (error.status === 422) return t("errors.validation");
    return t("errors.save");
  };

  const clearProfileFeedback = () => {
    setProfileErrors(undefined);
    setProfileError(null);
    setProfileSuccess(null);
  };

  const handleProfileSubmit = async (values: EmployeeFormValues) => {
    if (!data) return false;

    setIsProfileSubmitting(true);
    clearProfileFeedback();

    try {
      const updatedUser = await updateUser(data.user.id, {
        name: values.name,
        email: values.email,
        department_id: values.departmentId ? Number(values.departmentId) : null,
        location_id: values.locationId ? Number(values.locationId) : null,
      });
      setData((current) =>
        current ? { ...current, user: updatedUser } : current,
      );
      setProfileSuccess(t("edit.profileSaved"));

      if (String(currentUser?.id) === String(updatedUser.id)) {
        void refreshCurrentUser().catch(() => undefined);
      }
      return true;
    } catch (error) {
      if (error instanceof ApiServiceError) {
        setProfileErrors(error.validationErrors);
        if (error.status !== 422 || !error.validationErrors) {
          setProfileError(mutationErrorMessage(error));
        }
      } else {
        setProfileError(t("errors.save"));
      }
      return false;
    } finally {
      setIsProfileSubmitting(false);
    }
  };

  const handleRoleSave = async (roleId: number) => {
    if (!data) return;

    setIsRoleSubmitting(true);
    setRoleError(null);
    setRoleSuccess(null);

    try {
      const updatedUser = await updateUserRole(data.user.id, roleId);
      setData((current) =>
        current ? { ...current, user: updatedUser } : current,
      );
      setRoleSuccess(t("edit.roleSaved"));
    } catch (error) {
      setRoleError(
        error instanceof ApiServiceError
          ? mutationErrorMessage(error)
          : t("errors.save"),
      );
    } finally {
      setIsRoleSubmitting(false);
    }
  };

  const handleStatusConfirm = async () => {
    if (!data) return;

    setIsStatusSubmitting(true);
    setStatusError(null);
    setStatusSuccess(null);

    try {
      const updatedUser = await updateUserStatus(
        data.user.id,
        !data.user.is_active,
      );
      setData((current) =>
        current ? { ...current, user: updatedUser } : current,
      );
      setIsStatusConfirmationOpen(false);
      setStatusSuccess(
        t(
          updatedUser.is_active
            ? "edit.accountActivated"
            : "edit.accountDeactivated",
        ),
      );
    } catch (error) {
      setIsStatusConfirmationOpen(false);
      setStatusError(
        error instanceof ApiServiceError
          ? mutationErrorMessage(error)
          : t("errors.save"),
      );
    } finally {
      setIsStatusSubmitting(false);
    }
  };

  const created = Boolean(
    (location.state as EditLocationState | null)?.created,
  );

  if (!hasValidUserId) {
    return (
      <>
        <PageMeta
          title={`${t("edit.title")} | KIM PHỤC SẮC`}
          description={t("edit.metaDescription")}
        />
        <PageBreadCrumb pageTitle={t("edit.title")} />
        <section className="rounded-2xl border border-error-200 bg-white px-6 py-12 text-center dark:border-error-800 dark:bg-white/3">
          <p className="text-theme-sm text-error-600 dark:text-error-400">
            {t("errors.notFound")}
          </p>
          <Link
            to="/employees"
            className="mt-5 inline-flex rounded-lg border border-gray-300 px-4 py-3 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 focus-visible:ring-2 focus-visible:ring-kps-primary/40 focus-visible:outline-none dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
          >
            {t("actions.backToList")}
          </Link>
        </section>
      </>
    );
  }

  const isCurrentUser = String(currentUser?.id) === String(data?.user.id);
  const hasUnsavedChanges = isProfileDirty || isRoleDirty;
  const isMutationPending =
    isProfileSubmitting || isRoleSubmitting || isStatusSubmitting;
  const isCompleteDisabled =
    hasUnsavedChanges || isMutationPending || isStatusConfirmationOpen;
  return (
    <>
      <PageMeta
        title={`${t("edit.title")} | KIM PHỤC SẮC`}
        description={t("edit.metaDescription")}
      />
      <PageBreadCrumb pageTitle={t("edit.title")} />
      <p className="-mt-4 mb-6 text-theme-sm text-gray-500 dark:text-gray-400">
        {t("edit.subtitle")}
      </p>

      {isLoading || resolvedUserId !== userId ? (
        <div
          role="status"
          className="rounded-2xl border border-gray-200 bg-white px-6 py-14 text-center text-theme-sm text-gray-500 dark:border-gray-800 dark:bg-white/3 dark:text-gray-400"
        >
          {t("edit.loading")}
        </div>
      ) : loadError || !data ? (
        <section className="rounded-2xl border border-error-200 bg-white px-6 py-12 text-center dark:border-error-800 dark:bg-white/3">
          <h2 className="text-lg font-semibold text-gray-900 dark:text-white">
            {t("errors.title")}
          </h2>
          <p className="mt-2 text-theme-sm text-error-600 dark:text-error-400">
            {loadError ?? t("errors.loadDetail")}
          </p>
          <div className="mt-5 flex flex-col justify-center gap-3 sm:flex-row">
            <Link
              to="/employees"
              className="inline-flex items-center justify-center rounded-lg border border-gray-300 px-4 py-3 text-theme-sm font-medium text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
            >
              {t("actions.backToList")}
            </Link>
            <Button type="button" variant="outline" onClick={retry}>
              {t("actions.retry")}
            </Button>
          </div>
        </section>
      ) : (
        <div className="space-y-4">
          {created && (
            <div
              role="status"
              className="rounded-lg border border-success-200 bg-success-50 px-4 py-3 text-theme-sm text-success-700 dark:border-success-800 dark:bg-success-500/10 dark:text-success-400"
            >
              {t("create.success")}
            </div>
          )}

          <EmployeeForm
            key={`profile-${data.user.id}`}
            mode="edit"
            initialValues={{
              name: data.user.name,
              email: data.user.email,
              password: "",
              passwordConfirmation: "",
              departmentId: data.user.department
                ? String(data.user.department.id)
                : "",
              locationId: data.user.location
                ? String(data.user.location.id)
                : "",
              roleId: "",
            }}
            departments={includeReference(
              data.references.departments,
              data.user.department,
            )}
            locations={includeReference(
              data.references.locations,
              data.user.location,
            )}
            roles={data.references.roles}
            isSubmitting={isProfileSubmitting}
            serverErrors={profileErrors}
            requestError={profileError}
            successMessage={profileSuccess}
            onInteraction={clearProfileFeedback}
            onDirtyChange={setIsProfileDirty}
            onSubmit={handleProfileSubmit}
          />

          <EmployeeRoleManagement
            key={`role-${data.user.id}`}
            user={data.user}
            roles={includeReference(data.references.roles, data.user.role)}
            isCurrentUser={isCurrentUser}
            isSubmitting={isRoleSubmitting}
            error={roleError}
            success={roleSuccess}
            onDirtyChange={setIsRoleDirty}
            onInteraction={() => {
              setRoleError(null);
              setRoleSuccess(null);
            }}
            onSave={handleRoleSave}
          />

          <EmployeeAccessStatus
            user={data.user}
            isCurrentUser={isCurrentUser}
            isSubmitting={isStatusSubmitting}
            error={statusError}
            success={statusSuccess}
            onChangeStatus={() => {
              setStatusError(null);
              setStatusSuccess(null);
              setIsStatusConfirmationOpen(true);
            }}
          />

          <div className="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-white p-4 sm:flex-row sm:items-center dark:border-gray-800 dark:bg-white/3">
            <div className="min-w-0 flex-1">
              {hasUnsavedChanges && (
                <p
                  id="employee-edit-unsaved-notice"
                  className="text-theme-sm text-warning-700 dark:text-warning-400"
                >
                  {t("edit.unsavedChanges")}
                </p>
              )}
            </div>
            <Button
              type="button"
              className="w-full !bg-kps-primary hover:!bg-kps-primary-hover focus-visible:ring-3 focus-visible:ring-kps-primary/35 focus-visible:outline-none disabled:!bg-kps-primary sm:w-auto"
              disabled={isCompleteDisabled}
              onClick={() => navigate("/employees")}
            >
              {t("edit.complete")}
            </Button>
          </div>

          <EmployeeStatusConfirmation
            user={data.user}
            isOpen={isStatusConfirmationOpen}
            isSubmitting={isStatusSubmitting}
            onClose={() => setIsStatusConfirmationOpen(false)}
            onConfirm={() => void handleStatusConfirm()}
          />
        </div>
      )}
    </>
  );
}
