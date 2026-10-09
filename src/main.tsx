import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import App from "./App.tsx";
import { AppWrapper } from "./components/common/PageMeta.tsx";
import AuthProvider from "./context/AuthProvider.tsx";
import AuthorizationProvider from "./context/AuthorizationProvider.tsx";
import { LanguageProvider } from "./context/LanguageContext.tsx";
import { ThemeProvider } from "./context/ThemeContext.tsx";
import "./i18n";
import "./index.css";

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <ThemeProvider>
      <LanguageProvider>
        <AppWrapper>
          <AuthProvider>
            <AuthorizationProvider>
              <App />
            </AuthorizationProvider>
          </AuthProvider>
        </AppWrapper>
      </LanguageProvider>
    </ThemeProvider>
  </StrictMode>,
);
