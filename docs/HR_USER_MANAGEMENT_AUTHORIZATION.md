# HR User Management Authorization

KPS uses the `HR_MANAGER` role for “Hành chính nhân sự” authority. The `HR_ADMIN` department is organization master data only; belonging to that department never grants User Management permissions.

## Permission profile

HR_MANAGER has organization scope for the existing User Management permissions:

- `users.view`
- `users.create`
- `users.update`
- `users.disable`
- `users.assign_role`

It retains normal employee behavior for Dashboard and Work Reports: personal Dashboard mode, own report creation/viewing, no manager comment authority, and no department/organization report visibility.

## Protected targets and privileged assignments

An OWNER or ADMIN role anywhere on a target KPS membership makes that account read-only to HR_MANAGER. HR may still list and view the account, but profile, role, and status mutations return a safe `403`.

OWNER, ADMIN, and HR_MANAGER are privileged assignment roles. Only an actor whose active/default KPS membership contains OWNER or ADMIN may assign them. Role references are actor-specific, so HR receives only normal assignable roles.

Managed-user responses expose safe UI state:

```json
{
  "is_protected": true,
  "actions": {
    "can_edit": false,
    "can_change_role": false,
    "can_change_status": false
  }
}
```

These fields do not expose permission codes, data scopes, role codes, or the internal policy reason.

## Single active HR manager

KPS currently permits at most one active/default `HR_MANAGER` membership. User creation, role assignment, and membership reactivation all check for another active holder. The existing holder is excluded when that same membership is edited.

The rule is enforced in the backend transaction while the KPS organization row is locked. There is no new unique constraint or migration.

## Deployment note

`AuthorizationSeeder` creates the role and synchronizes its grants idempotently, but deliberately assigns it to no user. After review, run the seeder in the target environment and let OWNER/ADMIN assign the intended holder through User Management.
