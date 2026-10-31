// The fragment is never sent to the web server or included in a Referer header.
export const parseEmailChangeFragment = (fragment: string): string | null => {
  const token = new URLSearchParams(fragment.replace(/^#/, "")).get(
    "email-change",
  );
  return token && /^[a-f0-9]{64}$/.test(token) ? token : null;
};
