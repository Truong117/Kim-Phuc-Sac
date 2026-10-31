import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";
import i18next from "i18next";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { createServer } from "vite";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");
const executable = (source) =>
  `data:text/javascript;base64,${Buffer.from(
    ts.transpileModule(source, {
      compilerOptions: {
        module: ts.ModuleKind.ES2022,
        target: ts.ScriptTarget.ES2022,
      },
    }).outputText,
  ).toString("base64")}`;

test("email link parser accepts only full random tokens from fragments", async () => {
  const { parseEmailChangeFragment } = await import(
    executable(await read("src/utils/accountEmailChange.ts"))
  );
  const token = "ab".repeat(32);
  assert.equal(parseEmailChangeFragment(`#email-change=${token}`), token);
  for (const invalid of [
    "",
    "#email-change=",
    `#email-change=${token}a`,
    "#email-change=<script>",
    "#token=abc",
    "#email-change=https://attacker.example",
  ])
    assert.equal(parseEmailChangeFragment(invalid), null);
});

test("account services send actor-only mutations and keep verification tokens in POST bodies", async () => {
  const calls = [];
  const source = (await read("src/services/accountService.ts")).replace(
    'import { apiRequest } from "@/services/apiClient";',
    "const apiRequest = (...args) => globalThis.__accountV2Request(...args);",
  );
  globalThis.__accountV2Request = (...args) => {
    calls.push(args);
    return Promise.resolve({});
  };
  try {
    const service = await import(executable(source));
    await service.updateProfile("Updated", "Original");
    await service.requestEmailChange("new@example.com", "secret");
    await service.confirmEmailChange("ab".repeat(32), "secret");
    await service.cancelEmailChange();
    await service.getAccountEmailState();
    assert.deepEqual(
      calls.map(([path, options]) => [path, options.method ?? "GET"]),
      [
        ["/api/account/profile", "PATCH"],
        ["/api/account/email-change", "POST"],
        ["/api/account/email-change/confirm", "POST"],
        ["/api/account/email-change", "DELETE"],
        ["/api/account/email-change", "GET"],
      ],
    );
    assert.deepEqual(JSON.parse(calls[0][1].body), {
      name: "Updated",
      expected_name: "Original",
    });
    assert.deepEqual(JSON.parse(calls[2][1].body), {
      token: "ab".repeat(32),
      current_password: "secret",
    });
    for (const [path, options] of calls) {
      assert.doesNotMatch(path, /token=|secret|user_id/);
      assert.equal(JSON.parse(options.body ?? "{}").user_id, undefined);
    }
  } finally {
    delete globalThis.__accountV2Request;
  }
});

test("profile and account have distinct fields and authenticated cross navigation", async () => {
  const [personal, work, summary, account, navigation] = await Promise.all([
    read("src/components/profile/ProfilePersonalInfo.tsx"),
    read("src/components/profile/ProfileWorkInfo.tsx"),
    read("src/components/account/AccountSummary.tsx"),
    read("src/pages/Account/Account.tsx"),
    read("src/components/account/AccountNavigation.tsx"),
  ]);
  assert.match(personal, /name="name"/);
  assert.doesNotMatch(personal, /name="email"|name="password"/);
  assert.match(personal, /updateProfile\(normalizedName, user.name\)/);
  assert.doesNotMatch(work, /<Input|<Select|onChange/);
  assert.doesNotMatch(summary, /user.name/);
  assert.match(summary, /user.email/);
  assert.match(account, /<AccountEmailSection/);
  assert.match(navigation, /to: "\/profile"/);
  assert.match(navigation, /to: "\/account"/);
});

