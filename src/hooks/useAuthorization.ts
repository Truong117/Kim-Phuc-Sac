import { AuthorizationContext } from "@/context/AuthorizationContext";
import { useContext } from "react";

export const useAuthorization = () => {
  const context = useContext(AuthorizationContext);

  if (!context) {
    throw new Error(
      "useAuthorization must be used within an AuthorizationProvider",
    );
  }

  return context;
};
