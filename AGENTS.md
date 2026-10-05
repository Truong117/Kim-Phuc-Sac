# AGENTS.md — KPS Internal System

> React 19 internal operations frontend · Vite · Tailwind CSS v4 · react-i18next · ApexCharts · based on TailAdmin React Free

## Repo Map

```
src/
├── App.tsx                    # root router — all routes registered here
├── main.tsx                   # entry point: providers + CSS imports
├── index.css                  # Tailwind v4 @theme tokens, @utility classes, third-party overrides
├── pages/
│   ├── Dashboard/             # KPS management dashboard
│   ├── Reports/               # daily report, history, detail placeholder
│   └── OtherPage/             # module placeholder and 404
├── components/
│   ├── ui/                    # retained primitives: badge, button, dropdown, modal, table
│   ├── form/                  # Form, Label, Select, InputField, TextArea
│   ├── common/                # shared widgets: PageBreadCrumb, ComponentCard, PageMeta,
│   │                          #   ThemeToggleButton, ScrollToTop, BrandMark
│   ├── header/                # notification and user dropdowns
│   ├── dashboard/             # Phase 1 dashboard widgets
│   └── reports/               # Phase 1 report components
├── layout/                    # AppLayout, AppSidebar, AppHeader, Backdrop
├── context/                   # ThemeContext, SidebarContext, LanguageContext
├── hooks/                     # reusable hooks such as useModal and useClickOutside
├── i18n/                      # index.ts — i18next bootstrap (resources, defaultNS, fallbackLng)
├── locales/en/common.json     # current translation resource (mostly Vietnamese KPS UI)
├── icons/                     # .svg files + index.ts barrel (SVGR named exports)
├── mocks/                     # Phase 1 mock data
├── types/                     # KPS domain types
└── utils/                     # utility helpers
```

## Stack

- **React 19** with strict **TypeScript** (~5.8), bundled by **Vite 8**.
- **React Router v8** (`react-router`) for client-side routing via `BrowserRouter`.
- **react-i18next v17** + **i18next v26** for internationalization and RTL support.
- **Tailwind CSS v4** — configured entirely through `src/index.css`; **no `tailwind.config` file exists**.
- **react-apexcharts** for the management dashboard chart.
- **react-helmet-async** (`PageMeta`) for per-page `<title>` and `<meta description>`.
- Path alias: `@/*` → `src/*` (configured in `tsconfig.app.json` + `vite.config.ts`).
- Scripts: `npm run dev` (Vite dev server), `npm run build` (tsc + Vite), `npm run lint`.
- Node >= 20.19.0 || >= 22.12.0 required (Vite 8 requirement).

## Routing Conventions

- All routes are registered in `src/App.tsx` using `<Routes>` / `<Route>`.
- **`<AppLayout>`** is the standard dashboard shell (sidebar + header). The wildcard 404 route is standalone.
- **New page** → create a file or folder under `src/pages/<Category>/MyPage.tsx`, then add a `<Route>` in `App.tsx` under the appropriate layout group.
- Page files are **PascalCase** (`MyPage.tsx`) with a **default export**.
- Colocate route-only sub-components inside the page folder. Reusable UI goes in `src/components/<feature>/`.

## Conventions

- **Component files**: PascalCase (`DashboardStats.tsx`) with a **default export**.
- **Hook files**: camelCase (`useModal.ts`).
- **New reusable component** → `src/components/<feature>/` if domain-specific, else `src/components/common/` or `src/components/ui/`.
- **New icon** → drop the `.svg` into `src/icons/`, add a named export to `src/icons/index.ts` using a PascalCase name (e.g., `export { ReactComponent as MyIcon } from "./my-icon.svg"`). Never inline SVG markup in components.
- **Page meta (SEO)** → every page must render `<PageMeta title="…" description="…" />` (from `src/components/common/PageMeta.tsx`) as the first child.
- **Breadcrumbs** → add `<PageBreadCrumb pageTitle="…" />` at the top of admin pages, matching existing pages.
- Use `<ComponentCard title="…">` for bordered content sections that match the existing KPS pages.
- Modals use the `useModal` hook (`isOpen`, `openModal`, `closeModal`, `toggleModal`).
- Global state goes through the existing contexts (`useSidebar`, `useTheme`, `useLanguage`) — do not add new providers without a clear need.
- Prefer primitives from `src/components/ui/` and `src/components/form/` over raw HTML or new third-party equivalents.

