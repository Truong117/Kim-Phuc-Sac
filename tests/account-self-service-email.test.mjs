import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import ts from "typescript";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { createServer } from "vite";

const read = (path) => readFile(new URL(`../${path}`, import.meta.url), "utf8");

test("account email service sends only the self-service fields and returns the updated identity", async () => {
  const calls = [];
  const identity = { id: 42, email: "new@example.com" };
  const source = (await read("src/services/accountService.ts")).replace(
    'import { apiRequest } from "@/services/apiClient";',
    "const apiRequest = (...args) => globalThis.__accountEmailRequest(...args);",
  );
  globalThis.__accountEmailRequest = (...args) => {
    calls.push(args);
    return Promise.resolve(identity);
  };
  try {
    const compiled = ts.transpileModule(source, {
      compilerOptions: { module: ts.ModuleKind.ES2022 },
    }).outputText;
    const service = await import(
      `data:text/javascript;base64,${Buffer.from(compiled).toString("base64")}`
    );
    assert.equal(
      await service.updateEmail(
        "new@example.com",
        "current-secret",
        "old@example.com",
      ),
      identity,
    );
    assert.deepEqual(calls[0], [
      "/api/account/email",
      {
        method: "PATCH",
        body: JSON.stringify({
          email: "new@example.com",
          current_password: "current-secret",
          expected_email: "old@example.com",
        }),
      },
    ]);
    await service.updateProfile("Updated Name", "Original Name");
    assert.deepEqual(JSON.parse(calls[1][1].body), {
      name: "Updated Name",
      expected_name: "Original Name",
    });
    await service.updatePassword({
      current_password: "current-secret",
      password: "new-secret",
      password_confirmation: "new-secret",
    });
    assert.equal(calls[2][0], "/api/account/password");
    for (const [path, options] of calls) {
      assert.doesNotMatch(path, /current-secret|user_id|token|pending/);
      assert.equal(JSON.parse(options.body).user_id, undefined);
    }
  } finally {
    delete globalThis.__accountEmailRequest;
  }
});

test("profile and account own distinct editable fields and have no mail confirmation flow", async () => {
  const [personal, work, summary, account, section, navigation, translations] =
    await Promise.all([
      read("src/components/profile/ProfilePersonalInfo.tsx"),
      read("src/components/profile/ProfileWorkInfo.tsx"),
      read("src/components/account/AccountSummary.tsx"),
      read("src/pages/Account/Account.tsx"),
      read("src/components/account/AccountEmailSection.tsx"),
      read("src/components/account/AccountNavigation.tsx"),
      read("src/locales/vi/common.json"),
    ]);
  assert.match(personal, /name="name"/);
  assert.doesNotMatch(personal, /name="email"|name="password"/);
  assert.doesNotMatch(work, /<Input|<Select|onChange/);
  assert.doesNotMatch(summary, /user.name/);
  assert.match(summary, /user.email/);
  assert.match(account, /<AccountEmailSection/);
  assert.match(account, /<AccountPasswordSection/);
  assert.match(navigation, /to: "\/profile"/);
  assert.match(navigation, /to: "\/account"/);
  assert.match(section, /updateCurrentUser\(user\)/);
  assert.match(section, /finally\s*\{\s*setPassword\(""\)/);
  assert.doesNotMatch(
    section,
    /localStorage|sessionStorage|email-change|confirmEmail|pending|window.location/,
  );
  const messages = JSON.parse(translations);
  assert.equal(messages.account.summary.name, undefined);
  assert.equal(messages.profile.personal.email, undefined);
  assert.equal(messages.account.emailChange.pending, undefined);
  assert.match(messages.account.emailChange.success, /Lần đăng nhập tiếp theo/);
});

test("email form renders associated labels, errors, required fields and locks saving controls", async () => {
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
      email: "new@example.com",
      password: "",
      busy: false,
      errors: {},
      onEmailChange() {},
      onPasswordChange() {},
      onSubmit() {},
    };
    const markup = renderToStaticMarkup(React.createElement(Form, props));
    assert.match(markup, /for="account-new-email"/);
    assert.match(markup, /name="email"/);
    assert.match(markup, /type="email"/);
    assert.match(markup, /maxLength="255"/i);
    assert.match(markup, /for="account-email-password"/);
    assert.match(markup, /autoComplete="current-password"/i);
    assert.match(markup, /required=""/);
    const invalid = renderToStaticMarkup(
      React.createElement(Form, {
        ...props,
        errors: { email: "Email unavailable" },
      }),
    );
    assert.match(invalid, /aria-invalid="true"/);
    assert.match(invalid, /aria-describedby="account-new-email-hint"/);
    assert.match(invalid, /id="account-new-email-hint"/);
    assert.match(invalid, /Email unavailable/);
    const saving = renderToStaticMarkup(
      React.createElement(Form, { ...props, busy: true }),
    );
    assert.match(saving, /<fieldset disabled=""/);
    assert.match(saving, /aria-busy="true"/);
    assert.doesNotMatch(markup, /name="user_id"|name="token"|name="role"/);
  } finally {
    await vite.close();
  }
});
