import type { FC, FormEvent, ReactNode } from "react";

interface FormProps {
  onSubmit: (event: FormEvent<HTMLFormElement>) => void;
  children: ReactNode;
  className?: string;
  noValidate?: boolean;
}

const Form: FC<FormProps> = ({ onSubmit, children, className, noValidate }) => {
  return (
    <form
      noValidate={noValidate}
      onSubmit={(event) => {
        event.preventDefault(); // Prevent default form submission
        onSubmit(event);
      }}
      className={className}
    >
      {children}
    </form>
  );
};

export default Form;
