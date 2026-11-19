import { useId, useState } from "react";
import { createPortal } from "react-dom";
import { useTranslation } from "react-i18next";
import Label from "@/components/form/Label";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import { Modal } from "@/components/ui/modal";
import { useModal } from "@/hooks/useModal";
import {
  facebookTrackingStopReasons,
  isFacebookTrackingStopReason,
  type FacebookTrackedPost,
  type FacebookTrackingStopReason,
} from "@/types/facebookAnalytics";

export default function FacebookTrackingAction({
  post,
  disabled,
  onChange,
}: {
  post: FacebookTrackedPost;
  disabled: boolean;
  onChange: (reason?: FacebookTrackingStopReason) => void;
}) {
  const { t } = useTranslation("common", {
    keyPrefix: "facebookAnalytics.tracking",
  });
  const { isOpen, openModal, closeModal } = useModal();
  const titleId = useId();
  const reasonId = useId();
  const [reason, setReason] = useState<FacebookTrackingStopReason>();

  if (post.status === "stopped") {
    return (
      <Button
        size="sm"
        variant="outline"
        disabled={disabled}
        onClick={() => onChange()}
      >
        {t("resume")}
      </Button>
    );
  }

  return (
    <>
      <Button
        size="sm"
        variant="outline"
        disabled={disabled}
        onClick={() => {
          setReason(undefined);
          openModal();
        }}
      >
        {t("stop")}
      </Button>
      {isOpen &&
        createPortal(
          <Modal
            isOpen
            onClose={closeModal}
            ariaLabelledBy={titleId}
            aboveNavigation
            showCloseButton={false}
            className="mx-4 max-w-md p-5 sm:p-6"
          >
            <h2
              id={titleId}
              className="text-lg font-semibold text-gray-900 dark:text-white"
            >
              {t("stopTitle")}
            </h2>
            <p className="mt-2 text-sm break-words text-gray-700 dark:text-gray-300">
              {t("stopConfirm", { id: post.external_id })}
            </p>
            <div className="mt-4">
              <Label htmlFor={reasonId}>{t("reasonLabel")}</Label>
              <Select
                id={reasonId}
                value={reason ?? ""}
                allowClear
                placeholder={t("reasonNone")}
                options={facebookTrackingStopReasons.map((value) => ({
                  value,
                  label: t(`reasons.${value}`),
                }))}
                onChange={(value) =>
                  setReason(
                    isFacebookTrackingStopReason(value) ? value : undefined,
                  )
                }
              />
            </div>
            <div className="mt-6 flex flex-wrap justify-end gap-2">
              <Button size="sm" variant="outline" onClick={closeModal}>
                {t("cancel")}
              </Button>
              <Button
                size="sm"
                disabled={disabled}
                onClick={() => {
                  closeModal();
                  onChange(reason);
                }}
              >
                {t("stop")}
              </Button>
            </div>
          </Modal>,
          document.body,
        )}
    </>
  );
}
