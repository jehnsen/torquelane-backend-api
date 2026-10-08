# Frontend parity (Phase 4)

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

## Sign-in, session and app shell

| Screen / action | Endpoint(s) | Side | Status | Notes |
|---|---|---|---|---|
| `/login`: CSRF cookie | `GET /sanctum/csrf-cookie` | both | done | Sanctum SPA cookie session; bearer tokens are never read. |
| `/login`: sign in (`auth.signIn`) | `POST /auth/login` | both | done | Refuses a session with no tenant scope (403 with `details.reason`). |
| `/login`: forgot / reset password | `POST /auth/forgot-password`, `POST /auth/reset-password` | both | done | Link points at `FRONTEND_URL/reset-password`. |
| Accept invitation | `POST /auth/invitations/accept` | both | done | Replaces ../web's admin-set password (see Access). |
| Sign out (`auth.signOut`) | `POST /auth/logout` | both | done | |
| `/` role-aware home, session, nav, capabilities, modules, branding | `GET /me` | both | done | `side`, `role`, `capabilities`, `modules`, `branding`, `branches`; the frontend stops carrying `lib/rbac.ts` and `lib/tenant.ts`. |
| Branch switcher | header `X-Branch-Id` on every request | staff | done | One allowed branch id or `all`; anything else is 403 `branch_not_allowed`. |
| Alerts panel | `GET /alerts` | both | done | Derived on read; ids are identity (`pms:…`, `doc:…`, `wo:…`). |
| Alerts: mark read / dismiss / restore (`markAlertsRead`, `dismissAlert`, `restoreAlerts`) | `POST /alerts/read`, `POST /alerts/dismiss`, `POST /alerts/restore` | both | done | Per user, per scope bucket. |
| Demo "switch account" (`auth.switchAccount`) | `POST /auth/logout`, `POST /auth/login` | both | done | ../web signed out and in with the demo password; the same two calls do it. |
| `/workflow` marketing page | none | both | done | Static content; no data. |

## Fleet (customer side, also open to staff)

