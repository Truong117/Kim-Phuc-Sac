import { useLayoutEffect, useRef } from "react";
import Input from "@/components/form/input/InputField";
import { spaFieldClasses } from "../config";
import { editSpaMoneyInput, formatSpaMoneyInput } from "../moneyInput";

export default function SpaMoneyInput({
  id,
  value,
  onChange,
  error,
  required,
}: {
  id: string;
  value: string;
  onChange: (value: string) => void;
  error: boolean;
  required: boolean;
}) {
  const selection = useRef<{ input: HTMLInputElement; caret: number } | null>(
    null,
  );
  useLayoutEffect(() => {
    const pending = selection.current;
    if (pending && document.activeElement === pending.input)
      pending.input.setSelectionRange(pending.caret, pending.caret);
    selection.current = null;
  });
  return (
    <Input
      id={id}
      type="text"
      inputMode="numeric"
      autoComplete="off"
      value={formatSpaMoneyInput(value)}
      onChange={(event) => {
        const input = event.currentTarget;
        const edit = editSpaMoneyInput(
          input.value,
          input.selectionStart ?? input.value.length,
          value,
          (event.nativeEvent as InputEvent).inputType,
        );
        if (!edit) return;
        if (edit.value === value) {
          input.value = formatSpaMoneyInput(value);
          input.setSelectionRange(edit.caret, edit.caret);
          return;
        }
        selection.current = { input, caret: edit.caret };
        onChange(edit.value);
      }}
      required={required}
      error={error}
      aria-invalid={error}
      className={spaFieldClasses}
    />
  );
}
