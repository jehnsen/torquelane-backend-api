# Frontend parity (Phase 4, verified in Phase 5)

Every screen in `../web/app/(app)/` (plus sign-in and the app shell) and every
mutation in `../web/lib/store.ts`, `../web/lib/auth.ts` and
`../web/app/api/admin/users`, mapped to the API endpoint(s) that serve it. The
frontend switches over in Phase 5: it renders what these endpoints return and
computes no authoritative total, status, due date or permission (R2).

Audited against pms-monitoring-frontend@d45871e. Paths are under `/api/v1`.

- **Side**: who the endpoint serves. `staff` (provider side), `portal`
  (customer side) or `both`. A caller on the other side gets 403 (or 404 for a
  record outside their scope).
- **Status**: `done` (the endpoint exists, is tested, and gives the screen
  everything it computed itself before), or `dropped` (no endpoint, with the
  reason, pending approval). No row is dropped.
- `tests/Feature/Parity/ParitySmokeTest.php` reads the tables below and
  calls every endpoint in them as a provider admin and as a fleet manager.
  Every endpoint must exist. Each GET must answer 200 to a caller on its side
  and 403/404 to the other side. No call may answer 5xx. Placeholders like
  `{vehicle}` are filled with seeded Actimed records both callers can reach.
- **Phase 5**: verified from the real frontend (pms-monitoring-frontend,
  `e2e/`) against this API on a fresh demo seed. Every screen was opened as
  provider admin (PA), service advisor (SA), provider technician (T), fleet
  manager (FM) and viewer (V), with no error state, no 4xx/5xx, and no
  uncaught exception. Each action was driven through its screen. The
  column lists the roles whose UI sessions called the row's endpoints and
  got a success; NW is Northwind's fleet manager (the out-of-scope check).
  "portal: gated" means the portal roles saw the staff-only gate instead.
  `e2e/parity-report.mjs` reproduces the mapping from
  `parity-log/parity-calls.jsonl`. "—" rows have no screen in ../web, and
  each says why.

## Sign-in, session and app shell

| Screen / action | Endpoint(s) | Side | Status | Notes | Phase 5 |
|---|---|---|---|---|---|
| `/login`: CSRF cookie | `GET /sanctum/csrf-cookie` | both | done | Sanctum SPA cookie session; bearer tokens are never read. | ✓ PA SA T FM V NW |
| `/login`: sign in (`auth.signIn`) | `POST /auth/login` | both | done | Refuses a session with no tenant scope (403 with `details.reason`). | ✓ PA SA T FM V NW |
| `/login`: forgot / reset password | `POST /auth/forgot-password`, `POST /auth/reset-password` | both | done | Link points at `FRONTEND_URL/reset-password`. | ✓ forgot (PA); reset page built (`/reset-password`), needs a mailed token — API LoginTest |
| Accept invitation | `POST /auth/invitations/accept` | both | done | Replaces ../web's admin-set password (see Access). | — page built (`/accept-invite`); needs a mailed token, covered by the API's InvitationTest |
| Sign out (`auth.signOut`) | `POST /auth/logout` | both | done | | ✓ PA |
| `/` role-aware home, session, nav, capabilities, modules, branding | `GET /me` | both | done | `side`, `role`, `capabilities`, `modules`, `branding`, `branches`; the frontend stops carrying `lib/rbac.ts` and `lib/tenant.ts`. | ✓ PA SA T FM V NW |
| Branch switcher | header `X-Branch-Id` on every request | staff | done | One allowed branch id or `all`; anything else is 403 `branch_not_allowed`. | ✓ PA (switcher; both branches) |
| Alerts panel | `GET /alerts` | both | done | Derived on read; ids are identity (`pms:…`, `doc:…`, `wo:…`). | ✓ PA SA T FM V NW |
| Alerts: mark read / dismiss / restore (`markAlertsRead`, `dismissAlert`, `restoreAlerts`) | `POST /alerts/read`, `POST /alerts/dismiss`, `POST /alerts/restore` | both | done | Per user, per scope bucket. | ✓ FM |
| Demo "switch account" (`auth.switchAccount`) | `POST /auth/logout`, `POST /auth/login` | both | done | ../web signed out and in with the demo password; the same two calls do it. | ✓ demo builds only (`NEXT_PUBLIC_DEMO_MODE`); same two calls as sign-out + sign-in, both verified |
| `/workflow` marketing page | none | both | done | Static content; no data. | ✓ static page renders for all five roles |

