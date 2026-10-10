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

## The shop's inventory (Phase 6, staff only)

The stock room has no counterpart in `../web`: these screens are new. Every
endpoint is staff-only (`inventory:view` to read, `inventory:manage` to change;
a portal session gets 403). The **Phase 6** column is the real frontend
driven against this API on a fresh demo seed by `e2e/inventory.spec.ts`, as
provider admin (PA), service advisor (SA) and fleet manager (FM, the
out-of-scope check). A write the browser did not drive says so; the Pest suite
covers it (`tests/Feature/Inventory`).

| Screen / action | Endpoint(s) | Side | Status | Notes | Phase 6 |
|---|---|---|---|---|---|
| `/shop/inventory`: items, search by SKU / name / barcode, type filter | `GET /items?q=filter&item_type=part`, `GET /items/{item}` | staff | done | Each item carries, for every branch the caller may see, its reorder point, bin, the price that applies and what is on hand. | ✓ PA SA; portal: gated |
| Items: add / edit / deactivate (`inventory:manage`) | `POST /items`, `PATCH /items/{item}` | staff | done | SKU and barcode unique per organization, whatever their case; never deleted; the stock unit is fixed once stock has moved. | ✓ PA (add, duplicate SKU refused); edit / deactivate: API-tested only (Pest) |
| Items: reorder point, bin, branch price | `PUT /items/{item}/branch-settings/{branch}` | staff | done | Only in a branch the caller works in (404 otherwise). | ✓ PA |
| `/shop/inventory/stock`: stock on hand, value, low / negative | `GET /stock/on-hand?low=1`, `GET /stock-locations`, `GET /stock/alerts` | staff | done | Value is on hand × moving-average cost, summed exactly and rounded once. Alerts are derived on read. | ✓ PA SA |
| Stock on hand: opening balance | `POST /stock/opening` | staff | done | Only for an item with no history in the location. | API-tested only (Pest) |
| Stock on hand: negative-stock policy | `PATCH /branches/{branch}` | staff | done | `negative_stock_policy`: `allow_and_flag` (default) or `block`. `organization:manage`. | API-tested only (Pest) |
| `/shop/inventory/movements`: the ledger | `GET /stock/moves?item_id={item}&from=2026-10-01` | staff | done | Cursor-paged, newest first; quantity signed; each move names its document. | ✓ PA |
| `/shop/inventory/purchasing`: the shop's purchase orders | `GET /shop-purchase-orders?status=open`, `GET /shop-purchase-orders/{shop_purchase_order}` | staff | done | `status` is derived from the goods receipts. Not Phase 4's `/purchase-orders`. | ✓ PA |
| Purchase orders: raise, edit a draft, issue, cancel | `POST /shop-purchase-orders`, `PATCH /shop-purchase-orders/{shop_purchase_order}`, `POST /shop-purchase-orders/{shop_purchase_order}/issue`, `POST /shop-purchase-orders/{shop_purchase_order}/cancel` | staff | done | Numbered `SPO-…` at creation; lines frozen once issued. | ✓ PA (raise a draft); edit, issue, cancel: API-tested only (Pest) |
| Receive PO: goods receipts, partial or full; void | `POST /shop-purchase-orders/{shop_purchase_order}/receipts`, `GET /goods-receipts`, `GET /goods-receipts/{goods_receipt}`, `POST /goods-receipts/{goods_receipt}/void` | staff | done | `GR-…`; no more than is outstanding; a void is a reversal, never an edit. | ✓ PA (partial receipt); void: API-tested only (Pest) |
| `/shop/inventory/counts`: count sheets | `GET /stock-counts`, `GET /stock-counts/{stock_count}` | staff | done | | ✓ PA |
| Stock count: draw, enter, post, cancel | `POST /stock-counts`, `PUT /stock-counts/{stock_count}/lines`, `POST /stock-counts/{stock_count}/post`, `POST /stock-counts/{stock_count}/cancel` | staff | done | Posting turns each variance into an adjustment move with a reason. | ✓ PA (draw, enter, post); cancel: API-tested only (Pest) |
| `/shop/inventory/transfers`: transfers between branches | `GET /stock-transfers`, `GET /stock-transfers/{stock_transfer}` | staff | done | Seen by staff of either branch. | ✓ PA |
| Transfers: make one, reverse one | `POST /stock-transfers`, `POST /stock-transfers/{stock_transfer}/reverse` | staff | done | One document, both moves; a reversal is at most once. | ✓ PA (make); reverse: API-tested only (Pest) |
| `/shop/inventory/reorder`: what to buy | `GET /stock/reorder?horizon_weeks=6` | staff | done | On hand, on order, reorder points and Phase 4's forecast (matched by SKU). | ✓ PA |
| Work order lines: parts source and the item a shop-stock line issues | `POST /work-orders`, `PUT /work-orders/{work_order}/lines` | both | done | `item_id` / `item` / `stock_cost_cents` and the order's `stock` figures are staff-only; a portal response carries none. | ✓ PA (shop stock, issued on completion, job cost shown); customer supplied / bought for this job: API-tested only (Pest) |

