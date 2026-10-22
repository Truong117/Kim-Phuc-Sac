# Work Report local draft

Daily Work Report V2 currently stores unfinished form data in the browser's
`localStorage`. It does not submit, synchronize, or upload a draft to the KPS
backend.

## Storage contract

- Key: `kps:work-report:draft:v1:{userId}:{businessDate}`.
- `userId` comes from the authenticated user. No shared or anonymous fallback
  identifier is used.
- `businessDate` comes from `GET /api/reports/today`, not from the device clock.
- The record includes schema version, revision, update time, server report
  reference, editor mode, all work items, and pending Quick Report text.
- Drafts older than seven days, malformed JSON, and incompatible schema
  versions are removed when draft storage is inspected.

The storage adapter is isolated in `src/services/workReportDraftStorage.ts` so
it can be replaced by a backend draft API later without changing the report
form model.

## Restore and conflict policy

The page loads today's backend state before checking the corresponding local
draft. A local draft is never restored silently: the user chooses between the
device draft and current server data. If the report is no longer editable, the
draft can only be viewed, copied, or deleted.

Each write uses an optimistic revision. A newer revision from another tab is
not overwritten silently; the user must choose which tab's content to keep.
Successful report submission removes only the matching user/date revision.
Failed requests retain it.

## Security limitations

`localStorage` is not encrypted. Anyone with access to the same unlocked
browser profile, browser developer tools, or a script executing in the same
origin may be able to inspect draft content. Users on shared devices should
delete sensitive drafts and sign out or close the browser profile when their
work is complete. No password, access token, session value, or authentication
secret is stored in a report draft.

Local drafts are device- and browser-profile-specific. They are not available
on another device and are not a backup guaranteed against browser storage
clearing, private-mode restrictions, quota failures, or abrupt device loss.
