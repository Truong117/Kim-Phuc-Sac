import AuthLoadingScreen from "@/components/auth/AuthLoadingScreen";
import { useAuth } from "@/hooks/useAuth";
import { useAuthorization } from "@/hooks/useAuthorization";
import Forbidden from "@/pages/OtherPage/Forbidden";
import {
  canOpenWorkspacePath,
  defaultWorkspacePath,
  readWorkspacePreference,
} from "@/utils/workspace";
import { Navigate, useLocation } from "react-router";

export default function WorkspaceEntry() {
  const { currentUser } = useAuth();
  const { workspaces, isAuthorizationLoading } = useAuthorization();
  const { state } = useLocation();
  if (isAuthorizationLoading) return <AuthLoadingScreen />;
  const from = state?.from;
  const requested =
    typeof from?.pathname === "string"
      ? `${from.pathname}${from.search ?? ""}${from.hash ?? ""}`
      : null;
  const target =
    requested && canOpenWorkspacePath(workspaces, requested)
      ? requested
      : defaultWorkspacePath(
          workspaces,
          currentUser ? readWorkspacePreference(String(currentUser.id)) : null,
        );
  return target ? <Navigate to={target} replace /> : <Forbidden />;
}
