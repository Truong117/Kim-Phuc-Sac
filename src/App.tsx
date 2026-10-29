import { Navigate, Route, BrowserRouter as Router, Routes } from "react-router";
import AuthorizationRoute from "./components/auth/AuthorizationRoute";
import ProtectedRoute from "./components/auth/ProtectedRoute";
import { ScrollToTop } from "./components/common/ScrollToTop";
import AppLayout from "./layout/AppLayout";
import AiDailyInsights from "./pages/AI/AiDailyInsights";
import AiExecutiveDashboard from "./pages/AI/AiExecutiveDashboard";
import Account from "./pages/Account/Account";
import Login from "./pages/Auth/Login";
import Overview from "./pages/Dashboard/Overview";
import EmployeeCreate from "./pages/Employees/EmployeeCreate";
import EmployeeEdit from "./pages/Employees/EmployeeEdit";
import EmployeeList from "./pages/Employees/EmployeeList";
import ModulePlaceholder from "./pages/OtherPage/ModulePlaceholder";
import NotFound from "./pages/OtherPage/NotFound";
import Profile from "./pages/Profile/Profile";
import NewReport from "./pages/Reports/NewReport";
import ReportDetail from "./pages/Reports/ReportDetail";
import ReportHistory from "./pages/Reports/ReportHistory";
import TaskCreate from "./pages/Tasks/TaskCreate";
import TaskDetail from "./pages/Tasks/TaskDetail";
import TaskList from "./pages/Tasks/TaskList";
import WeeklyPlanImport from "./pages/WeeklyPlans/WeeklyPlanImport";
import WeeklyPlanList from "./pages/WeeklyPlans/WeeklyPlanList";
import WeeklyPlanReview from "./pages/WeeklyPlans/WeeklyPlanReview";
import WeeklyPlanWizardEntry from "./pages/WeeklyPlans/WeeklyPlanWizardEntry";
import WeeklyPlanWizardQuality from "./pages/WeeklyPlans/WeeklyPlanWizardQuality";
import WeeklyPlanWizardRoot from "./pages/WeeklyPlans/WeeklyPlanWizardRoot";
import WeeklyPlanWizardSource from "./pages/WeeklyPlans/WeeklyPlanWizardSource";
import WeeklyPlanWizardStructure from "./pages/WeeklyPlans/WeeklyPlanWizardStructure";
import WeeklyPlanWizardSubmit from "./pages/WeeklyPlans/WeeklyPlanWizardSubmit";
import WeeklyPlanWizardWork from "./pages/WeeklyPlans/WeeklyPlanWizardWork";

export default function App() {
  return (
    <>
      <Router>
        <ScrollToTop />
        <Routes>
          <Route path="/login" element={<Login />} />

          <Route element={<ProtectedRoute />}>
            {/* Dashboard Layout */}
            <Route element={<AppLayout />}>
              <Route path="/dashboard" element={<Navigate to="/" replace />} />
              <Route element={<AuthorizationRoute navigationKey="dashboard" />}>
                <Route index element={<Overview />} />
              </Route>
              <Route path="/profile" element={<Profile />} />
              <Route path="/account" element={<Account />} />

              {/* KPS Modules */}
              <Route path="/reports/new" element={<NewReport />} />
              <Route
                element={<AuthorizationRoute navigationKey="reports.history" />}
              >
                <Route path="/reports" element={<ReportHistory />} />
                <Route path="/reports/:id" element={<ReportDetail />} />
              </Route>
              <Route element={<AuthorizationRoute navigationKey="tasks" />}>
                <Route path="/tasks" element={<TaskList />} />
                <Route path="/tasks/new" element={<TaskCreate />} />
                <Route path="/tasks/:id" element={<TaskDetail />} />
              </Route>
              <Route
                element={<AuthorizationRoute navigationKey="weekly-plans" />}
              >
                <Route path="/weekly-plans" element={<WeeklyPlanList />} />
                <Route
                  path="/weekly-plans/new"
                  element={<WeeklyPlanImport />}
                />
                <Route
                  path="/weekly-plans/:id/review"
                  element={<WeeklyPlanReview />}
                />
                <Route
                  path="/weekly-plans/:id"
                  element={<WeeklyPlanWizardRoot />}
                >
                  <Route index element={<WeeklyPlanWizardEntry />} />
                  <Route path="source" element={<WeeklyPlanWizardSource />} />
                  <Route
                    path="structure"
                    element={<WeeklyPlanWizardStructure />}
                  />
                  <Route path="work" element={<WeeklyPlanWizardWork />} />
                  <Route path="quality" element={<WeeklyPlanWizardQuality />} />
                  <Route path="submit" element={<WeeklyPlanWizardSubmit />} />
                </Route>
              </Route>
              <Route element={<AuthorizationRoute navigationKey="customers" />}>
                <Route
                  path="/customers"
                  element={<ModulePlaceholder titleKey="modules.customers" />}
                />
                <Route
                  path="/customers/:id"
                  element={
                    <ModulePlaceholder titleKey="modules.customerDetail" />
                  }
                />
              </Route>
              <Route element={<AuthorizationRoute navigationKey="products" />}>
                <Route
                  path="/products"
                  element={<ModulePlaceholder titleKey="modules.products" />}
                />
              </Route>
              <Route element={<AuthorizationRoute navigationKey="orders" />}>
                <Route
                  path="/orders"
                  element={<ModulePlaceholder titleKey="modules.orders" />}
                />
              </Route>
              <Route
                element={<AuthorizationRoute navigationKey="ai.dashboard" />}
              >
                <Route
                  path="/ai/dashboard"
                  element={<AiExecutiveDashboard />}
                />
              </Route>
              <Route
                element={<AuthorizationRoute navigationKey="ai.insights" />}
              >
                <Route path="/ai/insights" element={<AiDailyInsights />} />
              </Route>
              <Route
                element={<AuthorizationRoute navigationKey="ai.assistant" />}
              >
                <Route
                  path="/ai/assistant"
                  element={<ModulePlaceholder titleKey="modules.aiAssistant" />}
                />
              </Route>
              <Route element={<AuthorizationRoute navigationKey="employees" />}>
                <Route path="/employees" element={<EmployeeList />} />
                <Route path="/employees/new" element={<EmployeeCreate />} />
                <Route path="/employees/:id" element={<EmployeeEdit />} />
              </Route>
              <Route
                element={<AuthorizationRoute navigationKey="integrations" />}
              >
                <Route
                  path="/integrations"
                  element={
                    <ModulePlaceholder titleKey="modules.integrations" />
                  }
                />
              </Route>
              <Route element={<AuthorizationRoute navigationKey="settings" />}>
                <Route
                  path="/settings"
                  element={<ModulePlaceholder titleKey="modules.settings" />}
                />
              </Route>
            </Route>
          </Route>

          {/* Fallback Route */}
          <Route path="*" element={<NotFound />} />
        </Routes>
      </Router>
    </>
  );
}