## Internationalization (react-i18next) Rules

- **Setup**: i18next is bootstrapped in `src/i18n/index.ts` and imported once in `src/main.tsx`. Do not re-initialize it.
- **Current locale resource**: only `en`, using the single `"common"` namespace. KPS strings in this resource are currently Vietnamese.
- `LanguageContext` still contains planned `ar`, `es`, and `de` metadata, but their dictionaries are not implemented. Do not claim multi-language support or invent translations without a localization decision.
- **Using translations**:
  - In any component (all are client-side in Vite/React): use the `useTranslation` hook.
    ```tsx
    import { useTranslation } from "react-i18next";
    const { t } = useTranslation(); // uses default "common" namespace
    return <p>{t("myKey")}</p>;
    ```
  - Organize keys by feature namespace inside `common.json` (e.g., `"customers": { "list": { "title": "Khách hàng" } }`), then access with `t("customers.list.title")`.
- **Language switching**: Use `useLanguage()` from `src/context/LanguageContext.tsx`. Call `setLanguage(code)` — it updates i18next, `localStorage`, and `document.documentElement.lang`/`dir` automatically.
- **RTL**: Arabic (`ar`) sets `dir="rtl"` on `<html>`. `LanguageContext` exposes `dir: "ltr" | "rtl"` for conditional logic. Use CSS logical properties everywhere (see Styling Rules) — the RTL flip is CSS-driven and requires no JS conditionals for layout.

## Styling Rules

- Tailwind CSS **v4** — the entire theme lives in `src/index.css` under `@theme`. **Never create a `tailwind.config.js/ts`**.
- Always use theme tokens instead of hardcoded values:
  - **Colors**: `brand`, `gray`, `blue-light`, `orange`, `success`, `error`, `warning` scales (`25`–`950`), plus `theme-pink-500` / `theme-purple-500`.
  - **Typography**: `font-outfit`, `text-theme-xs/sm/xl`, `text-title-sm/md/lg/xl/2xl`.
  - **Shadows**: `shadow-theme-xs/sm/md/lg/xl`, `shadow-focus-ring`, `shadow-slider-navigation`, `shadow-tooltip`, `shadow-datepicker`.
  - **Breakpoints**: custom `2xsm` (375px), `xsm` (425px), `3xl` (2000px) alongside defaults.
  - **Z-index**: `z-1`, `z-9`, `z-99`, `z-999`, `z-9999`, `z-99999`, `z-999999` tokens.
- **Dark mode is class-based** (`@custom-variant dark (&:is(.dark *))`, toggled by `ThemeContext` adding/removing `.dark` on `<html>`). Every styled element must include its `dark:` variant.
- **CSS Logical Properties for RTL & Internationalization**:
  - Never use physical directional utilities when logical equivalents exist; physical `left`/`right` properties break the Arabic (`ar`) RTL layout:
    - **Margins**: use `ms-*` / `me-*` instead of `ml-*` / `mr-*`.
    - **Padding**: use `ps-*` / `pe-*` instead of `pl-*` / `pr-*`.
    - **Positioning / Insets**: use `start-*` / `end-*` instead of `left-*` / `right-*`.
    - **Borders**: use `border-s-*` / `border-e-*` instead of `border-l-*` / `border-r-*`.
    - **Border Radius**: use `rounded-s-*` / `rounded-e-*` / `rounded-ss-*` / `rounded-se-*` / `rounded-es-*` / `rounded-ee-*` instead of `rounded-l-*` / `rounded-r-*`.
    - **Text Alignment**: use `text-start` / `text-end` instead of `text-left` / `text-right`.
  - **Directional Icons & Transforms**:
    - Directional glyphs (back/forward arrows, breadcrumb chevrons, next/prev buttons) must flip in RTL: use `rtl:rotate-180` or `rtl:-scale-x-100`.
    - Off-canvas drawers and sliding elements must mirror their translation (e.g., `-translate-x-full rtl:translate-x-full`).
    - Use `ltr:*` / `rtl:*` modifiers only when a logical property does not exist or for third-party integration overrides.
