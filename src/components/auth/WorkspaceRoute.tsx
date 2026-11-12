import { useEffect } from "react";
import { Navigate, Outlet, useLocation } from "react-router";
import AuthLoadingScreen from "@/components/auth/AuthLoadingScreen";
import { useAuth } from "@/hooks/useAuth";
import { useAuthorization } from "@/hooks/useAuthorization";
import Forbidden from "@/pages/OtherPage/Forbidden";
import type { WorkspaceId } from "@/types/workspace";
import {
  canOpenWorkspacePath,
  defaultWorkspacePath,
  rememberWorkspace,
} from "@/utils/workspace";

export default function WorkspaceRoute({
  workspace,
}: {
  workspace: WorkspaceId;
}) {
  const { currentUser } = useAuth();
  const { workspaces, isAuthorizationLoading } = useAuthorization();
  const { pathname } = useLocation();
  const allowed = canOpenWorkspacePath(workspaces, pathname);
  useEffect(() => {
    if (!isAuthorizationLoading && allowed && currentUser)
      rememberWorkspace(String(currentUser.id), workspace);
  }, [allowed, currentUser, isAuthorizationLoading, workspace]);
  if (isAuthorizationLoading) return <AuthLoadingScreen />;
  if (!allowed) {
    const home = defaultWorkspacePath(workspaces, null);
    if (
      workspace === "internal" &&
      ["/", "/dashboard"].includes(pathname) &&
      home
    )
      return <Navigate to={home} replace />;
    return <Forbidden />;
  }
  return <Outlet />;
}
