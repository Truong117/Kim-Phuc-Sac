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

## Numeric API permalinks and opaque post links

Facebook can return an ordinary post permalink containing numeric IDs while the employee's share URL resolves to a vanity URL containing `pfbid`. Opaque IDs are compared independently of the owner segment and of the `story_fbid` versus `/posts/` URL shape.

When these identities still differ, the matcher only opens permalink URLs already returned by the connected Page's API catalogue. Supported public redirects and canonical/og:url metadata can associate their numeric identity with an opaque identity. It does not decode or guess opaque IDs, compare captions/dates, execute JavaScript, or send Page credentials to the public website. The original API object remains the metric source and the source of Page ownership validation.

The same matcher is used before report save and during sync. A bounded per-Page cache retains verified aliases for one day and unresolved attempts for one minute. The form's three-resolution budget and the worker's ten-resolution/45-second budget are shared with share-link resolution. Numeric ordinary-post permalinks take priority. A check that runs out of budget reports that identity matching is unfinished; retry continues past cached candidates. Public login walls can still prevent automatic matching.

Advisory `reason_code` values distinguish connection/configuration errors, provider failures, pending identity work, unavailable public aliases and a completed no-match result. The form no longer suggests that every unmatched post falls outside the 300-post catalogue. The integration panel separately shows pending or unavailable identity aliases. No extra worker or migration is introduced.

## Bounded continuation and diagnostic progress

A failed alias lookup may be retried after its one-minute cache expires, but that expiry must not erase scan progress. The per-Page index now retains each attempt for up to one day and prioritizes never-attempted candidates before old failures. A lock serializes index updates. Completing a catalogue pass with no verified aliases is an unavailable result, rather than permanently pending work.

The form automatically continues a pending check with at most three requests in total, separated by two seconds. It stops on a match, connection/provider error, public access/rate-limit indication, or after that bound. The final incomplete message explicitly allows saving without waiting. Leaving the form or changing the links cancels the active request and continuation timers.

Safe `progress` counters show catalogue size, checked candidates, remaining candidates and verified opaque aliases. Failure counters use fixed categories such as login/access required, HTTP status, missing metadata, transport failure or response limit. Raw provider HTML, headers and credentials are never returned. These diagnostics describe what the server observed; pending work alone does not establish that Facebook blocked a link or that the post is private.
