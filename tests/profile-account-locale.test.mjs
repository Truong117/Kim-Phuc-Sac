import assert from "node:assert/strict";
import { access, readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";

const readSource = (relativePath) =>
  readFile(new URL(`../${relativePath}`, import.meta.url), "utf8");

test("profile and account dropdown destinations are separate self-service routes", async () => {
  const [dropdown, app] = await Promise.all([
    readSource("src/components/header/UserDropdown.tsx"),
    readSource("src/App.tsx"),
  ]);
  const dropdownItems = [
    ...dropdown.matchAll(/<DropdownItem[\s\S]*?<\/DropdownItem>/g),
  ].map((match) => match[0]);
  const profileItem = dropdownItems.find((item) =>
    item.includes('t("userDropdown.editProfile")'),
  );
  const accountItem = dropdownItems.find((item) =>
    item.includes('t("userDropdown.accountSettings")'),
  );

  assert.ok(profileItem);
  assert.match(profileItem, /to="\/profile"/);
  assert.match(profileItem, /onItemClick=\{closeDropdown\}/);
  assert.ok(accountItem);
  assert.match(accountItem, /to="\/account"/);
  assert.match(accountItem, /onItemClick=\{closeDropdown\}/);
  assert.match(dropdown, /const initials =/);
  assert.match(dropdown, /\{initials\.toUpperCase\(\)\}/);

  assert.match(app, /<Route path="\/profile" element=\{<Profile \/>\} \/>/);
  assert.match(app, /<Route path="\/account" element=\{<Account \/>\} \/>/);
});

test("profile excludes password management and account owns it", async () => {
  const [profile, account, passwordSection, translations] = await Promise.all([
    readSource("src/pages/Profile/Profile.tsx"),
    readSource("src/pages/Account/Account.tsx"),
    readSource("src/components/account/AccountPasswordSection.tsx"),
    readSource("src/locales/vi/common.json"),
  ]);
  const messages = JSON.parse(translations);

  assert.doesNotMatch(profile, /Password|updatePassword|profile\.password/);
  assert.match(profile, /<ProfilePersonalInfo user=\{currentUser\} \/>/);
  assert.match(profile, /<ProfileWorkInfo user=\{currentUser\} \/>/);
  assert.match(account, /<AccountSummary user=\{currentUser\} \/>/);
  assert.match(account, /<AccountPasswordSection[\s\S]*?onPasswordChanged=/);
  assert.match(passwordSection, /updatePassword\(\{/);
  assert.match(passwordSection, /name="current_password"/);
  assert.match(passwordSection, /name="password_confirmation"/);
  assert.equal(messages.account.title, "Cài đặt tài khoản");
  assert.equal(
    messages.account.subtitle,
    "Quản lý thông tin đăng nhập và bảo mật tài khoản",
  );
  assert.equal(messages.account.summary.name, undefined);
  assert.equal(messages.account.summary.email, "Email đăng nhập");
  assert.equal(messages.account.password.title, "Đổi mật khẩu");
  assert.equal(messages.profile.password, undefined);
});

test("Vietnamese is the only active locale and uses the local Vietnam flag", async () => {
  const [languages, i18n, dropdown, icons] = await Promise.all([
    readSource("src/i18n/languages.ts"),
    readSource("src/i18n/index.ts"),
    readSource("src/components/header/UserDropdown.tsx"),
    readSource("src/icons/index.ts"),
  ]);

  await access(new URL("../src/locales/vi/common.json", import.meta.url));
  await assert.rejects(
    access(new URL("../src/locales/en/common.json", import.meta.url)),
  );

  assert.match(languages, /id:\s*"vi"/);
  assert.match(languages, /name:\s*"Tiếng Việt"/);
  assert.match(languages, /shortName:\s*"VIE"/);
  assert.match(languages, /FlagIcon:\s*VnFlagIcon/);
  assert.doesNotMatch(languages, /UsFlagIcon|id:\s*"en"/);
  assert.match(icons, /VnFlagIcon.*flag-vn\.svg/);
  assert.match(i18n, /import viCommon from "\.\.\/locales\/vi\/common\.json"/);
  assert.match(i18n, /vi:\s*\{\s*common:\s*viCommon/);
  assert.match(i18n, /fallbackLng\s*=\s*defaultLocale/);
  assert.match(dropdown, /currentLang\.shortName/);
  assert.match(dropdown, /<CurrentFlagIcon/);
  assert.match(dropdown, /languages\.map/);
});

test("legacy English storage migrates to vi without retaining unsupported values", async () => {
  const languagesSource = await readSource("src/i18n/languages.ts");
  const executableSource = languagesSource.replace(
    'import { VnFlagIcon } from "@/icons";',
    "const VnFlagIcon = () => null;",
  );
  const transpiled = ts.transpileModule(executableSource, {
    compilerOptions: {
      module: ts.ModuleKind.ES2022,
      target: ts.ScriptTarget.ES2022,
    },
  }).outputText;
  const localeModule = await import(
    `data:text/javascript;base64,${Buffer.from(transpiled).toString("base64")}`
  );
  const values = new Map([
    ["i18nextLng", "en"],
    ["language", "en"],
  ]);
  const storage = {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
  };

  assert.deepEqual(localeModule.locales, ["vi"]);
  assert.equal(localeModule.defaultLocale, "vi");
  assert.equal(localeModule.normalizeLocale("en"), "vi");
  assert.equal(localeModule.normalizeLocale("vi-VN"), "vi");
  assert.equal(localeModule.migrateStoredLocale(storage, "en-US"), "vi");
  assert.equal(values.get("i18nextLng"), "vi");
  assert.equal(values.get("language"), "vi");
});

test("locale initialization applies Vietnamese HTML language and LTR direction", async () => {
  const [html, i18n, context] = await Promise.all([
    readSource("index.html"),
    readSource("src/i18n/index.ts"),
    readSource("src/context/LanguageContext.tsx"),
  ]);

  assert.match(html, /<html lang="vi">/);
  assert.match(i18n, /document\.documentElement\.lang\s*=\s*initialLng/);
  assert.match(
    i18n,
    /document\.documentElement\.dir\s*=\s*getLanguage\(initialLng\)\.dir/,
  );
  assert.match(context, /document\.documentElement\.lang\s*=\s*language/);
  assert.match(context, /document\.documentElement\.dir\s*=\s*dir/);
  assert.match(context, /localStorage\.setItem\("i18nextLng", language\)/);
  assert.match(context, /localStorage\.setItem\("language", language\)/);
});
