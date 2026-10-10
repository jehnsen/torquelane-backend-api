# How the detailing characterization fixtures were recorded

`../planner.json` and `../engine.json` are recordings of what the SOURCE does
(the Samahuzai detailing app, `detailing-app_v2` at c30da77), not of this API.
The port is held to them by `tests/Unit/Domain/Detailing/*CharacterizationTest`
and `tests/Feature/Detailing/EngineCharacterizationTest`. The source has no
tests of its own; these stand in for them.

- `planner.json` comes from the source's own JavaScript
  (`src/utils/bookingUtils.js`, `src/data/timeSlots.js`) run under Node:
  duration parsing, nearest-slot rounding, the sequential day-fill plan.
- `engine.json` comes from the source's own SQL (`add_booking`,
  `update_booking_detailers`, `update_booking_status`) run in a scratch
  Postgres 17, called the way `AppContext.jsx` calls them (the plan computed by
  the source's JavaScript, the heads by `addBooking`'s formula).

- `equipment.json` (Phase 10) comes from the source's equipment engine
  (`src/utils/equipmentUtils.js`, a pure module) run under Node by
  `capture-equipment.mjs`: 101 tools in 46 situations, each with its full
  report and the floor summary. Costs go in as centavos and are handed to the
  source in pesos. Re-record with
  `node capture-equipment.mjs <dir holding equipmentUtils.mjs> <out dir>`.

## Which definitions

Those functions are redefined several times across the source's SQL files. The
last definition in apply order (`schema.sql`, then `migrations.sql`) was used:

| Function | File and line | Notes |
|---|---|---|
| `add_booking(p, p_occupies_slots, p_day_slots)` | `migrations.sql:2685` | Same body as `schema.sql:301`, comments aside. |
| `update_booking_detailers` | `migrations.sql:2834` | Same body as `schema.sql:466`. |
| `update_booking_status` | `migrations.sql:2613` | Same body as `schema.sql:597`. |

`migration_latest.sql` is NOT the latest: it has one commit (2026-06-23) and
stops at the source's Phase 7, before day slots, head counts, task timing and
early release. Its definitions were not used.

Applying both files leaves two live overloads of `add_booking` (with and
without `p_day_slots`), of `tender_pos_order` and of `update_settings`: later
phases added a parameter without dropping the earlier function. The app passes
`p_day_slots`, which selects the newer one.

## The one change made to the source

`update_booking_status` calls `now()` three times. So that completion times
could be chosen, those three calls read a session setting instead
(`char_now.sql`). Nothing else in any function was touched.

## Re-recording

```sh
./pg.sh postgres -c 'create database legacy_characterization'
./pg.sh legacy_characterization < stubs.sql            # auth.uid(), the realtime publication
./pg.sh legacy_characterization < <source>/supabase/schema.sql
./pg.sh legacy_characterization < <source>/supabase/migrations.sql
./pg.sh legacy_characterization < char_now.sql
#   then load update_booking_status with now() → public.char_now()
node capture.mjs <dir holding bookingUtils.mjs + timeSlots.mjs> ./pg.sh <out dir>
```

`bookingUtils.mjs` is the source file with its import path changed to
`./timeSlots.mjs`. If the live database's `pg_get_functiondef` output is ever
supplied and differs from the files, re-record against it.
