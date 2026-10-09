import { EyeCloseIcon, EyeIcon } from "@/icons";
import type React from "react";
import type { FC } from "react";
import { useState } from "react";
import { useTranslation } from "react-i18next";

interface InputProps extends Omit<
  React.InputHTMLAttributes<HTMLInputElement>,
  "success" | "error" | "hint" | "step"
> {
  success?: boolean;
  error?: boolean;
  hint?: string;
  step?: number;
  showPasswordToggle?: boolean;
}

const Input: FC<InputProps> = ({
  type = "text",
  id,
  name,
  placeholder,
  defaultValue,
  value,
  onChange,
  className = "",
  min,
  max,
  step,
  disabled = false,
  success = false,
  error = false,
  hint,
  showPasswordToggle = false,
  ...props
}) => {
  const { t } = useTranslation();
  const [isPasswordVisible, setIsPasswordVisible] = useState(false);
  const hasPasswordToggle = showPasswordToggle && type === "password";
  const inputType = hasPasswordToggle && isPasswordVisible ? "text" : type;
  let inputClasses = ` h-11 w-full rounded-lg border appearance-none ps-4 ${hasPasswordToggle ? "pe-11" : "pe-4"} py-2.5 text-sm shadow-theme-xs placeholder:text-gray-400 focus:outline-hidden focus:ring-3  dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 ${className}`;

  if (disabled) {
    inputClasses += ` text-gray-500 border-gray-300 opacity-40 bg-gray-100 cursor-not-allowed dark:bg-gray-800 dark:text-gray-400 dark:border-gray-700 opacity-40`;
  } else if (error) {
    inputClasses += `  border-error-500 focus:border-error-300 focus:ring-error-500/20 dark:text-error-400 dark:border-error-500 dark:focus:border-error-800`;
  } else if (success) {
    inputClasses += `  border-success-500 focus:border-success-300 focus:ring-success-500/20 dark:text-success-400 dark:border-success-500 dark:focus:border-success-800`;
  } else {
    inputClasses += ` bg-transparent text-gray-800 border-gray-300 focus:border-brand-300 focus:ring-brand-500/20 dark:border-gray-700 dark:text-white/90  dark:focus:border-brand-800`;
  }

  return (
    <div>
      <div className="relative">
        <input
          type={inputType}
          id={id}
          name={name}
          placeholder={placeholder}
          defaultValue={defaultValue}
          value={value}
          onChange={onChange}
          min={min}
          max={max}
          step={step}
          disabled={disabled}
          className={inputClasses}
          aria-describedby={hint && id ? `${id}-hint` : undefined}
          {...props}
        />

        {hasPasswordToggle && (
          <button
            type="button"
            disabled={disabled}
            aria-label={t(
              isPasswordVisible
                ? "passwordVisibility.hide"
                : "passwordVisibility.show",
            )}
            aria-pressed={isPasswordVisible}
            onClick={() => setIsPasswordVisible((visible) => !visible)}
            className="absolute inset-e-2 top-1/2 flex size-9 -translate-y-1/2 items-center justify-center rounded-md text-gray-500 transition-colors hover:text-gray-700 focus-visible:ring-2 focus-visible:ring-kps-primary/40 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-40 dark:text-gray-400 dark:hover:text-gray-200"
          >
            {isPasswordVisible ? (
              <EyeCloseIcon className="size-5" aria-hidden="true" />
            ) : (
              <EyeIcon className="size-5" aria-hidden="true" />
            )}
          </button>
        )}
      </div>

      {hint && (
        <p
          id={id ? `${id}-hint` : undefined}
          className={`mt-1.5 text-xs ${
            error
              ? "text-error-500"
              : success
                ? "text-success-500"
                : "text-gray-500"
          }`}
        >
          {hint}
        </p>
      )}
    </div>
  );
};

export default Input;
