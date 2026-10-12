import i18n from "i18next";
import { initReactI18next } from "react-i18next";

import viCommon from "../locales/vi/common.json";
import {
  defaultLocale,
  getLanguage,
  locales,
  migrateStoredLocale,
} from "./languages";

export const resources = {
  vi: {
    common: viCommon,
  },
} as const;

export const defaultNS = "common";
export const fallbackLng = defaultLocale;

const initialLng =
  typeof window !== "undefined"
    ? migrateStoredLocale(localStorage, navigator.language)
    : fallbackLng;

if (typeof document !== "undefined") {
  document.documentElement.lang = initialLng;
  document.documentElement.dir = getLanguage(initialLng).dir;
}

i18n.use(initReactI18next).init({
  resources,
  lng: initialLng,
  fallbackLng,
  supportedLngs: [...locales],
  defaultNS,
  ns: ["common"],
  interpolation: {
    escapeValue: false, // React already escapes values
    prefix: "{",
    suffix: "}",
  },
});

export default i18n;
