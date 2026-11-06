import { useEffect, useId, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import { InfoCircleIcon } from "@/icons";
import Badge from "@/components/ui/badge/Badge";

export default function MailMessageStatus({ status }: { status: string }) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const tooltipId = useId();
  const container = useRef<HTMLSpanElement>(null);
  const sent = status === "accepted";

  useEffect(() => {
    if (!open) return;
    const dismissOutside = (event: PointerEvent) => {
      if (
        event.target instanceof Node &&
        !container.current?.contains(event.target)
      )
        setOpen(false);
    };
    const dismissEscape = (event: KeyboardEvent) => {
      if (event.key === "Escape") setOpen(false);
    };
    document.addEventListener("pointerdown", dismissOutside);
    document.addEventListener("keydown", dismissEscape);
    return () => {
      document.removeEventListener("pointerdown", dismissOutside);
      document.removeEventListener("keydown", dismissEscape);
    };
  }, [open]);

  const label = t(`mailUi.messageStates.${status}`, {
    defaultValue: t("mailUi.messageStates.unknown"),
  });
  if (!sent) return <Badge color="light">{label}</Badge>;
  return (
    <span
      ref={container}
      className="relative inline-flex max-w-full items-center gap-1"
      onPointerEnter={(event) => {
        if (event.pointerType !== "touch") setOpen(true);
      }}
      onPointerLeave={(event) => {
        if (event.pointerType !== "touch") setOpen(false);
      }}
    >
      <Badge color="success">{label}</Badge>
      <button
        type="button"
        aria-label={t("mailUi.sentInfoLabel")}
        aria-describedby={open ? tooltipId : undefined}
        onFocus={() => setOpen(true)}
        onBlur={() => setOpen(false)}
        onClick={() => setOpen(true)}
        className="inline-flex size-8 shrink-0 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus-visible:outline-2 focus-visible:outline-kps-primary dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-200"
      >
        <InfoCircleIcon className="size-4" />
      </button>
      {open && (
        <span className="absolute end-0 top-full z-99 w-56 pt-2">
          <span
            id={tooltipId}
            role="tooltip"
            className="block rounded-lg bg-gray-900 px-3 py-2 text-start text-xs font-normal text-white shadow-tooltip dark:bg-gray-100 dark:text-gray-900"
          >
            {t("mailUi.sentInfo")}
          </span>
        </span>
      )}
    </span>
  );
}