- Reusable `@utility` classes already defined in `index.css` (`menu-item`, `menu-item-active`, `menu-item-inactive`, `menu-item-icon`, `menu-dropdown-item`, `menu-dropdown-badge`, `custom-scrollbar`, `no-scrollbar`, …) — reuse them before creating new ones.
- ApexCharts overrides live at the bottom of `index.css`. Add any approved third-party overrides there, matching the existing `@apply` style.
- Never hardcode hex colors in `className`. Chart option objects (`ApexOptions.colors`) are the established exception — copy hex values from the `@theme` palette (e.g., `#465fff` = `brand-500`, `#12b76a` = `success-500`).

## Component Rules

- **One feature, one folder**: page UI goes in `src/components/<feature>/`, split into focused, single-responsibility sub-components (e.g., `DashboardStats.tsx`, `ReportHistoryTable.tsx`). Avoid one monolithic file per page.
- **Composition over prop drilling**: pass `children`, separate container/state logic from presentational components, extract large JSX sections into their own files, and define explicit typed prop interfaces per sub-component.
- **Charts** (`react-apexcharts`): import lazily with `React.lazy` + `Suspense`, or guard with `if (typeof window === "undefined") return null`. Follow the pattern used in existing chart components before choosing an approach.
- **Icons**: always import from `@/icons` using the named export (e.g., `import { CalendarIcon } from "@/icons"`). SVGs are compiled to React components via `vite-plugin-svgr`. Never inline raw SVG markup in components.
- **Page SEO**: always add `<PageMeta title="Page Title | KIM PHỤC SẮC" description="…" />` as the first element in every page component.
- **Modals**: use `useModal` hook from `src/hooks/useModal.ts` and the `<Modal>` primitive from `src/components/ui/modal/`.
- **Global state**: consume only via existing hooks — `useSidebar()`, `useTheme()`, `useLanguage()`.

## Don'ts

- Don't install new packages without asking the user.
- Don't create a `tailwind.config.js` or `tailwind.config.ts` — Tailwind v4 is fully configured through `src/index.css`.
- Don't hardcode hex colors or pixel values in `className` — always use `@theme` tokens.
- Don't hardcode user-facing text — add keys to the current dictionary and use `t()`. Expand to other dictionaries only after the localization strategy is confirmed.
- Don't use physical directional utilities (`ml-*`, `mr-*`, `pl-*`, `pr-*`, `left-*`, `right-*`, `border-l-*`, `border-r-*`, `rounded-l-*`, `rounded-r-*`, `text-left`, `text-right`) — always prefer CSS logical equivalents (`ms-*`, `me-*`, `ps-*`, `pe-*`, `start-*`, `end-*`, `border-s-*`, `border-e-*`, `rounded-s-*`, `rounded-e-*`, `text-start`, `text-end`).
- Don't import `react-apexcharts` at the module level without verifying it is safe in that component's context — follow the lazy-loading pattern in the dashboard.
- Don't add new pages outside `src/pages/` or new routes outside `src/App.tsx`.
- Don't add new context providers without a clear, broad need — prefer local state or composition.
- Don't inline SVG markup in components — always add the `.svg` to `src/icons/` and export it from the barrel `index.ts`.
- Don't use `styled-components`, CSS Modules, or any CSS-in-JS — Tailwind utility classes with `@utility` extensions in `index.css` are the only styling mechanism.
