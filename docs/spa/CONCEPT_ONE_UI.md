# Spa concept 1 — UI review scope

This iteration extends the Spa workspace in the existing KPS repository. It uses synthetic data in React memory; reload/reset discards all changes. There are no Spa API writes, database migrations, role/permission changes, seeds, outbound messages or real payments.

## Screen coverage

| Module             | Working preview interactions                                                                                                    |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------- |
| Overview           | Visit/service counts, receipts today, outstanding, stock/care alerts, quick links                                               |
| Appointments       | Customer/service/date search, ordered services, optional package, list and technician agenda, create/edit/cancel, visit history |
| Reception          | Check in, record receiver, assign/reassign each service to KTV/bed, conflict validation                                         |
| Customers          | Search/create/edit, notes, visit history, package balance, cashier link when authorized                                         |
| Services / retail  | Search, create/edit service, book service, sell product to customer                                                             |
| Packages           | Sell package, balance/expiry/history, book next session                                                                         |
| KTV                | Phone task/detail/back, four bottom tabs; tablet split view; start, draft result, complete, request/accept/withdraw handover    |
| Cashier            | Invoice detail, partial receipts, outstanding, receipt history, payment method, CSV                                             |
| Rooms / beds       | Day filter, occupancy and per-bed agenda, visit detail                                                                          |
| Inventory          | Search/low-stock filter, batch/expiry, stock in/out with reason, movement log, CSV                                              |
| Staff / commission | Synthetic roster, day attendance, assigned/completed counts, commission illustration                                            |
| Care               | Create follow-up job, due/channel/assignee, record result, open/done filter                                                     |
| Cashbook           | Actual receipt/expense ledger, in/out/net totals, expense form, CSV                                                             |
| Reports            | Day / 7 / 30 days, receipts vs invoices vs debt, cash-flow bars, KTV performance, CSV                                           |
| Settings           | Facility/hours/commission preview, read-only current KPS access, operating audit                                                |

Staff UI reuses the existing `settings` capability; cashbook reuses `cashier`. Both sidebar and route guard share this mapping. The backend workspace permission contract is unchanged. Only existing Owner/Admin/Assistant accounts are required to review this iteration; no new KTV account is created.

## Invariants demonstrated

- Booking does not consume package sessions. Completion consumes one session once; insufficient/expired/wrong-customer packages fail atomically.
- Once all services finish, one invoice contains only services outside packages. Retail/package sales create invoices, without inventing receipts.
- Receipts are positive integer VND, cannot predate their invoice or exceed balance. Reports use receipt date for actual cash and invoice date for billing.
- Retail sale posts stock-out. Stock-out cannot exceed available quantity and manual movements require a reason.
- Used service prices/durations remain fixed; historic task names/prices are snapshots.
- Reception, dispatch, KTV participants/final owner, completion actor and cashier are recorded separately.
- During handover the original KTV remains responsible; the recipient must accept. Incoming slots are reserved and rechecked on acceptance. Completed/history views retain participants.
- Cancelled appointments release resources; running/completed tasks cannot be cancelled or reassigned.
- Draft results stay only in memory, with a 600 ms autosave and flush on task/route switch. KTV UI contains no phone numbers, billing, payment or debt information.

## Review sequence

1. Open `/spa` with Spa preview enabled and an existing authorized account.
2. Create customer → book → receive → assign individual services and inspect the technician agenda/room agenda.
3. Open KTV; authorized reviewers can select a synthetic KTV. Start a service, enter result, complete or request/accept handover.
4. Check package history and invoice. Record a partial receipt, then compare cashier, cashbook and report totals.
5. Sell a retail item, compare inventory movement and unpaid invoice; try invalid overpayment/stock-out to see error focus.
6. Try care result, attendance and settings. Reload/reset and confirm the original fixture returns.

## Deliberate next-stage integrations

Persistent data and business APIs, facility/tenant isolation, server enforcement of final permissions, real KTV identity binding, skin-photo storage/consent, notifications/SMS/Zalo, financial reconciliation/refunds, tax invoices and payroll calculations remain backend/integration work. The UI labels these as a preview; the settings table does not edit access rights. Attendance and commission are operational illustrations, not HR/payroll records.
