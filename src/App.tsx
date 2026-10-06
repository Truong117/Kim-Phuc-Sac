import { Navigate, Route, BrowserRouter as Router, Routes } from "react-router";
import ProtectedRoute from "./components/auth/ProtectedRoute";
import { ScrollToTop } from "./components/common/ScrollToTop";
import AppLayout from "./layout/AppLayout";
import Login from "./pages/Auth/Login";
import Home from "./pages/Dashboard/Management";
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
              <Route path="/dashboard" element={<Home />} />

              {/* KPS Modules */}
              <Route path="/reports/new" element={<NewReport />} />
              <Route path="/reports" element={<ReportHistory />} />
              <Route
                path="/reports/:id"
                element={<ReportDetailPlaceholder />}
              />
              <Route
                path="/tasks"
                element={<ModulePlaceholder titleKey="modules.tasks" />}
              />
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
              <Route
                path="/products"
                element={<ModulePlaceholder titleKey="modules.products" />}
              />
              <Route
                path="/orders"
                element={<ModulePlaceholder titleKey="modules.orders" />}
              />
              <Route
                path="/ai/insights"
                element={<ModulePlaceholder titleKey="modules.aiInsights" />}
              />
              <Route
                path="/ai/assistant"
                element={<ModulePlaceholder titleKey="modules.aiAssistant" />}
              />
              <Route
                path="/employees"
                element={<ModulePlaceholder titleKey="modules.employees" />}
              />
              <Route
                path="/integrations"
                element={<ModulePlaceholder titleKey="modules.integrations" />}
              />
              <Route
                path="/settings"
                element={<ModulePlaceholder titleKey="modules.settings" />}
              />
            </Route>
          </Route>

          {/* Fallback Route */}
          <Route path="*" element={<NotFound />} />
        </Routes>
      </Router>
    </>
  );
}
