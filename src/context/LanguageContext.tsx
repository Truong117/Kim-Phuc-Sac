import {
  getLanguage,
  languages,
  normalizeLocale,
  type Language,
  type Locale,
} from "@/i18n/languages";
import type React from "react";
import { createContext, useContext, useEffect, useState } from "react";
import { useTranslation } from "react-i18next";

type LanguageContextType = {
  language: Locale;
  currentLanguage: Language;
  dir: "ltr" | "rtl";
  setLanguage: (code: Locale) => void;
  availableLanguages: readonly Language[];
};

const LanguageContext = createContext<LanguageContextType | undefined>(undefined);

export const LanguageProvider: React.FC<{ children: React.ReactNode }> = ({
  children,
}) => {
  const { i18n } = useTranslation();
  const [language, setLanguageState] = useState<Locale>(() =>
    normalizeLocale(i18n.resolvedLanguage || i18n.language),
  );

  const currentLanguage = getLanguage(language);
  const dir = currentLanguage.dir;

  useEffect(() => {
    const handleLanguageChanged = (nextLanguage: string) => {
      const normalizedLanguage = normalizeLocale(nextLanguage);

      setLanguageState((currentLanguage) =>
        currentLanguage === normalizedLanguage
          ? currentLanguage
          : normalizedLanguage,
      );

      if (nextLanguage !== normalizedLanguage) {
        void i18n.changeLanguage(normalizedLanguage);
      }
    };

    i18n.on("languageChanged", handleLanguageChanged);
    return () => {
      i18n.off("languageChanged", handleLanguageChanged);
    };
  }, [i18n]);

  useEffect(() => {
    document.documentElement.lang = language;
    document.documentElement.dir = dir;
    localStorage.setItem("i18nextLng", language);
    localStorage.setItem("language", language);
  }, [language, dir]);

  const setLanguage = (code: Locale) => {
    setLanguageState(code);
    void i18n.changeLanguage(code);
  };

  return (
    <LanguageContext.Provider
      value={{
        language,
        currentLanguage,
        dir,
        setLanguage,
        availableLanguages: languages,
      }}
    >
      {children}
    </LanguageContext.Provider>
  );
};

export const useLanguage = () => {
  const context = useContext(LanguageContext);
  if (context === undefined) {
    throw new Error("useLanguage must be used within a LanguageProvider");
  }
  return context;
};