## Order-to-cash (Phase 7)

Invoices, payments and receivables have no counterpart in `../web`: these
screens are new. Billing is core (no module). Staff with `billing:view` read
everything in their branches; `billing:manage` raises and issues invoices and
records payments; `billing:void` voids. A portal user with `billing:view`
(fleet manager, purchasing officer, viewer) sees their own account's ISSUED
invoices, payments, balance and statement, and prints them; nothing else. The
**Phase 7** column is the real frontend driven against this API on a fresh
demo seed (PA provider admin, SA service advisor, C cashier, FM fleet manager);
a write the browser did not drive says so, and `tests/Feature/Billing` covers it.

| Screen / action | Endpoint(s) | Side | Status | Notes | Phase 7 |
|---|---|---|---|---|---|
| `/shop/billing`: billing queue (closed, not invoiced) | `GET /billing/queue?customer_account_id={customer_account}` | staff | done | Oldest finished first; a job on a standing invoice, or settled before invoicing existed, is not listed. | ✓ PA (seeded queue, account filter); portal: gated |
| Billing queue: invoice selected jobs (one account, one branch) | `POST /invoices` | staff | done | `work_order_ids`; each approved line billed at its STORED cost (parts and labour as two lines, then the job's fee). A job already on a standing invoice is 409. | ✓ PA (one job → draft) |
| `/invoices`: invoices, by status (incl. `open`, `overdue`), account, search | `GET /invoices?status=open` | both | done | Portal: own account, issued only. | ✓ PA FM (own account only; Northwind's not listed) |
| `/invoices/[id]`: invoice detail | `GET /invoices/{invoice}` | both | done | Totals (VATable / exempt / zero-rated / non-VAT sales, VAT, total due), payments applied, `can_*`. | ✓ PA C FM |
| Invoice detail: PDF | `GET /invoices/{invoice}/pdf` | both | done | `application/pdf`; same policy as the invoice. | ✓ C FM (download) |
| Invoice detail: edit a draft (notes, discounts, typed-in lines), discard | `PATCH /invoices/{invoice}`, `DELETE /invoices/{invoice}` | staff | done | Only a draft; an issued invoice is 409. | API-tested only (Pest); discount editor built |
| Invoice detail: issue | `POST /invoices/{invoice}/issue` | staff | done | `Idempotency-Key` required; `INV-YYYY-NNNN` from the invoice series; due date from the account's terms. | ✓ PA |
| Invoice detail: void | `POST /invoices/{invoice}/void` | staff | done | `billing:void`; only with nothing paid against it; keeps its number; its jobs return to the queue. | API-tested only (Pest) |
| Record payment | `POST /payments` | staff | done | `Idempotency-Key` required; spread as asked, else oldest due first; the rest is credit. A paid invoice stamps its jobs collected. | ✓ C (cash, invoice paid) |
| `/payments`: payments; detail; acknowledgment receipt PDF | `GET /payments`, `GET /payments/{payment}`, `GET /payments/{payment}/pdf` | both | done | Portal: own account. | ✓ PA C (list, receipt download); FM: list |
| Payment: apply credit, void | `POST /payments/{payment}/allocations`, `POST /payments/{payment}/void` | staff | done | Void: `billing:void`; its invoices are owed again. | API-tested only (Pest); void control built |
| Customer balance tab (`/shop/clients/[id]`, portal `/invoices`) | `GET /customer-accounts/{customer_account}/balance` | both | done | Outstanding, overdue, credit, credit limit (`over_limit` warns, never blocks). | ✓ PA (Northwind, over limit) FM (own) |
| Statement of account, and its PDF | `GET /customer-accounts/{customer_account}/statement?from=2026-07-01&to=2026-10-08`, `GET /customer-accounts/{customer_account}/statement/pdf` | both | done | Balance brought forward, each invoice / payment / void, running and closing balance. | ✓ PA FM (statement, PDF download) |
| `/shop/receivables`: AR aging | `GET /receivables/aging?as_of=2026-10-08` | staff | done | Per account: current, 1–30, 31–60, 61–90, over 90, credit. | ✓ PA |
| Receivables: revenue, accrual and cash | `GET /receivables/revenue?from=2026-10-01&to=2026-10-08` | staff | done | Invoiced (net, VAT, total) and received (by method). | ✓ PA |
| Raise a work order for an account over its credit limit | `POST /work-orders` | both | done | Still created; `warnings[].code = credit_limit_exceeded`; the override is audited. | API-tested only (Pest); the dialog and check-in show the warning |
| Check-out: hand the vehicle back | `POST /work-orders/collect` | staff | done | Phase 7: stamps `released_at`; settles nothing (payment does). | API-tested (Pest); check-out wording updated |

## The books (Phase 8)

The general ledger has no counterpart in `../web`: these screens are new, and
there is no full accounting UI by design. Every money and stock event posts a
balanced double-entry journal entry in the same transaction as the event; the
screens read the result. The books are core (no module) and staff-only:
`ledger:view` (provider admin; a branch manager within their branches) reads,
`ledger:manage` (provider admin) edits the chart, the posting rules and the
export mappings, and closes months. The **Phase 8** column is the real
frontend driven against this API on a fresh demo seed (PA provider admin, BM
the branch-limited branch manager, C cashier); `tests/Feature/Ledger` covers
what the browser did not drive.

| Screen / action | Endpoint(s) | Side | Status | Notes | Phase 8 |
|---|---|---|---|---|---|
| `/shop/books`: chart of accounts, with balances | `GET /ledger/accounts?as_of=2026-10-08` | staff | done | The lean chart is installed on first use; `balance_cents` is on the account's own side through `as_of`, over the branches in view. | ✓ PA BM (read-only); C: refused |
| Chart: add, edit, deactivate an account | `POST /ledger/accounts`, `PATCH /ledger/accounts/{account}` | staff | done | `ledger:manage`. Once posted to, code/type/side are fixed (409); a rule's account cannot be deactivated. | ✓ PA (add); BM: control disabled |
| `/shop/books/rules`: posting rules | `GET /ledger/posting-rules` | staff | done | Every rule key with its account and the account type it needs. | ✓ PA |
| Posting rules: re-point | `PUT /ledger/posting-rules` | staff | done | `ledger:manage`; applies to postings from now on; wrong type or inactive account is 422. | ✓ PA (re-point and restore) |
| Accounting product and export mapping | `GET /ledger/settings`, `PUT /ledger/settings`, `PUT /ledger/export-mappings` | staff | done | `none`, `xero` or `quickbooks`; the account-code (Xero) or account-name (QuickBooks) mapping table. | API-tested only (Pest); the mapping table is built |
| `/shop/books/journal`: journal browser | `GET /ledger/journal?from=2026-07-01&to=2026-10-08` | staff | done | Filters: range, account, event, branch, source document, search. Newest first. | ✓ PA |
| Journal: open an entry | `GET /ledger/journal/{journal_entry}` | staff | done | Lines with accounts and branches; a reversal names the entry it undoes. A branch-limited caller gets 404 for another branch's entry. | ✓ PA (an invoice's entry, balanced) |
| Journal: export | `GET /ledger/journal/export?from=2026-07-01&to=2026-10-08` | staff | done | `format` csv, or xero / quickbooks when that is the accounting target and every account is mapped (409 `unmapped_accounts`). | ✓ PA (CSV download) |
| `/shop/books/periods`: months | `GET /ledger/periods` | staff | done | Newest first, with status and who closed it. | ✓ PA |
| Period close: checklist | `GET /ledger/periods/checklist?period=2026-09` | staff | done | Unposted sources = 0, receivables = AR, stock valuation = Inventory, unapplied credit = Customer Deposits, trial balance balanced. | ✓ PA |
| Period close: close the month | `POST /ledger/periods/close` | staff | done | `ledger:manage`, not branch-limited; month over, earlier months closed, checklist passing. Final. | ✓ PA (oldest month closed); BM: control disabled |
| `/shop/books/reports`: trial balance | `GET /ledger/reports/trial-balance?as_of=2026-10-08` | staff | done | `balanced` is the API's. | ✓ PA |
| Reports: general ledger | `GET /ledger/reports/general-ledger/{account}?from=2026-07-01&to=2026-10-08` | staff | done | Running balance on the account's own side after the balance brought forward; paged. | ✓ PA |
| Reports: profit and loss by branch and consolidated | `GET /ledger/reports/profit-and-loss?from=2026-07-01&to=2026-10-08` | staff | done | Revenue (net of discounts), cost of sales, gross profit, expenses, net profit; a column per branch in scope. | ✓ PA |
| Reports: balance sheet | `GET /ledger/reports/balance-sheet?as_of=2026-10-08` | staff | done | Assets, liabilities, equity plus earnings to date. | ✓ PA |
| Reports: daily sales by branch and payment method | `GET /ledger/reports/daily-sales?from=2026-09-01&to=2026-09-30` | staff | done | Net sales, VAT and invoiced per day and branch; receipts by method. | ✓ PA |

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
