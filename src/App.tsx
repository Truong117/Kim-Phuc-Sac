import { Navigate, Route, BrowserRouter as Router, Routes } from "react-router";
import AuthorizationRoute from "./components/auth/AuthorizationRoute";
import ProtectedRoute from "./components/auth/ProtectedRoute";
import { ScrollToTop } from "./components/common/ScrollToTop";
import AppLayout from "./layout/AppLayout";
import Login from "./pages/Auth/Login";
import Home from "./pages/Dashboard/Management";
import EmployeeCreate from "./pages/Employees/EmployeeCreate";
import EmployeeEdit from "./pages/Employees/EmployeeEdit";
import EmployeeList from "./pages/Employees/EmployeeList";
import ModulePlaceholder from "./pages/OtherPage/ModulePlaceholder";
import NotFound from "./pages/OtherPage/NotFound";
import NewReport from "./pages/Reports/NewReport";
import ReportDetailPlaceholder from "./pages/Reports/ReportDetailPlaceholder";
import ReportHistory from "./pages/Reports/ReportHistory";

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
              <Route index element={<Navigate to="/dashboard" replace />} />
              <Route element={<AuthorizationRoute navigationKey="dashboard" />}>
                <Route path="/dashboard" element={<Home />} />
              </Route>

              {/* KPS Modules */}
              <Route
                element={<AuthorizationRoute navigationKey="reports.new" />}
              >
                <Route path="/reports/new" element={<NewReport />} />
              </Route>
              <Route
                element={<AuthorizationRoute navigationKey="reports.history" />}
              >
                <Route path="/reports" element={<ReportHistory />} />
                <Route
                  path="/reports/:id"
                  element={<ReportDetailPlaceholder />}
                />
              </Route>
              <Route element={<AuthorizationRoute navigationKey="tasks" />}>
                <Route
                  path="/tasks"
                  element={<ModulePlaceholder titleKey="modules.tasks" />}
                />
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
                element={<AuthorizationRoute navigationKey="ai.insights" />}
              >
                <Route
                  path="/ai/insights"
                  element={<ModulePlaceholder titleKey="modules.aiInsights" />}
                />
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