test("email confirmation requires explicit submission, removes URL token and clears passwords", async () => {
  const source = await read("src/components/account/AccountEmailSection.tsx");
  assert.match(source, /window.history.replaceState/);
  assert.match(source, /window.addEventListener\("hashchange"/);
  assert.match(source, /confirmEmailChange\(token, password\)/);
  assert.match(source, /finally\s*\{\s*setPassword\(""\)/);
  assert.doesNotMatch(source, /localStorage|sessionStorage/);
  assert.match(source, /role="alert"/);
  assert.match(source, /aria-live="polite"/);
  const login = await read("src/pages/Auth/Login.tsx");
  assert.match(login, /state\?\.from\?\.hash/);
});

test("email pending messages interpolate with the active Vietnamese i18n configuration", async () => {
  const common = JSON.parse(await read("src/locales/vi/common.json"));
  const i18n = i18next.createInstance();
  await i18n.init({
    lng: "vi",
    resources: { vi: { common } },
    defaultNS: "common",
    interpolation: { prefix: "{", suffix: "}" },
  });
  assert.equal(
    i18n.t("account.emailChange.pending", { email: "new@example.com" }),
    "Đang chờ xác minh: new@example.com",
  );
  assert.match(
    i18n.t("account.emailChange.expires", { time: "15:00" }),
    /15:00/,
  );
  for (const section of [common.profile.personal, common.account.emailChange]) {
    for (const message of Object.values(section))
      assert.doesNotMatch(message, /\{\{/);
  }
});

test("email form renders accessible request and confirmation modes with locked saving controls", async () => {
  const vite = await createServer({
    appType: "custom",
    logLevel: "silent",
    server: { middlewareMode: true },
  });
  try {
    await vite.ssrLoadModule("/src/i18n/index.ts");
    const Form = (
      await vite.ssrLoadModule("/src/components/account/AccountEmailForm.tsx")
    ).default;
    const props = {
      confirming: false,
      email: "",
      password: "",
      busy: false,
      onEmailChange() {},
      onPasswordChange() {},
      onSubmit() {},
      onDismiss() {},
    };
    const request = renderToStaticMarkup(React.createElement(Form, props));
    assert.match(request, /for="account-new-email"/);
    assert.match(request, /type="email"[^>]*required/);
    assert.match(request, /for="account-email-password"/);
    assert.match(request, /autoComplete="current-password"/i);
    const confirm = renderToStaticMarkup(
      React.createElement(Form, {
        ...props,
        confirming: true,
        pendingEmail: "new@example.com",
        busy: true,
      }),
    );
    assert.doesNotMatch(confirm, /name="email"/);
    assert.match(confirm, /new@example.com/);
    assert.match(confirm, /<fieldset disabled=""/);
    assert.match(confirm, /aria-busy="true"/);
    assert.doesNotMatch(confirm, /email-change=|token_hash/);
  } finally {
    await vite.close();
  }
});

test("dirty account forms share one navigation guard and cancellation keeps the page open", async () => {
  const originals = Object.fromEntries(
    [
      "window",
      "document",
      "Element",
      "HTMLAnchorElement",
      "__accountV2Effect",
    ].map((name) => [name, globalThis[name]]),
  );
  const effects = [];
  const listeners = new Map();
  let confirmations = 0;
  const eventTarget = {
    addEventListener(name, handler) {
      listeners.set(name, handler);
    },
    removeEventListener(name) {
      listeners.delete(name);
    },
  };
  class Anchor {
    href = "http://localhost/account";
    target = "";
    closest() {
      return this;
    }
    hasAttribute() {
      return false;
    }
  }
  try {
    globalThis.Element = Anchor;
    globalThis.HTMLAnchorElement = Anchor;
    globalThis.window = {
      ...eventTarget,
      location: { href: "http://localhost/profile" },
      confirm() {
        confirmations++;
        return false;
      },
    };
    globalThis.document = eventTarget;
    globalThis.__accountV2Effect = (effect) => effects.push(effect);
    const source = (await read("src/hooks/useAccountUnsavedChanges.ts"))
      .replace(
        'import { useEffect } from "react";',
        "const useEffect = (effect) => globalThis.__accountV2Effect(effect);",
      )
      .replace(
        'import { useTranslation } from "react-i18next";',
        'const useTranslation = () => ({ t: () => "Discard unsaved changes?" });',
      );
    const { default: guard } = await import(executable(source));
    guard(true);
    guard(true);
    const cleanup = effects.map((effect) => effect());
    assert.equal(listeners.size, 2);
    let prevented = false;
    listeners.get("click")({
      target: new Anchor(),
      button: 0,
      preventDefault() {
        prevented = true;
      },
      stopPropagation() {},
    });
    assert.equal(confirmations, 1);
    assert.equal(prevented, true);
    cleanup[0]();
    assert.equal(listeners.size, 2);
    cleanup[1]();
    assert.equal(listeners.size, 0);
  } finally {
    for (const [name, value] of Object.entries(originals)) {
      if (value === undefined) delete globalThis[name];
      else globalThis[name] = value;
    }
  }
});
