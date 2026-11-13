import { useEffect, useRef, useState } from "react";
import { useTranslation } from "react-i18next";
import Label from "@/components/form/Label";
import TextArea from "@/components/form/input/TextArea";
import { useSpaPreview } from "../hooks/useSpaPreviewSnapshot";
import { spaFieldClasses } from "../config";
import type { SpaPreviewError, SpaTechnicianTask } from "../types";
import SpaFormError from "./SpaFormError";
import SpaActionDialog, {
  type SpaActionForm,
} from "./business/SpaActionDialog";
import { SpaAction, SpaHint } from "./business/SpaBusinessUi";
export default function SpaTechnicianActions({
  task,
}: {
  task: SpaTechnicianTask;
}) {
  const { t } = useTranslation();
  const b = (k: string) => t(`spa.business.${k}`);
  const { snapshot, execute } = useSpaPreview();
  const technicianId = snapshot.previewTechnician.id;
  const [result, setResult] = useState(task.draftResult ?? ""),
    [error, setError] = useState<SpaPreviewError>(),
    [saved, setSaved] = useState(Boolean(task.draftResult)),
    [form, setForm] = useState<SpaActionForm | null>(null);
  const executeRef = useRef(execute);
  useEffect(() => {
    executeRef.current = execute;
  }, [execute]);
  const editable =
    task.status === "in_progress" &&
    task.technicianId === technicianId &&
    !task.handover;
  useEffect(() => {
    if (!editable) return;
    const timeout = setTimeout(() => {
      const error = executeRef.current({
        kind: "task-draft",
        id: task.appointmentId,
        taskId: task.id,
        technicianId,
        result,
      });
      setError(error);
      setSaved(!error);
    }, 600);
    return () => clearTimeout(timeout);
  }, [result, editable, task.appointmentId, task.id, technicianId]);
  const draftRef = useRef({ result, editable });
  useEffect(() => {
    draftRef.current = { result, editable };
  }, [result, editable]);
  useEffect(
    () => () => {
      if (draftRef.current.editable)
        executeRef.current({
          kind: "task-draft",
          id: task.appointmentId,
          taskId: task.id,
          technicianId,
          result: draftRef.current.result,
        });
    },
    [task.appointmentId, task.id, technicianId],
  );
  const context = { id: task.appointmentId, taskId: task.id };
  const reason = {
    key: "reason",
    label: b("reason"),
    type: "textarea" as const,
    errors: ["reasonRequired" as const, "technicianConflict" as const],
  };
  const own = task.technicianId === technicianId;
  const arrived =
    snapshot.appointments.find((a) => a.id === task.appointmentId)?.status ===
    "arrived";

  return (
    <div className="mt-6 space-y-4">
      <SpaFormError
        error={error}
        messages={{ noteTooLong: t("spa.business.resultTooLong") }}
      />
      {task.status === "completed" ? (
        <>
          <h4 className="font-semibold text-gray-900 dark:text-white">
            {b("resultRead")}
          </h4>
          <p className="text-sm break-words whitespace-pre-wrap text-gray-600 dark:text-gray-300">
            {task.result}
          </p>
        </>
      ) : (
        <>
          {!arrived ? (
            <SpaHint>{b("notReceived")}</SpaHint>
          ) : task.handover ? (
            <div className="space-y-3 rounded-xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-800 dark:bg-warning-500/10">
              <p className="text-sm font-medium text-gray-800 dark:text-gray-200">
                {t("spa.business.pendingHandover", {
                  name: task.handover.toName,
                })}
              </p>
              <SpaHint>
                {t("spa.business.currentOwner", { name: task.technicianName })}{" "}
                · {task.handover.reason}
              </SpaHint>
              {task.draftResult && (
                <p className="text-sm whitespace-pre-wrap text-gray-600 dark:text-gray-300">
                  {task.draftResult}
                </p>
              )}
              <div className="flex flex-wrap gap-2">
                {task.handover.toId === technicianId && (
                  <SpaAction
                    onClick={() =>
                      setError(
                        execute({
                          kind: "handover-accept",
                          ...context,
                          technicianId,
                        }),
                      )
                    }
                  >
                    {b("accept")}
                  </SpaAction>
                )}
                {own && (
                  <SpaAction
                    secondary
                    onClick={() =>
                      setForm({
                        title: b("withdrawTitle"),
                        initial: { reason: "" },
                        fields: [reason],
                        submit: (v) =>
                          execute({
                            kind: "handover-withdraw",
                            ...context,
                            reason: v.reason,
                          }),
                      })
                    }
                  >
                    {b("withdraw")}
                  </SpaAction>
                )}
              </div>
            </div>
          ) : own && task.status === "assigned" ? (
            <SpaAction
              onClick={() =>
                setError(
                  execute({ kind: "task-start", ...context, technicianId }),
                )
              }
            >
              {b("start")}
            </SpaAction>
          ) : editable ? (
            <>
              <div>
                <Label htmlFor="spa-tech-result">{b("result")}</Label>
                <TextArea
                  id="spa-tech-result"
                  placeholder={b("result")}
                  value={result}
                  onChange={(value) => {
                    setResult(value);
                    setSaved(false);
                    setError(undefined);
                  }}
                  className={spaFieldClasses}
                  error={error === "noteTooLong"}
                />
                <SpaHint>{b("resultHint")}</SpaHint>
                <p
                  role="status"
                  className="mt-1 text-xs text-gray-500 dark:text-gray-400"
                >
                  {b(saved ? "draftSaved" : "draftSaving")}
                </p>
              </div>
              <div className="sticky bottom-24 z-10 flex flex-wrap gap-2 border-t border-gray-100 bg-white py-3 md:bottom-20 dark:border-gray-800 dark:bg-gray-900">
                <SpaAction
                  onClick={() =>
                    setForm({
                      title: b("completeTitle"),
                      hint: b("completeHint"),
                      initial: { result },
                      fields: [
                        {
                          key: "result",
                          label: b("result"),
                          type: "textarea",
                          errors: [
                            "resultRequired",
                            "packageEmpty",
                            "packageInvalid",
                            "invalidTransition",
                          ],
                        },
                      ],
                      submit: (v) =>
                        execute({
                          kind: "task-complete",
                          ...context,
                          technicianId,
                          result: v.result,
                        }),
                    })
                  }
                >
                  {b("complete")}
                </SpaAction>
                <SpaAction
                  secondary
                  onClick={() =>
                    setForm({
                      title: b("handover"),
                      hint: b("handoverHint"),
                      initial: { toId: "", reason: "" },
                      fields: [
                        {
                          key: "toId",
                          label: b("handoverTo"),
                          type: "select",
                          options: snapshot.technicians
                            .filter((tech) => tech.id !== technicianId)
                            .map((tech) => ({
                              value: tech.id,
                              label: tech.name,
                            })),
                          errors: ["technicianRequired", "technicianConflict"],
                        },
                        reason,
                      ],
                      submit: (v) => {
                        const draftError = execute({
                          kind: "task-draft",
                          ...context,
                          technicianId,
                          result,
                        });
                        return (
                          draftError ??
                          execute({
                            kind: "handover-request",
                            ...context,
                            technicianId,
                            toId: v.toId,
                            reason: v.reason,
                          })
                        );
                      },
                    })
                  }
                >
                  {b("handover")}
                </SpaAction>
              </div>
            </>
          ) : null}
        </>
      )}
      {task.participants?.length ? (
        <SpaHint>
          {t("spa.business.participants", {
            names: task.participants
              .map(
                (id) =>
                  snapshot.technicians.find((tech) => tech.id === id)?.name ??
                  id,
              )
              .join(", "),
          })}
        </SpaHint>
      ) : null}
      {form && <SpaActionDialog form={form} onClose={() => setForm(null)} />}
    </div>
  );
}
