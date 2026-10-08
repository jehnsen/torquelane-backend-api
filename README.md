# TorqueLane API

Laravel API for TorqueLane, a multi-tenant ERP + CRM for Philippine automotive
service businesses. It is the only writer and the source of truth for every
business rule; the Next.js frontend renders what it returns.

```bash
composer setup   # PHP 8.4 + pdo_pgsql, Composer 2 and Docker required
composer test
```

- **[CLAUDE.md](CLAUDE.md)**: architecture, conventions, standing rules, roadmap
- **[docs/deploy.md](docs/deploy.md)**: staging deploy (CloudPanel VPS)
- **[openapi.json](openapi.json)**: generated API description (`composer openapi`)





Phase 4 code written (uncommitted, not yet fully tested)
Domain (pure):
Domain/Parts: PartsForecast ports computePartsDemand and summariseDemand. Value objects: FleetPartFacts, PartUsage, PartDemandRow, DemandContributor, WorkCoverage, PurchaseCoverage.
Domain/PurchaseOrders:
PurchaseOrderStatus and PurchaseOrderMachine. Allowed moves: draft→sent, draft→cancelled, sent→received, sent→cancelled; every move needs po:issue.
PurchaseOrders: fromDemand groups forecast rows by preferred vendor. canIssue applies the existing approval bands to the order's total.
PurchaseOrderExport ports po-export.ts, using ExportTable and ExportOrder.
Domain/Analytics/Analytics: a port of all ten analytics.ts functions, plus value objects.
Domain/Approvals/ApprovalRequests: the logic behind the Requests screen, plus its value objects.
Golden replay: PartsAnalyticsGoldenTest passes. Every case in parts-forecast.json and analytics.json matches.
Fixtures were copied byte-exact from d45871e.
Supporting files: Support/PartsAnalyticsPort.php and Support/HealthIndex.php.
FleetPort::healthFrom() and costOf() are now public.
Schema:
Migration 500100: vendors, fleet_parts (stock never negative), fleet_part_usages.
Migration 500200:
purchase_orders, with a status CHECK and a CHECK tying each status to its timestamps.
purchase_order_lines, with a CHECK that the total equals quantity × unit cost.
The _line_tasks and _line_vehicles junction tables.
An append-only purchase_order_events table.
Triggers: an issued PO and its lines can't change except the PO's status, and no PO is ever deleted.
Models: Vendor, FleetPart, FleetPartUsage, PurchaseOrder, PurchaseOrderLine, PurchaseOrderEvent. All are classified in ModelCoverageTest.
Seed (Demo/PartsSeed, called from DemoSeeder):
6 vendors.
Actimed's 25 parts, with their usages from data/parts-catalogue.json, which comes from parts.json's constants.
The 2 demo POs. The purchase_order series continues at PO-2026-0003.
Migrations and seed run cleanly (checked with one test file).
Vendors (complete except the route): SaveVendor, VendorPolicy (staff only; writes need settings:manage), SaveVendorRequest, VendorResource and VendorCollection, VendorController, and DirectoryQueries::vendors().
Parts and purchase orders:
Actions: PartsQueries (list, forecast, usages, coverage), SaveFleetPart, PurchaseOrderJournal, RaisePurchaseOrders, ProgressPurchaseOrder, PurchaseOrderQueries (incl. export).
RaisePurchaseOrders recomputes the forecast on the locked account and numbers each PO.
ProgressPurchaseOrder: send checks the band; receive restocks the account's own parts; cancel needs a reason.
Policies: FleetPartPolicy and PurchaseOrderPolicy.
Requests:
ListFleetPartsRequest, SaveFleetPartRequest, DemandForecastRequest.
RaisePurchaseOrdersRequest, ListPurchaseOrdersRequest (handles format=csv|xlsx too), CancelPurchaseOrderRequest.
Resources: fleet part and purchase order resources and collections.
app/Exports/SpreadsheetWriter: CSV, plus XLSX built with ZipArchive (no new dependency).
3. Remaining
Controllers:
FleetPartController. You rejected this write; I stopped there and wrote nothing after it.
DemandForecastController, PurchaseOrderController (list, show, from-forecast, send, receive, cancel, export list and single).
Routes for all of these, plus vendors.
Endpoints still to add:
Analytics: dashboard, reports, schedule.
GET /requests.
Shop: home, reports, clients, clients/{account}.
Profile: PATCH /me and PUT /me/password.
Bulk POST /work-orders/collect.
SaveServiceTask::delete: answer 409 for a task still referenced by work orders or PO lines. Today that hits the FK and answers 500.
Tests:
Feature tests for parts and POs: band refusal, restock, triggers, numbering.
Database golden tests: the forecast for Actimed at 6 weeks, analytics per account, and the seeded catalogue against parts.json.
Isolation suite: new tables and GET routes in coverage.php.
A smoke test hitting every parity endpoint as provider admin and as fleet manager.
docs/frontend-parity.md: proposed drops that need your approval are the demo "switch account", the resetFleet refetch, and the profile's first/last name/username split (the API has only name).
CLAUDE.md: a Phase 4 section plus rules that bite, including the deferred module checks in policies.
Gates: composer lint:fix, then composer analyse, composer test and composer openapi. Pint will strip unused imports in PartsAnalyticsPort and reorder the constants in SpreadsheetWriter.