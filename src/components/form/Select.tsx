import { useState } from "react";

interface Option {
  value: string;
  label: string;
}

interface SelectProps extends Omit<
  React.SelectHTMLAttributes<HTMLSelectElement>,
  "defaultValue" | "onChange" | "value"
> {
  options: Option[];
  placeholder?: string;
  onChange: (value: string) => void;
  defaultValue?: string;
  value?: string;
  allowClear?: boolean;
  error?: boolean;
  hint?: string;
}

const Select: React.FC<SelectProps> = ({
  options,
  placeholder = "Select an option",
  onChange,
  className = "",
  defaultValue = "",
  value,
  id,
  name,
  allowClear = false,
  disabled = false,
  error = false,
  hint,
  ...props
}) => {
  const [internalValue, setInternalValue] = useState<string>(defaultValue);
  const selectedValue = value ?? internalValue;

  const handleChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
    const nextValue = e.target.value;
    if (value === undefined) {
      setInternalValue(nextValue);
    }
    onChange(nextValue);
  };

  return (
    <div className="relative">
      <select
        id={id}
        name={name}
        className={`h-11 w-full appearance-none rounded-lg border bg-transparent px-4 py-2.5 pe-11 text-sm shadow-theme-xs placeholder:text-gray-400 focus:ring-3 focus:outline-hidden disabled:cursor-not-allowed disabled:bg-gray-100 disabled:opacity-50 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:disabled:bg-gray-800 ${
          error
            ? "border-error-500 focus:border-error-300 focus:ring-error-500/20 dark:border-error-500 dark:focus:border-error-800"
            : "border-gray-300 focus:border-brand-300 focus:ring-brand-500/10 dark:border-gray-700 dark:focus:border-brand-800"
        } ${
          selectedValue
            ? "text-gray-800 dark:text-white/90"
            : "text-gray-400 dark:text-gray-400"
        } ${className}`}
        value={selectedValue}
        onChange={handleChange}
        disabled={disabled}
        aria-invalid={error || undefined}
        aria-describedby={hint && id ? `${id}-hint` : undefined}
        {...props}
      >
        {/* Placeholder option */}
        <option
          value=""
          disabled={!allowClear}
          className="text-gray-700 dark:bg-gray-900 dark:text-gray-400"
        >
          {placeholder}
        </option>
        {/* Map over options */}
        {options.map((option) => (
          <option
            key={option.value}
            value={option.value}
            className="text-gray-700 dark:bg-gray-900 dark:text-gray-400"
          >
            {option.label}
          </option>
        ))}
      </select>
      <svg
        className="pointer-events-none absolute inset-e-3 top-1/2 -translate-y-1/2 text-gray-700 dark:text-gray-400"
        width="20"
        height="20"
        viewBox="0 0 20 20"
        fill="none"
        xmlns="http://www.w3.org/2000/svg"
      >
        <path
          d="M4.79175 8.02075L10.0001 13.2291L15.2084 8.02075"
          stroke="currentColor"
          strokeWidth="1.5"
          strokeLinecap="round"
          strokeLinejoin="round"
        />
      </svg>
      {hint && (
        <p
          id={id ? `${id}-hint` : undefined}
          className={`mt-1.5 text-xs ${
            error ? "text-error-500" : "text-gray-500 dark:text-gray-400"
          }`}
        >
          {hint}
        </p>
      )}
    </div>
  );
};

export default Select;
