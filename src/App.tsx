import { Navigate, Route, BrowserRouter as Router, Routes } from "react-router";
import { ScrollToTop } from "./components/common/ScrollToTop";
import AppLayout from "./layout/AppLayout";
import SignIn from "./pages/AuthPages/SignIn";
import SignUp from "./pages/AuthPages/SignUp";
import Calendar from "./pages/Calendar";
import BarChart from "./pages/Charts/BarChart";
import LineChart from "./pages/Charts/LineChart";
import Home from "./pages/Dashboard/Management";
import FormElements from "./pages/Forms/FormElements";
import Blank from "./pages/OtherPage/Blank";
import ModulePlaceholder from "./pages/OtherPage/ModulePlaceholder";
import NotFound from "./pages/OtherPage/NotFound";
import NewReport from "./pages/Reports/NewReport";
import BasicTables from "./pages/Tables/BasicTables";
import Alerts from "./pages/UiElements/Alerts";
import Avatars from "./pages/UiElements/Avatars";
import Badges from "./pages/UiElements/Badges";
import Buttons from "./pages/UiElements/Buttons";
import Images from "./pages/UiElements/Images";
import Videos from "./pages/UiElements/Videos";
import UserProfiles from "./pages/UserProfiles";

export default function App() {
  return (
    <>
      <Router>
        <ScrollToTop />
        <Routes>
          {/* Dashboard Layout */}
          <Route element={<AppLayout />}>
            <Route index element={<Navigate to="/dashboard" replace />} />
            <Route path="/dashboard" element={<Home />} />

            {/* KPS Modules */}
            <Route path="/reports/new" element={<NewReport />} />
            <Route
              path="/reports"
              element={<ModulePlaceholder titleKey="modules.reportHistory" />}
            />
            <Route
              path="/reports/:id"
              element={<ModulePlaceholder titleKey="modules.reportDetail" />}
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
              element={<ModulePlaceholder titleKey="modules.customerDetail" />}
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

            {/* Others Page */}
            <Route path="/profile" element={<UserProfiles />} />
            <Route path="/calendar" element={<Calendar />} />
            <Route path="/blank" element={<Blank />} />

            {/* Forms */}
            <Route path="/form-elements" element={<FormElements />} />

            {/* Tables */}
            <Route path="/basic-tables" element={<BasicTables />} />

            {/* Ui Elements */}
            <Route path="/alerts" element={<Alerts />} />
            <Route path="/avatars" element={<Avatars />} />
            <Route path="/badge" element={<Badges />} />
            <Route path="/buttons" element={<Buttons />} />
            <Route path="/images" element={<Images />} />
            <Route path="/videos" element={<Videos />} />

            {/* Charts */}
            <Route path="/line-chart" element={<LineChart />} />
            <Route path="/bar-chart" element={<BarChart />} />
          </Route>

          {/* Auth Layout */}
          <Route path="/signin" element={<SignIn />} />
          <Route path="/signup" element={<SignUp />} />

          {/* Fallback Route */}
          <Route path="*" element={<NotFound />} />
        </Routes>
      </Router>
    </>
  );
}
