import { SidebarProvider, useSidebar } from "@/context/SidebarContext";
import NotificationProvider from "@/context/NotificationProvider";
import { cn } from "@/utils";
import { Outlet } from "react-router";
import AppHeader from "./AppHeader";
import KpsChatBubble from "@/components/chat/KpsChatBubble";
import type { WorkspaceId } from "@/types/workspace";
import SpaSidebar from "@/features/spa/components/SpaSidebar";
import AppSidebar from "./AppSidebar";
import Backdrop from "./Backdrop";

type LayoutProps = React.PropsWithChildren<{
  workspace?: WorkspaceId;
  header?: React.ReactNode;
}>;

const LayoutContent = ({
  workspace = "internal",
  header,
  children,
}: LayoutProps) => {
  const { isExpanded, isHovered, isMobileOpen } = useSidebar();

  return (
    <div
      className={cn(
        "min-h-screen xl:flex",
        workspace === "spa" && "bg-gray-50 dark:bg-gray-950",
      )}
    >
      {workspace === "spa" ? <SpaSidebar /> : <AppSidebar />}
      <Backdrop />
      {workspace === "internal" && <KpsChatBubble />}

      <div
        className={cn(
          "min-w-0 flex-1 xl:transition-[margin] xl:duration-300 xl:ease-in-out",
          isExpanded || isHovered ? "xl:ms-72.5" : "xl:ms-22.5",
          isMobileOpen ? "ms-0" : "",
        )}
      >
        {header ?? <AppHeader workspace={workspace} />}
        <main className="mx-auto w-full max-w-375 p-4 md:p-6">
          {children ?? <Outlet />}
        </main>
      </div>
    </div>
  );
};

const AppLayout = ({ workspace, header, children }: LayoutProps) => {
  return (
    <NotificationProvider>
      <SidebarProvider>
        <LayoutContent workspace={workspace} header={header}>
          {children}
        </LayoutContent>
      </SidebarProvider>
    </NotificationProvider>
  );
};

export default AppLayout;
