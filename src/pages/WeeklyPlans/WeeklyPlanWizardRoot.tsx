import { WeeklyPlanWizardProvider } from "@/context/WeeklyPlanWizardContext";
import { Outlet } from "react-router";

export default function WeeklyPlanWizardRoot() {
  return (
    <WeeklyPlanWizardProvider>
      <Outlet />
    </WeeklyPlanWizardProvider>
  );
}
