import { VnFlagIcon } from "@/icons";

export const languages = [
  {
    id: "vi",
    name: "Tiếng Việt",
    shortName: "VIE",
    dir: "ltr",
    FlagIcon: VnFlagIcon,
  },
] as const;

export type Language = (typeof languages)[number];
export type Locale = Language["id"];

export const locales: readonly Locale[] = languages.map(
  (language) => language.id,
);
export const defaultLocale: Locale = languages[0].id;

interface LocaleStorage {
  getItem: (key: string) => string | null;
  setItem: (key: string, value: string) => void;
}

export function isLocale(value: string): value is Locale {
  return languages.some((language) => language.id === value);
}

export function normalizeLocale(value?: string | null): Locale {
  const languageCode = value?.trim().toLowerCase().split(/[-_]/)[0];

  return languageCode && isLocale(languageCode)
    ? languageCode
    : defaultLocale;
}

export function migrateStoredLocale(
  storage: LocaleStorage,
  browserLanguage?: string | null,
): Locale {
  const savedLanguage =
    storage.getItem("i18nextLng") || storage.getItem("language");
  const locale = normalizeLocale(savedLanguage || browserLanguage);

  storage.setItem("i18nextLng", locale);
  storage.setItem("language", locale);

  return locale;
}

export function getLanguage(locale: Locale | string): Language {
  const normalizedLocale = normalizeLocale(locale);

  return (
    languages.find((language) => language.id === normalizedLocale) ||
    languages[0]
  );
}