| Screen / action | Endpoint(s) | Side | Status | Notes |
|---|---|---|---|---|
| `/dashboard`: KPIs, demand bands, 30-day spend, 12-month cost, 6-week load, stale odometers, expiring documents, attention list, active work orders | `GET /analytics/dashboard` | both | done | One call. Staff may add `customer_account_id`; ../web redirects staff to `/shop`. |
| `/dashboard`: compliance bar | `GET /analytics/dashboard` (`summary`) | both | done | Same figures as `GET /fleet/summary`. |
| `/vehicles`: list, PMS filter (incl. stale), department, search, least healthy first | `GET /vehicles?pms=overdue&sort=health&search=hiace` | both | done | Band, staleness and health are derived per request, then paged. Department options come from the listed vehicles (presentational). |
| `/vehicles`: add vehicle (`addVehicle`) | `POST /vehicles` | both | done | Plate and VIN unique per organization among unarchived vehicles. |
| `/vehicles/[id]`: detail, health, PMS schedule | `GET /vehicles/{vehicle}`, `GET /vehicles/{vehicle}/health` | both | done | |
| `/vehicles/[id]`: edit (`updateVehicle`, profile fields) | `PATCH /vehicles/{vehicle}` | both | done | |
| `/vehicles/[id]`: odometer dialog (`updateVehicle` odometer) | `POST /vehicles/{vehicle}/readings`, `GET /vehicles/{vehicle}/readings` | both | done | Odometer and rate are derived from readings; implausible jumps need `confirm_warning`. |
| `/vehicles/[id]`: correct a reading | `POST /vehicles/{vehicle}/readings/{reading}/void` | both | done | API addition: corrections are void rows (append-only). |
| `/vehicles/[id]`: documents tab | `GET /documents?vehicle_id={vehicle}` | both | done | |
| `/vehicles/[id]`: work-order history | `GET /work-orders?vehicle_id={vehicle}` | both | done | |
| `/vehicles/[id]`: ownership history, transfer | `GET /vehicles/{vehicle}/ownerships`, `POST /vehicles/{vehicle}/transfer` | staff | done | API addition (Phase 2). |
| `/vehicles/[id]`: archive | `DELETE /vehicles/{vehicle}` | both | done | Archives and frees the plate; never deletes. |
| `/schedule`: load chart, demand line, groups (overdue / 7 / 30 / 90 days), status filter | `GET /analytics/schedule?status=overdue` | both | done | Rows in `compareUrgency` order, each group with its catalogue cost. |
| `/schedule`: "Book in" / new work order (`createWorkOrder`) | `POST /work-orders` | both | done | Unnumbered draft; lines priced by the server. |
| `/schedule`: "Auto-schedule overdue" preview | `GET /analytics/auto-schedule` | both | done | Was computed in the dialog (coverage, slotting, estimate); now the server's. |
| `/schedule`: "Auto-schedule overdue" commit | `POST /work-orders/auto-schedule` | both | done | Recomputed in the transaction; one draft per item; suspended accounts are left out. |
| `/reports`: KPIs, cost trend, mix, rankings, frequency (3/6/12 months) | `GET /analytics/reports?months=6` | both | done | Counts only orders closed in the window, as ../web did. Cost per km in integer centavos. |
| `/documents`: list, kind / vehicle / status / text filters, sort by expiry | `GET /documents?status=expired&sort=expiry&q=ctpl` | both | done | Expiry status uses the same rule as the compliance badge (30 days). |
| `/documents`: tiles (count, size, expiring within 45 days) | `GET /documents/summary` | both | done | |
| Upload document (`addDocument`), incl. on a work order | `POST /documents` | both | done | Multipart, private disk, 10 MB. Attach with `work_order_id`: filed under the order's account and vehicle. |
| Download document | `GET /documents/{document}/download` | both | done | 60-second signed URL. |
| Delete document (`deleteDocument`) | `DELETE /documents/{document}` | both | done | |
| `/requests`: tiles, pending queue (who may decide, SLA breach), committed vs budget, turnaround | `GET /requests` | both | done | Each order is judged against its own effective settings. |
| `/demand-forecast`: rows, summary sentence, horizon | `GET /demand-forecast?customer_account_id={customer_account}&horizon_weeks=6` | both | done | Per account (stock is the account's own). Staff name the account; portal users get their own. |
| `/demand-forecast`: "Generate purchase request" (`generatePurchaseOrders`) | `POST /purchase-orders` | both | done | Send `part_ids`; quantities and prices come from the server's own forecast. One draft per vendor, numbered `PO-YYYY-NNNN`. |
| `/purchase-orders`: list | `GET /purchase-orders` | both | done | `can_send` tells the UI whether issuing is within the caller's band. |
| `/purchase-orders`: detail / print | `GET /purchase-orders/{purchase_order}` | both | done | Print is rendered by the frontend from this JSON. |
| `/purchase-orders`: mark sent / received / cancelled (`updatePurchaseOrderStatus`) | `POST /purchase-orders/{purchase_order}/send`, `POST /purchase-orders/{purchase_order}/receive`, `POST /purchase-orders/{purchase_order}/cancel` | both | done | Send is held to the issuer's band (403 naming the limit). Receive restocks the account's parts. Cancel needs a reason. |
| `/purchase-orders`: export list (`exportPurchaseOrdersToExcel`) | `GET /purchase-orders/export?format=xlsx` | both | done | Also `format=csv`. Same rows as ../web, one per line, money in pesos with 2 decimals. |
| `/purchase-orders`: export one (`exportPurchaseOrderToExcel`) | `GET /purchase-orders/{purchase_order}/export?format=xlsx` | both | done | |
| Spare parts catalogue (the account's own; feeds the forecast) | `GET /fleet-parts`, `GET /fleet-parts/{fleet_part}` | both | done | ../web held parts in seed state only; the API stores them per account. |
| Manage spare parts | `POST /fleet-parts`, `PATCH /fleet-parts/{fleet_part}`, `DELETE /fleet-parts/{fleet_part}` | both | done | `settings:manage`. Stock is an opening count only, then moved by receiving POs. A part on a PO cannot be deleted (409). |

## Work orders (both sides)

| Screen / action | Endpoint(s) | Side | Status | Notes |
|---|---|---|---|---|
| `/work-orders`: list, buckets, type, text search, ordering | `GET /work-orders?stage=active&type=preventive&q=brake&sort=scheduled` | both | done | `q` matches reference, title, technician, vendor, plate or customer. |
| `/work-orders`: bucket counts and filtered value | `GET /work-orders/summary?stage=active` | both | done | Value = labour + resolved parts, in centavos. |
| New work order dialog (`createWorkOrder`) | `POST /work-orders` | both | done | Suspended accounts take no new work (403 `account_suspended`). |
| `/work-orders/[id]`: detail, lines, totals, approval panel, wait banner, history | `GET /work-orders/{work_order}` | both | done | Totals, approval values, required approver and SLA come from the server. |
| Edit a draft (`updateWorkOrder` fields) | `PATCH /work-orders/{work_order}`, `PUT /work-orders/{work_order}/lines` | both | done | Drafts only; approved lines are never re-priced. |
| Send for approval (`sendForApproval`) | `POST /work-orders/{work_order}/send` | both | done | Numbers the order; auto-approves inside the band. |
| Approve / decline / defer lines (`decideLine`) | `POST /work-orders/{work_order}/decisions` | both | done | Within the caller's approval authority. |
| Schedule (`scheduleWorkOrder`) | `POST /work-orders/{work_order}/schedule` | staff | done | Date, time and bay. |
| Start (`updateWorkOrder` → in progress) | `POST /work-orders/{work_order}/start` | both | done | `workorder:update`. |
| Complete (`completeWorkOrder`) | `POST /work-orders/{work_order}/complete`, `POST /work-orders/{work_order}/close` | staff | done | Complete records findings, odometer, parts and tasks. Close runs the variance check and resets PMS. |
| Cancel (`updateWorkOrder` → cancelled) | `POST /work-orders/{work_order}/cancel` | both | done | With a reason. |
| Mark collected (single) | `POST /work-orders/{work_order}/collect` | staff | done | |
| Attached documents | `GET /documents?work_order_id={work_order}` | both | done | Plus upload with `work_order_id`. |

## Shop (provider side)

| Screen / action | Endpoint(s) | Side | Status | Notes |
|---|---|---|---|---|
| `/shop`: tiles, waiting on client, on the floor, bay load, arriving, ready for collection, revenue this week vs last | `GET /shop/home` | staff | done | One call; each order carries its vehicle and customer name. |
| `/shop` sections individually | `GET /shop/arriving`, `GET /shop/in-progress`, `GET /shop/approvals`, `GET /shop/ready-for-collection`, `GET /shop/floor` | staff | done | Phase 3; golden-tested. |
| `/shop/check-in`: plate / VIN lookup | `GET /check-in/lookup?q=NCT9034` | both | done | Never pre-fills a stale odometer. Portal users can look up only their own vehicles. |
| `/shop/check-in`: new walk-in (customer, vehicle, first reading) | `POST /check-in` | staff | done | Service-records consent in the same transaction. |
| `/shop/check-in`: reading for a known vehicle (`updateVehicle` odometer) | `POST /vehicles/{vehicle}/readings` | staff | done | |
| `/shop/check-in`: raise the chosen jobs (`createWorkOrder`) | `POST /work-orders`, `POST /work-orders/{work_order}/send`, `POST /work-orders/{work_order}/schedule` | staff | done | Draft, then send (auto-approves in band), then book bay and time. |
| `/shop/check-in`: check-out panel, release a vehicle's jobs (`collectWorkOrders`) | `POST /work-orders/collect` | staff | done | All or none: every order must be closed and uncollected (else 409). |
| `/shop/queue`: active jobs, technician / bay / client / status filters, running first | `GET /work-orders?stage=active&sort=queue&q=hilux` | both | done | Also `technician_id`, `bay_id`, `customer_account_id`, `status[]`. Grouping is presentational. |
| `/shop/clients`: client book (vehicles, open work, turnaround, spend this month, uncollected) | `GET /shop/clients` | staff | done | An order counts for the account stamped on it. |
| `/shop/clients`: add / edit client (`addFleetClient`, `updateFleetClient`) | `POST /customer-accounts`, `PATCH /customer-accounts/{customer_account}` | staff | done | Opening requires service-records consent. |
| `/shop/clients/[id]`: rollup, vehicles, history, terms and effective bands | `GET /shop/clients/{customer_account}` | staff | done | `approval_overrides` (sparse) plus `effective_settings`. |
| `/shop/technicians`: load, current job, closed this period, actual vs estimate | `GET /shop/technicians` | staff | done | `variance_pct` now from the server. |
| `/shop/technicians`: roster, add / edit / remove (`addTechnician`, `updateTechnician`, `deleteTechnician`) | `GET /technicians`, `POST /technicians`, `PATCH /technicians/{technician}`, `DELETE /technicians/{technician}` | staff | done | |
| Bays (names, capacity) | `GET /bays`, `GET /bays/{bay}` | staff | done | ../web's static `lib/bays.ts`. |
| `/shop/vendors`: list, add / edit / remove (`addVendor`, `updateVendor`, `deleteVendor`) | `GET /vendors`, `GET /vendors/{vendor}`, `POST /vendors`, `PATCH /vendors/{vendor}`, `DELETE /vendors/{vendor}` | staff | done | `settings:manage`. |
| `/shop/reports`: revenue by client / service item, 21-day utilisation, turnaround by client, mix, parts margin | `GET /shop/reports?months=6` | staff | done | |
| Revenue between dates | `GET /shop/revenue` | staff | done | Phase 3. |

## Catalogue, settings, access, profile

| Screen / action | Endpoint(s) | Side | Status | Notes |
|---|---|---|---|---|
| `/service-catalogue`: list | `GET /service-tasks`, `GET /service-tasks/{service_task}` | both | done | |
| `/service-catalogue`: add / edit / delete (`addServiceTask`, `updateServiceTask`, `deleteServiceTask`) | `POST /service-tasks`, `PATCH /service-tasks/{service_task}`, `DELETE /service-tasks/{service_task}` | staff | done | A task with history, or named by work orders or POs, is 409: deactivate it instead. |
| `/settings`: warning thresholds | `GET /fleet/summary` | both | done | `thresholds` and the due-soon count. |
| `/settings`: approval bands, provider (`updateApprovalSettings`) | `GET /approval-settings`, `PUT /approval-settings`, `PUT /branches/{branch}/approval-settings` | staff | done | Organization defaults and sparse branch overrides. |
| `/settings`: approval bands, client (`updateApprovalSettings`, client scope) | `GET /customer-accounts/{customer_account}/approval-settings`, `PATCH /customer-accounts/{customer_account}/approval-settings` | both | done | New: the account's own Fleet Manager (or staff) with `settings:manage`. null clears a key. |
| `/settings`: branding (`updateTenantSettings`) | `GET /organization`, `PATCH /organization` | staff | done | `organization:manage`. Clients see their branding read-only in `GET /me`. |
| `/settings`: theme | none | both | done | Client-side preference; no data. |
| `/settings`: "Reset demo data" (`resetFleet`) | `GET /me` | both | done | ../web only refetched; with the API that is the screens' own GETs. The card's local-storage wording goes. |
| `/access`: members | `GET /users`, `GET /users/{user}` | both | done | Portal users see their own account's people. |
| `/access`: add user (`/api/admin/users`) | `POST /invitations`, `GET /invitations`, `DELETE /invitations/{invitation}` | staff | done | `access:manage` (staff only, as in ../web's rbac). Invitation flow (Phase 1): the invitee sets their own password, where ../web's admin typed it. No-escalation rule enforced. |
| `/access`: change role, disable, branch pins | `PATCH /users/{user}` | staff | done | `access:manage`, no escalation. |
| `/profile`: view | `GET /me` | both | done | `first_name`, `last_name`, `username`. |
| `/profile`: edit names and username (`auth.updateProfile`) | `PATCH /me` | both | done | Username unique per organization, case-insensitive (422 when taken). |
| `/profile`: change password (`auth.changePassword`) | `PUT /me/password` | both | done | Needs the current password. |

## Store mutations, one line each

| Mutation (`lib/store.ts`) | Endpoint(s) | Side | Status | Notes |
|---|---|---|---|---|
| `createWorkOrder` | `POST /work-orders` | both | done | |
| `updateWorkOrder` | `PATCH /work-orders/{work_order}`, `POST /work-orders/{work_order}/start`, `POST /work-orders/{work_order}/cancel` | both | done | A status is never set directly; each move has its endpoint. |
| `decideLine` | `POST /work-orders/{work_order}/decisions` | both | done | |
| `scheduleWorkOrder` | `POST /work-orders/{work_order}/schedule` | staff | done | |
| `completeWorkOrder` | `POST /work-orders/{work_order}/complete`, `POST /work-orders/{work_order}/close` | staff | done | |
| `sendForApproval` | `POST /work-orders/{work_order}/send` | both | done | |
| `collectWorkOrders` | `POST /work-orders/collect` | staff | done | |
| `updateVehicle` | `PATCH /vehicles/{vehicle}`, `POST /vehicles/{vehicle}/readings` | both | done | Odometer through readings only. |
| `addVehicle` | `POST /vehicles` | both | done | |
| `addFleetClient` | `POST /customer-accounts` | staff | done | |
| `updateFleetClient` | `PATCH /customer-accounts/{customer_account}` | both | done | Credit terms and overrides are staff-only fields there; client bands go through `/approval-settings`. |
| `addDocument` | `POST /documents` | both | done | |
| `deleteDocument` | `DELETE /documents/{document}` | both | done | |
| `markAlertsRead` / `dismissAlert` / `restoreAlerts` | `POST /alerts/read`, `POST /alerts/dismiss`, `POST /alerts/restore` | both | done | |
| `updateApprovalSettings` | `PUT /approval-settings`, `PATCH /customer-accounts/{customer_account}/approval-settings` | both | done | Provider scope / client scope. |
| `generatePurchaseOrders` | `POST /purchase-orders` | both | done | |
| `updatePurchaseOrderStatus` | `POST /purchase-orders/{purchase_order}/send`, `POST /purchase-orders/{purchase_order}/receive`, `POST /purchase-orders/{purchase_order}/cancel` | both | done | |
| `updateTenantSettings` | `PATCH /organization` | staff | done | |
| `addServiceTask` / `updateServiceTask` / `deleteServiceTask` | `POST /service-tasks`, `PATCH /service-tasks/{service_task}`, `DELETE /service-tasks/{service_task}` | staff | done | |
| `addTechnician` / `updateTechnician` / `deleteTechnician` | `POST /technicians`, `PATCH /technicians/{technician}`, `DELETE /technicians/{technician}` | staff | done | |
| `addVendor` / `updateVendor` / `deleteVendor` | `POST /vendors`, `PATCH /vendors/{vendor}`, `DELETE /vendors/{vendor}` | staff | done | |
| `resetFleet` | `GET /me` | both | done | A refetch, not a write. |

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
