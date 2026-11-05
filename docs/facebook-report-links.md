# Facebook links in work reports

This feature applies only to Facebook. Other evidence platforms keep their existing save behavior and are not sent to the Facebook checker.

Employees can paste a Facebook share link into the evidence field in either daily or makeup reports. After a short pause, the form checks the URL and displays an advisory result. Invalid URL syntax still uses the normal report validation. Unverified links, timeouts, rate limits and unavailable connections do not prevent saving a syntactically valid report.

The checker and social sync use the same share resolver. It accepts supported Facebook HTTPS share paths, follows at most three allowlisted redirects, and reads canonical/og:url metadata from a bounded HTML response. It does not execute JavaScript, log in to Facebook, or send a Page token to the public share URL. Login walls and other unresolved responses remain unverified. Public visibility alone does not guarantee API access or complete metrics.

A resolved URL is only a candidate. Positive matching requires a post or attachment in the connected Page's API catalogue (at most 300 recent posts). The UI check does not collect metrics or create post records. It only reports a current advisory result; it is not a permanent assertion that every metric is available.

Submitted URLs remain unchanged. Sync keeps their original source hashes and associates all aliases with the same external post ID. Removing or changing a report link immediately removes that source from read results. Share mappings are cached for one day, unresolved attempts for one minute, and Page catalogues for two minutes with connection revision in the key. A later sync checks against the Page API again.

No migration, new package or extra worker is required. The existing social worker resolves saved share links too. Per run it attempts at most ten uncached share links within a 45-second resolution budget. Unresolved and deferred share counts appear in the integration panel. Form checks attempt at most three uncached shares per request within a ten-second provider budget; use “Kiểm tra lại đường dẫn” to continue the remaining links. Cache locks prevent concurrent requests for the same alias/catalogue.

## Staging acceptance

1. Paste `https://www.facebook.com/share/r/1HCg7qFhTp/` into a report evidence field. When Facebook exposes a supported redirect or canonical metadata, it should resolve to Reel `1129745132867197` and match the connected Page.
2. Confirm the form retains the original share URL, and saving still works when the check is unavailable.
3. Sync the report. A share URL and direct Reel URL must produce one post and one hourly snapshot, with both original evidence associations retained.
4. Remove the evidence link and verify its report no longer contributes that post.
5. Verify the login-wall/unresolvable case gives a warning, never a false private/public conclusion or fabricated metrics.

Automated tests use controlled provider responses. Real Facebook share resolution must also be checked on staging because platform login and anti-bot responses can differ from test fixtures.
