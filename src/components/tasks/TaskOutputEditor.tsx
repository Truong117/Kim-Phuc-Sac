import { useTranslation } from "react-i18next";
import type { ReactNode } from "react";
import Input from "@/components/form/input/InputField";
import Button from "@/components/ui/button/Button";
import type { TaskOutput, TaskQuantityUnit } from "@/types/tasks";
import { needsOutputReference } from "@/utils/taskProgress";

export default function TaskOutputEditor({
  unit,
  outputs,
  onChange,
  disabled = false,
  completedLocked = false,
  existingOutputs = [],
  extraAction,
}: {
  unit: TaskQuantityUnit | null;
  outputs: TaskOutput[];
  onChange: (rows: TaskOutput[]) => void;
  disabled?: boolean;
  completedLocked?: boolean;
  existingOutputs?: TaskOutput[];
  extraAction?: ReactNode;
}) {
  const { t } = useTranslation("common", { keyPrefix: "taskProgress" });
  const care = needsOutputReference(unit);
  const change = (index: number, patch: Partial<TaskOutput>) =>
    onChange(
      outputs.map((row, i) => (i === index ? { ...row, ...patch } : row)),
    );
  return (
    <div className="space-y-3">
      {care && (
        <p className="text-theme-xs text-gray-600 dark:text-gray-300">
          {t(unit === "khách hàng" ? "customerRule" : "interactionRule")}
        </p>
      )}
      {outputs.map((output, index) => (
        <div
          key={output.id ?? `new-${index}`}
          className="flex flex-wrap items-center gap-3"
        >
          <div className="min-w-0 flex-1">
            <Input
              aria-label={t(care ? "careName" : "outputName", {
                number: index + 1,
              })}
              value={output.name}
              disabled={disabled}
              maxLength={200}
              onChange={(event) => change(index, { name: event.target.value })}
            />
          </div>
          {care && (
            <div className="w-full sm:w-auto sm:flex-1">
              <Input
                aria-label={t(
                  unit === "khách hàng"
                    ? "customerReference"
                    : "interactionReference",
                  { number: index + 1 },
                )}
                placeholder={t(
                  unit === "khách hàng"
                    ? "customerReferenceHint"
                    : "interactionReferenceHint",
                )}
                value={output.reference ?? ""}
                disabled={disabled || Boolean(output.id)}
                maxLength={200}
                onChange={(event) =>
                  change(index, { reference: event.target.value })
                }
              />
            </div>
          )}
          {!completedLocked && (
            <label className="flex items-center gap-2 text-theme-xs text-gray-700 dark:text-gray-300">
              <input
                type="checkbox"
                className="size-4 accent-kps-primary"
                checked={output.completed}
                disabled={disabled}
                onChange={(event) =>
                  change(index, { completed: event.target.checked })
                }
              />
              {t(care ? "careCompleted" : "outputCompleted")}
            </label>
          )}
          <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={disabled}
            onClick={() => onChange(outputs.filter((_, i) => i !== index))}
          >
            {t("deleteOutput")}
          </Button>
        </div>
      ))}
      <div className="flex flex-wrap gap-2">
        <Button
          type="button"
          size="sm"
          variant="outline"
          disabled={disabled || !unit || outputs.length >= 50}
          onClick={() =>
            onChange([
              ...outputs,
              {
                name: "",
                completed: true,
                ...(unit === "lượt chăm sóc"
                  ? { reference: crypto.randomUUID() }
                  : care
                    ? { reference: "" }
                    : {}),
              },
            ])
          }
        >
          {t(care ? "addCare" : "addOutput")}
        </Button>
        {extraAction}
      </div>
      {existingOutputs.length > 0 && (
        <details className="text-theme-xs text-gray-600 dark:text-gray-300">
          <summary className="cursor-pointer">{t("existingProducts")}</summary>
          <div className="mt-2 flex flex-wrap gap-2">
            {existingOutputs
              .filter((row) => row.unit === unit)
              .map((output) => (
                <Button
                  key={output.id}
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={
                    disabled ||
                    outputs.length >= 50 ||
                    outputs.some((row) => row.id === output.id)
                  }
                  onClick={() =>
                    onChange([
                      ...outputs,
                      {
                        id: output.id,
                        name: output.name,
                        reference: output.reference,
                        completed: output.completed,
                      },
                    ])
                  }
                >
                  {output.name}
                  {output.reference ? ` · ${output.reference}` : ""}
                </Button>
              ))}
          </div>
        </details>
      )}
    </div>
  );
}