## Fleet (customer side, also open to staff)

| Screen / action | Endpoint(s) | Side | Status | Notes | Phase 5 |
|---|---|---|---|---|---|
| `/dashboard`: KPIs, demand bands, 30-day spend, 12-month cost, 6-week load, stale odometers, expiring documents, attention list, active work orders | `GET /analytics/dashboard` | both | done | One call. Staff may add `customer_account_id`; ../web redirects staff to `/shop`. | ✓ PA SA T FM V |
| `/dashboard`: compliance bar | `GET /analytics/dashboard` (`summary`) | both | done | Same figures as `GET /fleet/summary`. | ✓ PA SA T FM V |
| `/vehicles`: list, PMS filter (incl. stale), department, search, least healthy first | `GET /vehicles?pms=overdue&sort=health&search=hiace` | both | done | Band, staleness and health are derived per request, then paged. Department options come from the listed vehicles (presentational). | ✓ PA SA T FM V |
| `/vehicles`: add vehicle (`addVehicle`) | `POST /vehicles` | both | done | Plate and VIN unique per organization among unarchived vehicles. | ✓ FM |
| `/vehicles/[id]`: detail, health, PMS schedule | `GET /vehicles/{vehicle}`, `GET /vehicles/{vehicle}/health` | both | done | | ✓ PA SA T FM V |
| `/vehicles/[id]`: edit (`updateVehicle`, profile fields) | `PATCH /vehicles/{vehicle}` | both | done | | ✓ FM |
| `/vehicles/[id]`: odometer dialog (`updateVehicle` odometer) | `POST /vehicles/{vehicle}/readings`, `GET /vehicles/{vehicle}/readings` | both | done | Odometer and rate are derived from readings; implausible jumps need `confirm_warning`. | ✓ SA FM (not called: `GET /vehicles/{…}/readings` — readings history not shown, as in ../web) |
| `/vehicles/[id]`: correct a reading | `POST /vehicles/{vehicle}/readings/{reading}/void` | both | done | API addition: corrections are void rows (append-only). | — API addition, no screen in ../web; not surfaced |
| `/vehicles/[id]`: documents tab | `GET /documents?vehicle_id={vehicle}` | both | done | | ✓ PA SA T FM V |
| `/vehicles/[id]`: work-order history | `GET /work-orders?vehicle_id={vehicle}` | both | done | | ✓ PA SA T FM V |
| `/vehicles/[id]`: ownership history, transfer | `GET /vehicles/{vehicle}/ownerships`, `POST /vehicles/{vehicle}/transfer` | staff | done | API addition (Phase 2). | — API addition, no screen in ../web; not surfaced |
| `/vehicles/[id]`: archive | `DELETE /vehicles/{vehicle}` | both | done | Archives and frees the plate; never deletes. | — no screen in ../web; not surfaced |
| `/schedule`: load chart, demand line, groups (overdue / 7 / 30 / 90 days), status filter | `GET /analytics/schedule?status=overdue` | both | done | Rows in `compareUrgency` order, each group with its catalogue cost. | ✓ PA SA T FM V |
| `/schedule`: "Book in" / new work order (`createWorkOrder`) | `POST /work-orders` | both | done | Unnumbered draft; lines priced by the server. | ✓ SA |
| `/schedule`: "Auto-schedule overdue" preview | `GET /analytics/auto-schedule` | both | done | Was computed in the dialog (coverage, slotting, estimate); now the server's. | ✓ FM |
| `/schedule`: "Auto-schedule overdue" commit | `POST /work-orders/auto-schedule` | both | done | Recomputed in the transaction; one draft per item; suspended accounts are left out. | ✓ FM |
| `/reports`: KPIs, cost trend, mix, rankings, frequency (3/6/12 months) | `GET /analytics/reports?months=6` | both | done | Counts only orders closed in the window, as ../web did. Cost per km in integer centavos. | ✓ PA SA T FM V |
| `/documents`: list, kind / vehicle / status / text filters, sort by expiry | `GET /documents?status=expired&sort=expiry&q=ctpl` | both | done | Expiry status uses the same rule as the compliance badge (30 days). | ✓ PA SA T FM V |
| `/documents`: tiles (count, size, expiring within 45 days) | `GET /documents/summary` | both | done | | ✓ PA SA T FM V |
| Upload document (`addDocument`), incl. on a work order | `POST /documents` | both | done | Multipart, private disk, 10 MB. Attach with `work_order_id`: filed under the order's account and vehicle. | ✓ FM |
| Download document | `GET /documents/{document}/download` | both | done | 60-second signed URL. | ✓ FM |
| Delete document (`deleteDocument`) | `DELETE /documents/{document}` | both | done | | ✓ FM |
| `/requests`: tiles, pending queue (who may decide, SLA breach), committed vs budget, turnaround | `GET /requests` | both | done | Each order is judged against its own effective settings. | ✓ PA SA T FM V NW |
| `/demand-forecast`: rows, summary sentence, horizon | `GET /demand-forecast?customer_account_id={customer_account}&horizon_weeks=6` | both | done | Per account (stock is the account's own). Staff name the account; portal users get their own. | ✓ PA SA T FM V |
| `/demand-forecast`: "Generate purchase request" (`generatePurchaseOrders`) | `POST /purchase-orders` | both | done | Send `part_ids`; quantities and prices come from the server's own forecast. One draft per vendor, numbered `PO-YYYY-NNNN`. | ✓ FM |
| `/purchase-orders`: list | `GET /purchase-orders` | both | done | `can_send` tells the UI whether issuing is within the caller's band. | ✓ PA SA T FM V |
| `/purchase-orders`: detail / print | `GET /purchase-orders/{purchase_order}` | both | done | Print is rendered by the frontend from this JSON. | ✓ FM (detail, print and export-one render from the list resource; `GET /purchase-orders/{…}` not called) |
| `/purchase-orders`: mark sent / received / cancelled (`updatePurchaseOrderStatus`) | `POST /purchase-orders/{purchase_order}/send`, `POST /purchase-orders/{purchase_order}/receive`, `POST /purchase-orders/{purchase_order}/cancel` | both | done | Send is held to the issuer's band (403 naming the limit). Receive restocks the account's parts. Cancel needs a reason. | ✓ FM (not called: `POST /purchase-orders/{…}/cancel` — no cancel in ../web) |
| `/purchase-orders`: export list (`exportPurchaseOrdersToExcel`) | `GET /purchase-orders/export?format=xlsx` | both | done | Also `format=csv`. Same rows as ../web, one per line, money in pesos with 2 decimals. | ✓ FM |
| `/purchase-orders`: export one (`exportPurchaseOrderToExcel`) | `GET /purchase-orders/{purchase_order}/export?format=xlsx` | both | done | | ✓ FM |
| Spare parts catalogue (the account's own; feeds the forecast) | `GET /fleet-parts`, `GET /fleet-parts/{fleet_part}` | both | done | ../web held parts in seed state only; the API stores them per account. | — no screen in ../web (parts lived in seed state); the forecast reads them server-side |
| Manage spare parts | `POST /fleet-parts`, `PATCH /fleet-parts/{fleet_part}`, `DELETE /fleet-parts/{fleet_part}` | both | done | `settings:manage`. Stock is an opening count only, then moved by receiving POs. A part on a PO cannot be deleted (409). | — no screen in ../web (parts lived in seed state); not surfaced |

## Work orders (both sides)

| Screen / action | Endpoint(s) | Side | Status | Notes | Phase 5 |
|---|---|---|---|---|---|
| `/work-orders`: list, buckets, type, text search, ordering | `GET /work-orders?stage=active&type=preventive&q=brake&sort=scheduled` | both | done | `q` matches reference, title, technician, vendor, plate or customer. | ✓ PA SA T FM V |
| `/work-orders`: bucket counts and filtered value | `GET /work-orders/summary?stage=active` | both | done | Value = labour + resolved parts, in centavos. | ✓ PA SA T FM V |
| New work order dialog (`createWorkOrder`) | `POST /work-orders` | both | done | Suspended accounts take no new work (403 `account_suspended`). | ✓ SA |
| `/work-orders/[id]`: detail, lines, totals, approval panel, wait banner, history | `GET /work-orders/{work_order}` | both | done | Totals, approval values, required approver and SLA come from the server. | ✓ PA SA T FM V |
| Edit a draft (`updateWorkOrder` fields) | `PATCH /work-orders/{work_order}`, `PUT /work-orders/{work_order}/lines` | both | done | Drafts only; approved lines are never re-priced. | — no draft editor in ../web (`updateWorkOrder` had no caller); create + send verified |
| Send for approval (`sendForApproval`) | `POST /work-orders/{work_order}/send` | both | done | Numbers the order; auto-approves inside the band. | ✓ SA FM |
| Approve / decline / defer lines (`decideLine`) | `POST /work-orders/{work_order}/decisions` | both | done | Within the caller's approval authority. | ✓ FM |
| Schedule (`scheduleWorkOrder`) | `POST /work-orders/{work_order}/schedule` | staff | done | Date, time and bay. | ✓ SA; portal: gated |
| Start (`updateWorkOrder` → in progress) | `POST /work-orders/{work_order}/start` | both | done | `workorder:update`. | ✓ T |
| Complete (`completeWorkOrder`) | `POST /work-orders/{work_order}/complete`, `POST /work-orders/{work_order}/close` | staff | done | Complete records findings, odometer, parts and tasks. Close runs the variance check and resets PMS. | ✓ T; portal: gated |
| Cancel (`updateWorkOrder` → cancelled) | `POST /work-orders/{work_order}/cancel` | both | done | With a reason. | ✓ SA |
| Mark collected (single) | `POST /work-orders/{work_order}/collect` | staff | done | | — no single-job collect in ../web; check-out releases per vehicle (`POST /work-orders/collect`, T) |
| Attached documents | `GET /documents?work_order_id={work_order}` | both | done | Plus upload with `work_order_id`. | ✓ PA SA T FM V |

## Shop (provider side)

| Screen / action | Endpoint(s) | Side | Status | Notes | Phase 5 |
|---|---|---|---|---|---|
| `/shop`: tiles, waiting on client, on the floor, bay load, arriving, ready for collection, revenue this week vs last | `GET /shop/home` | staff | done | One call; each order carries its vehicle and customer name. | ✓ PA SA T; portal: gated |
| `/shop` sections individually | `GET /shop/arriving`, `GET /shop/in-progress`, `GET /shop/approvals`, `GET /shop/ready-for-collection`, `GET /shop/floor` | staff | done | Phase 3; golden-tested. | — superseded by `GET /shop/home` (one call) on `/shop` |
| `/shop/check-in`: plate / VIN lookup | `GET /check-in/lookup?q=NCT9034` | both | done | Never pre-fills a stale odometer. Portal users can look up only their own vehicles. | ✓ SA |
| `/shop/check-in`: new walk-in (customer, vehicle, first reading) | `POST /check-in` | staff | done | Service-records consent in the same transaction. | ✓ SA; portal: gated |
| `/shop/check-in`: reading for a known vehicle (`updateVehicle` odometer) | `POST /vehicles/{vehicle}/readings` | staff | done | | ✓ SA FM |
| `/shop/check-in`: raise the chosen jobs (`createWorkOrder`) | `POST /work-orders`, `POST /work-orders/{work_order}/send`, `POST /work-orders/{work_order}/schedule` | staff | done | Draft, then send (auto-approves in band), then book bay and time. | ✓ SA FM |
| `/shop/check-in`: check-out panel, release a vehicle's jobs (`collectWorkOrders`) | `POST /work-orders/collect` | staff | done | All or none: every order must be closed and uncollected (else 409). | ✓ T; portal: gated |
| `/shop/queue`: active jobs, technician / bay / client / status filters, running first | `GET /work-orders?stage=active&sort=queue&q=hilux` | both | done | Also `technician_id`, `bay_id`, `customer_account_id`, `status[]`. Grouping is presentational. | ✓ PA SA T FM V |
| `/shop/clients`: client book (vehicles, open work, turnaround, spend this month, uncollected) | `GET /shop/clients` | staff | done | An order counts for the account stamped on it. | ✓ PA SA T; portal: gated |
| `/shop/clients`: add / edit client (`addFleetClient`, `updateFleetClient`) | `POST /customer-accounts`, `PATCH /customer-accounts/{customer_account}` | staff | done | Opening requires service-records consent. | ✓ PA; portal: gated |
| `/shop/clients/[id]`: rollup, vehicles, history, terms and effective bands | `GET /shop/clients/{customer_account}` | staff | done | `approval_overrides` (sparse) plus `effective_settings`. | ✓ PA SA T; portal: gated |
| `/shop/technicians`: load, current job, closed this period, actual vs estimate | `GET /shop/technicians` | staff | done | `variance_pct` now from the server. | ✓ PA SA T; portal: gated |
| `/shop/technicians`: roster, add / edit / remove (`addTechnician`, `updateTechnician`, `deleteTechnician`) | `GET /technicians`, `POST /technicians`, `PATCH /technicians/{technician}`, `DELETE /technicians/{technician}` | staff | done | | ✓ PA SA T; portal: gated |
| Bays (names, capacity) | `GET /bays`, `GET /bays/{bay}` | staff | done | ../web's static `lib/bays.ts`. | ✓ PA SA T (not called: `GET /bays/{…}` — lists only); portal: gated |
| `/shop/vendors`: list, add / edit / remove (`addVendor`, `updateVendor`, `deleteVendor`) | `GET /vendors`, `GET /vendors/{vendor}`, `POST /vendors`, `PATCH /vendors/{vendor}`, `DELETE /vendors/{vendor}` | staff | done | `settings:manage`. | ✓ PA SA T (not called: `GET /vendors/{…}` — lists only); portal: gated |
| `/shop/reports`: revenue by client / service item, 21-day utilisation, turnaround by client, mix, parts margin | `GET /shop/reports?months=6` | staff | done | | ✓ PA SA T; portal: gated |
| Revenue between dates | `GET /shop/revenue` | staff | done | Phase 3. | — superseded by `/shop/home` and `/shop/reports` |

## Catalogue, settings, access, profile

| Screen / action | Endpoint(s) | Side | Status | Notes | Phase 5 |
|---|---|---|---|---|---|
| `/service-catalogue`: list | `GET /service-tasks`, `GET /service-tasks/{service_task}` | both | done | | ✓ PA SA T FM V (not called: `GET /service-tasks/{…}` — lists only) |
| `/service-catalogue`: add / edit / delete (`addServiceTask`, `updateServiceTask`, `deleteServiceTask`) | `POST /service-tasks`, `PATCH /service-tasks/{service_task}`, `DELETE /service-tasks/{service_task}` | staff | done | A task with history, or named by work orders or POs, is 409: deactivate it instead. | ✓ PA; portal: gated |
| `/settings`: warning thresholds | `GET /fleet/summary` | both | done | `thresholds` and the due-soon count. | ✓ PA SA T FM V NW |
| `/settings`: approval bands, provider (`updateApprovalSettings`) | `GET /approval-settings`, `PUT /approval-settings`, `PUT /branches/{branch}/approval-settings` | staff | done | Organization defaults and sparse branch overrides. | ✓ PA SA T (not called: `PUT /branches/{…}/approval-settings` — branch overrides not surfaced; ../web had one provider-wide set); portal: gated |
| `/settings`: approval bands, client (`updateApprovalSettings`, client scope) | `GET /customer-accounts/{customer_account}/approval-settings`, `PATCH /customer-accounts/{customer_account}/approval-settings` | both | done | New: the account's own Fleet Manager (or staff) with `settings:manage`. null clears a key. | ✓ FM V |
| `/settings`: branding (`updateTenantSettings`) | `GET /organization`, `PATCH /organization` | staff | done | `organization:manage`. Clients see their branding read-only in `GET /me`. | ✓ PA SA T; portal: gated |
| `/settings`: theme | none | both | done | Client-side preference; no data. | ✓ client-side toggle, no data |
| `/settings`: "Reset demo data" (`resetFleet`) | `GET /me` | both | done | ../web only refetched; with the API that is the screens' own GETs. The card's local-storage wording goes. | ✓ PA SA T FM V NW |
| `/access`: members | `GET /users`, `GET /users/{user}` | both | done | Portal users see their own account's people. | ✓ PA (not called: `GET /users/{…}` — lists only) |
| `/access`: add user (`/api/admin/users`) | `POST /invitations`, `GET /invitations`, `DELETE /invitations/{invitation}` | staff | done | `access:manage` (staff only, as in ../web's rbac). Invitation flow (Phase 1): the invitee sets their own password, where ../web's admin typed it. No-escalation rule enforced. | ✓ PA; portal: gated |
| `/access`: change role, disable, branch pins | `PATCH /users/{user}` | staff | done | `access:manage`, no escalation. | — no screen in ../web (members were read-only there); not surfaced |
| `/profile`: view | `GET /me` | both | done | `first_name`, `last_name`, `username`. | ✓ PA SA T FM V NW |
| `/profile`: edit names and username (`auth.updateProfile`) | `PATCH /me` | both | done | Username unique per organization, case-insensitive (422 when taken). | ✓ V |
| `/profile`: change password (`auth.changePassword`) | `PUT /me/password` | both | done | Needs the current password. | ✓ V |

## Store mutations, one line each

| Mutation (`lib/store.ts`) | Endpoint(s) | Side | Status | Notes | Phase 5 |
|---|---|---|---|---|---|
| `createWorkOrder` | `POST /work-orders` | both | done | | ✓ SA |
| `updateWorkOrder` | `PATCH /work-orders/{work_order}`, `POST /work-orders/{work_order}/start`, `POST /work-orders/{work_order}/cancel` | both | done | A status is never set directly; each move has its endpoint. | ✓ SA T (not called: `PATCH /work-orders/{…}` — no draft editor in ../web) |
| `decideLine` | `POST /work-orders/{work_order}/decisions` | both | done | | ✓ FM |
| `scheduleWorkOrder` | `POST /work-orders/{work_order}/schedule` | staff | done | | ✓ SA; portal: gated |
| `completeWorkOrder` | `POST /work-orders/{work_order}/complete`, `POST /work-orders/{work_order}/close` | staff | done | | ✓ T; portal: gated |
| `sendForApproval` | `POST /work-orders/{work_order}/send` | both | done | | ✓ SA FM |
| `collectWorkOrders` | `POST /work-orders/collect` | staff | done | | ✓ T; portal: gated |
| `updateVehicle` | `PATCH /vehicles/{vehicle}`, `POST /vehicles/{vehicle}/readings` | both | done | Odometer through readings only. | ✓ SA FM |
| `addVehicle` | `POST /vehicles` | both | done | | ✓ FM |
| `addFleetClient` | `POST /customer-accounts` | staff | done | | ✓ PA; portal: gated |
| `updateFleetClient` | `PATCH /customer-accounts/{customer_account}` | both | done | Credit terms and overrides are staff-only fields there; client bands go through `/approval-settings`. | ✓ PA |
| `addDocument` | `POST /documents` | both | done | | ✓ FM |
| `deleteDocument` | `DELETE /documents/{document}` | both | done | | ✓ FM |
| `markAlertsRead` / `dismissAlert` / `restoreAlerts` | `POST /alerts/read`, `POST /alerts/dismiss`, `POST /alerts/restore` | both | done | | ✓ FM |
| `updateApprovalSettings` | `PUT /approval-settings`, `PATCH /customer-accounts/{customer_account}/approval-settings` | both | done | Provider scope / client scope. | ✓ PA FM |
| `generatePurchaseOrders` | `POST /purchase-orders` | both | done | | ✓ FM |
| `updatePurchaseOrderStatus` | `POST /purchase-orders/{purchase_order}/send`, `POST /purchase-orders/{purchase_order}/receive`, `POST /purchase-orders/{purchase_order}/cancel` | both | done | | ✓ FM (not called: `POST /purchase-orders/{…}/cancel` — no cancel in ../web) |
| `updateTenantSettings` | `PATCH /organization` | staff | done | | ✓ PA; portal: gated |
| `addServiceTask` / `updateServiceTask` / `deleteServiceTask` | `POST /service-tasks`, `PATCH /service-tasks/{service_task}`, `DELETE /service-tasks/{service_task}` | staff | done | | ✓ PA; portal: gated |
| `addTechnician` / `updateTechnician` / `deleteTechnician` | `POST /technicians`, `PATCH /technicians/{technician}`, `DELETE /technicians/{technician}` | staff | done | | ✓ PA; portal: gated |
| `addVendor` / `updateVendor` / `deleteVendor` | `POST /vendors`, `PATCH /vendors/{vendor}`, `DELETE /vendors/{vendor}` | staff | done | | ✓ PA; portal: gated |
| `resetFleet` | `GET /me` | both | done | A refetch, not a write. | ✓ PA SA T FM V NW |

## What moved from the browser to the server

The frontend used to compute these itself. It now renders them:

- every `lib/analytics.ts` series (`/analytics/*`), the parts forecast
  and its summary sentence (`/demand-forecast`), and the PO export rows;
- the approvals queue figures (`/requests`): who may decide, waits, SLA
  breaches, committed spend against budget;
- the shop home and reports figures (`/shop/home`, `/shop/reports`,
  `/shop/clients`), including technician variance;
- the auto-schedule proposals and their estimate;
- list totals: work-order buckets and filtered value, document tiles;
- derived list filters: PMS band, stale odometer, least healthy first,
  document expiry status.
