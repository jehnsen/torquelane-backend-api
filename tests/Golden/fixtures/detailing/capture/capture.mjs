// Records what the SOURCE does, for the port's characterization tests.
//
//   node capture.mjs <legacy-js-dir> <pg.sh> <out-dir>
//
// planner.json   the legacy JS (bookingUtils.js): durations, slot rounding, day plans.
// engine.json    the legacy SQL (add_booking, update_booking_detailers,
//                update_booking_status) run in a scratch Postgres, with the
//                plans the legacy JS computes, exactly as the app calls them.
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

const [legacyDir, pgsh, outDir] = process.argv.slice(2);
const U = await import(pathToFileURL(path.join(legacyDir, 'bookingUtils.mjs')));
const T = await import(pathToFileURL(path.join(legacyDir, 'timeSlots.mjs')));

const SOURCE = {
  repository: 'detailing-app_v2 (Samahuzai Carwash and Auto Detailing)',
  commit: 'c30da77',
  js: 'src/utils/bookingUtils.js, src/data/timeSlots.js',
  sql: {
    add_booking: 'supabase/migrations.sql:2685 (= supabase/schema.sql:301)',
    update_booking_detailers: 'supabase/migrations.sql:2834 (= supabase/schema.sql:466)',
    update_booking_status: 'supabase/migrations.sql:2613 (= supabase/schema.sql:597)',
  },
  clock: 'update_booking_status ran with its three now() calls reading a session setting; nothing else was changed',
};

// ------------------------------------------------------------------ planner
const durations = [
  '1 hr', '1 hour', '1.5 hrs', '2h', '.5 hrs', '0.5 hr', '45 min', '45 mins', '45 minutes', '45m', '90m', '90 minutes',
  '1 hr 30 min', '1h30m', '1:30', '0:45', '2-3 hrs', '2–3 hrs', '1.5–2 hrs', '4–5 hrs', '5–6 hrs', '6–8 hrs', '3–4 hrs',
  '2', '45', '1.1 hrs', '10 min', '31 min', '30 min', '60 min', '61 min', '', '   ', 'soon', '1 day', '2 days', '1–2 days',
  '1-2 days', '3 days', 'half day', 'Day', '2 HRS', ' 1 Hr 15 Min ', '1 hr 45 mins', '8 hrs', '9 hrs', '16 hrs', '2 hours 30 minutes',
];
const durationCases = durations.map((text) => ({
  text,
  minutes: U.parseDurationMinutes(text),
  days: U.getDaysConsumed(text),
  slot_budget: U.getTotalSlotBudget(text),
}));

const mins = (label) => U.parseTimeToMinutes(label);
const planOf = (date, time, duration, closing) => {
  const plan = U.planBookingDays(date, time, duration, closing);
  return plan.days.map((d) => ({ date: d.date, slots: d.slots.map(mins) }));
};

const startTimes = [
  '7:00 AM', '8:00 AM', '8:14 AM', '8:15 AM', '8:16 AM', '9:00 AM', '10:44 AM', '10:45 AM', '10:46 AM', '11:30 AM',
  '11:45 AM', '12:00 PM', '12:14 PM', '12:15 PM', '12:16 PM', '12:30 PM', '1:00 PM', '1:45 PM', '3:00 PM', '4:00 PM',
  '4:30 PM', '4:44 PM', '4:45 PM', '5:00 PM', '5:30 PM', '6:00 PM', '8:30 PM', '9:00 PM', '9:30 PM', '11:59 PM',
];
const planDurations = ['30 min', '1 hr', '2–3 hrs', '4–5 hrs', '6–8 hrs', '9 hrs', '1 day', '1–2 days', '3 days'];
const closings = [1020, 1080, 960, 1260, 510, 480];
const planCases = [];
for (const closing of closings) {
  for (const duration of planDurations) {
    for (const time of startTimes) {
      // Keep the fixture a readable size: the odd closings only with a few durations.
      if (closing !== 1020 && !['1 hr', '4–5 hrs', '1–2 days'].includes(duration)) continue;
      planCases.push({
        date: '2026-11-27', // a Friday: plans run across the weekend and the month end
        time_label: time,
        start_minutes: mins(time),
        duration,
        closing_minutes: closing,
        days: planOf('2026-11-27', time, duration, closing),
      });
    }
  }
}
// The source's 60-pass guard: one slot a day, a job far too long for it.
planCases.push({ date: '2026-11-27', time_label: '8:00 AM', start_minutes: 480, duration: '5 days', closing_minutes: 510, days: planOf('2026-11-27', '8:00 AM', '5 days', 510) });

writeFileSync(path.join(outDir, 'planner.json'), JSON.stringify({
  source: SOURCE,
  grid: { slot_minutes: T.SLOT_MINUTES, closing_minutes: T.CLOSING_MINUTES, grid_end_minutes: T.GRID_END_MINUTES, slots: T.timeSlots.map(mins) },
  durations: durationCases,
  plans: planCases,
}, null, 1) + '\n');

// ------------------------------------------------------------------- engine
const UID = '00000000-0000-4000-8000-000000000001';
const DETAILERS = {
  ana: '11111111-1111-4111-8111-111111111111',
  ben: '22222222-2222-4222-8222-222222222222',
  carlo: '33333333-3333-4333-8333-333333333333',
  dina: '44444444-4444-4444-8444-444444444444',
};
const NAME = { ana: 'Ana', ben: 'Ben', carlo: 'Carlo', dina: 'Dina' };
const keyOfUuid = Object.fromEntries(Object.entries(DETAILERS).map(([k, v]) => [v, k]));

const lit = (v) => `'${String(v).replace(/'/g, "''")}'`;
const sql = (statements) => {
  const args = ['legacy_characterization', '-At', '-v', 'ON_ERROR_STOP=1'];
  for (const s of statements) args.push('-c', s);
  return execFileSync(pgsh, args, { encoding: 'utf8' }).trim();
};
const call = (statement, now = null) => {
  const out = sql([`set char.uid = '${UID}'`, `set char.now = ${lit(now ?? '')}`, statement]);
  return JSON.parse(out.split('\n').pop());
};

const reset = (pool, closing) => sql([
  'truncate booking_status_logs, booking_day_slots, bookings, blocked_slots cascade',
  'delete from detailers',
  ...Object.entries(DETAILERS).map(([k, id]) => `insert into detailers (id, name, sort_order) values ('${id}', '${NAME[k]}', 0)`),
  `update settings set detailer_pool_size = ${pool}, default_detailers_per_booking = 1, closing_minutes = ${closing} where id = 1`,
]);

const idOf = (key) => `OBS-CHAR-${key}`;

const book = (s, step) => {
  const min = step.min ?? 1;
  const named = (step.named ?? []).map((k) => DETAILERS[k]);
  const plan = U.planBookingDays(step.date, step.time, step.duration, s.closing);
  if (!plan.days.length) return { input_plan: [], result: { ok: false, error: 'Invalid time slot.' } };
  // AppContext.addBooking: the heads sent to the RPC.
  const requested = Number(step.requested);
  const detailersCount = Math.max(named.length, Number.isFinite(requested) && requested > 0 ? requested : 1, min, 1);
  const p = {
    id: idOf(step.key), service_id: 1, service_name: 'Characterization', service_price: 1000,
    service_duration: step.duration, service_category: 'full', date: step.date, time: step.time,
    customer_name: 'Test Customer', email: 'test@example.com', phone: '09170000000', vehicle: 'Test Car',
    vehicle_year: '2020', notes: '', is_vip: false, status: step.status ?? 'pending',
    detailers_assigned: named, detailers_count: detailersCount, min_detailers: min,
  };
  const days = plan.days.map((d) => ({ date: d.date, slots: d.slots }));
  const pgArray = `array[${plan.days[0].slots.map(lit).join(',')}]::text[]`;
  const data = call(`select add_booking(${lit(JSON.stringify(p))}::jsonb, ${pgArray}, ${lit(JSON.stringify(days))}::jsonb)`);
  const input_plan = plan.days.map((d) => ({ date: d.date, slots: d.slots.map(mins) }));
  if (data.error) return { input_plan, result: { ok: false, error: data.error } };
  return {
    input_plan,
    result: { ok: true, status: data.status, heads: data.detailers_count, named: data.detailers_assigned.map((u) => keyOfUuid[u]) },
  };
};

const setStatus = (step) => {
  const data = call(`select update_booking_status(${lit(idOf(step.key))}, ${lit(step.to)}, ${step.reason == null ? 'null' : lit(step.reason)})`, step.at);
  if (data.error) return { ok: false, error: data.error };
  const iso = (ts) => (ts == null ? null : new Date(ts).toISOString());
  return { ok: true, status: data.status, started_at: iso(data.started_at), completed_at: iso(data.completed_at), cancellation_reason: data.cancellation_reason };
};

const setDetailers = (step) => {
  const ids = (step.named ?? []).map((k) => DETAILERS[k]);
  const arr = ids.length ? `array[${ids.map(lit).join(',')}]::uuid[]` : `'{}'::uuid[]`;
  const data = call(`select update_booking_detailers(${lit(idOf(step.key))}, ${arr}, ${step.min ?? 1})`);
  if (data.error) return { ok: false, error: data.error };
  return { ok: true, heads: data.detailers_count, named: data.detailers_assigned.map((u) => keyOfUuid[u]) };
};

const snapshot = () => {
  const strip = (id) => id.replace('OBS-CHAR-', '');
  const bookings = JSON.parse(sql([`select coalesce(jsonb_agg(jsonb_build_object('key', id, 'status', status, 'heads', detailers_count, 'named', detailers_assigned) order by id), '[]') from bookings`]));
  const slots = JSON.parse(sql([`select coalesce(jsonb_agg(jsonb_build_object('key', booking_id, 'date', date, 'slot', slot_label_minutes(slot), 'released', released) order by booking_id, date, slot_label_minutes(slot)), '[]') from booking_day_slots`]));
  const logs = JSON.parse(sql([`select coalesce(jsonb_agg(jsonb_build_object('key', booking_id, 'from', from_status, 'to', to_status, 'notes', notes) order by changed_at, id), '[]') from booking_status_logs`]));
  // The tightest reading of the pool: heads held per (date, slot), released rows and dead bookings left out.
  const held = JSON.parse(sql([`select coalesce(jsonb_agg(jsonb_build_object('date', d, 'slot', s, 'heads', h) order by d, s), '[]') from (select bds.date d, slot_label_minutes(bds.slot) s, sum(b.detailers_count) h from booking_day_slots bds join bookings b on b.id = bds.booking_id where not bds.released and b.status not in ('cancelled','no_show') group by 1, 2) x`]));
  return {
    bookings: bookings.map((b) => ({ ...b, key: strip(b.key), named: b.named.map((u) => keyOfUuid[u]) })),
    slots: slots.map((r) => ({ ...r, key: strip(r.key) })),
    logs: logs.map((r) => ({ ...r, key: strip(r.key) })),
    held,
  };
};

const D1 = '2026-11-02'; // Monday
const D2 = '2026-11-03';
const D3 = '2026-11-04';
const at = (date, hhmm) => `${date}T${hhmm}:00+08:00`;

const scenarios = [
  {
    name: 'capacity is checked on every day a booking touches',
    pool: 2, closing: 1020,
    steps: [
      { op: 'book', key: 'long', date: D1, time: '10:00 AM', duration: '1–2 days', requested: 2 },
      { op: 'book', key: 'day1-before', date: D1, time: '9:00 AM', duration: '1 hr' },
      { op: 'book', key: 'day1-overlap', date: D1, time: '9:30 AM', duration: '1 hr' },
      { op: 'book', key: 'day2-any', date: D2, time: '2:00 PM', duration: '30 min' },
      { op: 'book', key: 'day3-overlap', date: D3, time: '9:00 AM', duration: '1 hr' },
      { op: 'book', key: 'day3-after', date: D3, time: '10:00 AM', duration: '1 hr' },
      { op: 'book', key: 'rolls-into-day3', date: D2, time: '5:00 PM', duration: '1 hr' },
      { op: 'snapshot' },
    ],
  },
  {
    name: 'heads: asked, named, minimum; trimmed to what is free; refused under the minimum',
    pool: 3, closing: 1020,
    steps: [
      { op: 'book', key: 'two-heads', date: D1, time: '9:00 AM', duration: '1 hr', requested: 2 },
      { op: 'book', key: 'asks-three', date: D1, time: '9:00 AM', duration: '1 hr', requested: 3 },
      { op: 'book', key: 'needs-two', date: D1, time: '9:30 AM', duration: '1 hr', min: 2 },
      { op: 'book', key: 'needs-two-later', date: D1, time: '10:00 AM', duration: '1 hr', min: 2, requested: 5 },
      { op: 'book', key: 'unsaid', date: D1, time: '2:00 PM', duration: '1 hr' },
      { op: 'book', key: 'min-over-asked', date: D1, time: '3:00 PM', duration: '1 hr', min: 2, requested: 1 },
      { op: 'book', key: 'named-over-asked', date: D1, time: '4:00 PM', duration: '30 min', requested: 1, named: ['ana', 'ben', 'carlo'] },
      { op: 'book', key: 'named-trimmed', date: D1, time: '2:00 PM', duration: '30 min', named: ['ana', 'ben', 'carlo'] },
      { op: 'book', key: 'zero-asked', date: D1, time: '8:00 AM', duration: '30 min', requested: 0 },
      { op: 'snapshot' },
    ],
  },
  {
    name: 'a named detailer cannot be on two jobs in the same slot, on any day of the span',
    pool: 5, closing: 1020,
    steps: [
      { op: 'book', key: 'ana-morning', date: D1, time: '9:00 AM', duration: '2 hrs', named: ['ana'] },
      { op: 'book', key: 'ana-overlap', date: D1, time: '10:30 AM', duration: '1 hr', named: ['ana'] },
      { op: 'book', key: 'ana-adjacent', date: D1, time: '11:00 AM', duration: '1 hr', named: ['ana'] },
      { op: 'book', key: 'ben-carlo', date: D1, time: '9:00 AM', duration: '1 hr', named: ['carlo', 'ben'] },
      { op: 'book', key: 'three-busy', date: D1, time: '9:30 AM', duration: '30 min', named: ['dina', 'carlo', 'ana', 'ben'] },
      { op: 'book', key: 'dina-multi', date: D1, time: '3:00 PM', duration: '1 day', named: ['dina'] },
      { op: 'book', key: 'dina-day2', date: D2, time: '8:00 AM', duration: '30 min', named: ['dina'] },
      { op: 'book', key: 'dina-day2-after', date: D2, time: '3:00 PM', duration: '30 min', named: ['dina'] },
      { op: 'book', key: 'unnamed-day2', date: D2, time: '8:00 AM', duration: '30 min' },
      { op: 'snapshot' },
    ],
  },
  {
    name: 'a cancelled or no-show booking holds nothing',
    pool: 1, closing: 1020,
    steps: [
      { op: 'book', key: 'first', date: D1, time: '9:00 AM', duration: '1 hr', named: ['ana'] },
      { op: 'book', key: 'blocked-out', date: D1, time: '9:00 AM', duration: '1 hr', named: ['ana'] },
      { op: 'status', key: 'first', to: 'cancelled', reason: 'Customer called', at: at(D1, '08:00') },
      { op: 'book', key: 'second', date: D1, time: '9:00 AM', duration: '1 hr', named: ['ana'] },
      { op: 'status', key: 'second', to: 'no_show', reason: 'Did not arrive', at: at(D1, '09:40') },
      { op: 'book', key: 'third', date: D1, time: '9:30 AM', duration: '30 min', named: ['ana'] },
      { op: 'status', key: 'first', to: 'confirmed', at: at(D1, '09:45') },
      { op: 'snapshot' },
    ],
  },
  {
    name: 'reassigning detailers is checked across the whole span',
    pool: 3, closing: 1020,
    steps: [
      { op: 'book', key: 'job', date: D1, time: '4:00 PM', duration: '2 hrs', requested: 2 },
      { op: 'book', key: 'other-day2', date: D2, time: '8:00 AM', duration: '30 min', requested: 2, named: ['ana'] },
      { op: 'detailers', key: 'job', named: [] },
      { op: 'detailers', key: 'job', named: ['ben'], min: 2 },
      { op: 'detailers', key: 'job', named: ['ben', 'carlo'] },
      { op: 'detailers', key: 'job', named: ['ana'] },
      { op: 'detailers', key: 'job', named: ['ben'] },
      { op: 'detailers', key: 'missing', named: ['ben'] },
      { op: 'snapshot' },
      { op: 'detailers', key: 'job', named: ['ben'], min: 2 },
      { op: 'detailers', key: 'job', named: ['carlo'] },
      { op: 'detailers', key: 'other-day2', named: ['carlo', 'ana', 'dina'] },
      { op: 'detailers', key: 'other-day2', named: ['ana', 'dina'] },
      { op: 'snapshot' },
    ],
  },
  {
    name: 'status stamps and the append-only log',
    pool: 2, closing: 1020,
    steps: [
      { op: 'book', key: 'job', date: D1, time: '9:00 AM', duration: '2 hrs' },
      { op: 'status', key: 'job', to: 'confirmed', at: at(D1, '08:10') },
      { op: 'status', key: 'job', to: 'on-going', at: at(D1, '09:05') },
      { op: 'status', key: 'job', to: 'on-going', at: at(D1, '09:20') },
      { op: 'status', key: 'job', to: 'completed', at: at(D1, '11:20') },
      { op: 'status', key: 'job', to: 'on-going', at: at(D1, '11:30') },
      { op: 'status', key: 'job', to: 'completed', at: at(D1, '11:50') },
      { op: 'status', key: 'job', to: 'cancelled', reason: 'Entered by mistake', at: at(D1, '12:00') },
      { op: 'status', key: 'job', to: 'no_show', reason: 'Not the reason kept', at: at(D1, '12:05') },
      { op: 'status', key: 'job', to: 'pending', at: at(D1, '12:10') },
      { op: 'status', key: 'job', to: 'completed', at: at(D1, '08:00') },
      { op: 'status', key: 'job', to: 'archived', at: at(D1, '12:20') },
      { op: 'status', key: 'nobody', to: 'confirmed', at: at(D1, '12:20') },
      { op: 'snapshot' },
    ],
  },
  {
    name: 'finishing early releases from the 30-minute bucket of the completion onward',
    pool: 1, closing: 1020,
    steps: [
      { op: 'book', key: 'job', date: D1, time: '9:00 AM', duration: '2 hrs', named: ['ana'], status: 'confirmed' },
      { op: 'book', key: 'wants-0930', date: D1, time: '9:30 AM', duration: '30 min', named: ['ana'] },
      { op: 'status', key: 'job', to: 'on-going', at: at(D1, '09:02') },
      { op: 'status', key: 'job', to: 'completed', at: at(D1, '09:45') },
      { op: 'snapshot' },
      { op: 'book', key: 'takes-0930', date: D1, time: '9:30 AM', duration: '30 min', named: ['ana'] },
      { op: 'book', key: 'wants-0900', date: D1, time: '9:00 AM', duration: '30 min' },
      { op: 'status', key: 'job', to: 'on-going', at: at(D1, '09:50') },
      { op: 'snapshot' },
      { op: 'book', key: 'after-reopen', date: D1, time: '10:30 AM', duration: '30 min' },
      { op: 'status', key: 'job', to: 'completed', at: at(D1, '09:29') },
      { op: 'snapshot' },
      { op: 'status', key: 'job', to: 'confirmed', at: at(D1, '09:55') },
      { op: 'status', key: 'job', to: 'completed', at: at(D1, '10:00') },
      { op: 'snapshot' },
      { op: 'status', key: 'job', to: 'pending', at: at(D1, '10:05') },
      { op: 'status', key: 'job', to: 'completed', at: at(D1, '11:05') },
      { op: 'snapshot' },
    ],
  },
  {
    name: 'a multi-day job finished early gives back the rest of that day and every later day',
    pool: 2, closing: 1020,
    steps: [
      { op: 'book', key: 'long', date: D1, time: '2:00 PM', duration: '1–2 days', requested: 2, named: ['ana', 'ben'], status: 'confirmed' },
      { op: 'status', key: 'long', to: 'on-going', at: at(D1, '14:05') },
      { op: 'status', key: 'long', to: 'completed', at: at(D2, '10:10') },
      { op: 'snapshot' },
      { op: 'book', key: 'day2-freed', date: D2, time: '10:00 AM', duration: '1 hr', named: ['ana'] },
      { op: 'book', key: 'day2-held', date: D2, time: '9:30 AM', duration: '30 min' },
      { op: 'book', key: 'day3-freed', date: D3, time: '8:00 AM', duration: '1 hr', requested: 2, named: ['ben'] },
      { op: 'status', key: 'long', to: 'confirmed', at: at(D2, '10:30') },
      { op: 'snapshot' },
      { op: 'status', key: 'long', to: 'completed', at: at('2026-11-01', '18:00') },
      { op: 'snapshot' },
      { op: 'status', key: 'long', to: 'on-going', at: at(D1, '14:00') },
      { op: 'status', key: 'long', to: 'completed', at: at('2026-11-09', '09:00') },
      { op: 'snapshot' },
    ],
  },
  {
    name: 'the closing-time boundary',
    pool: 1, closing: 1020,
    steps: [
      { op: 'book', key: 'last-slot', date: D1, time: '4:30 PM', duration: '1 hr' },
      { op: 'book', key: 'at-closing', date: D1, time: '5:00 PM', duration: '1 hr' },
      { op: 'book', key: 'next-morning', date: D2, time: '8:00 AM', duration: '30 min' },
      { op: 'book', key: 'next-0930', date: D2, time: '9:30 AM', duration: '30 min' },
      { op: 'book', key: 'evening', date: D1, time: '8:00 PM', duration: '30 min' },
      { op: 'book', key: 'off-grid-1644', date: D3, time: '4:44 PM', duration: '30 min' },
      { op: 'book', key: 'off-grid-1645', date: D3, time: '4:45 PM', duration: '30 min' },
      { op: 'book', key: 'lunch', date: D3, time: '11:30 AM', duration: '2 hrs' },
      { op: 'book', key: 'lunch-1215', date: D3, time: '12:15 PM', duration: '30 min' },
      { op: 'snapshot' },
    ],
  },
  {
    name: 'a later closing keeps the evening in the same day',
    pool: 1, closing: 1140,
    steps: [
      { op: 'book', key: 'late', date: D1, time: '4:30 PM', duration: '2 hrs' },
      { op: 'book', key: 'at-1830', date: D1, time: '6:30 PM', duration: '1 hr' },
      { op: 'book', key: 'at-1900', date: D1, time: '7:00 PM', duration: '30 min' },
      { op: 'snapshot' },
    ],
  },
];

const recorded = [];
for (const s of scenarios) {
  reset(s.pool, s.closing);
  const steps = [];
  for (const step of s.steps) {
    if (step.op === 'book') {
      const { input_plan, result } = book(s, step);
      steps.push({ ...step, start_minutes: mins(step.time), source_plan: input_plan, expect: result });
    } else if (step.op === 'status') {
      steps.push({ ...step, expect: setStatus(step) });
    } else if (step.op === 'detailers') {
      steps.push({ ...step, expect: setDetailers(step) });
    } else {
      steps.push({ op: 'snapshot', expect: snapshot() });
    }
  }
  recorded.push({ name: s.name, pool: s.pool, closing_minutes: s.closing, steps });
}

writeFileSync(path.join(outDir, 'engine.json'), JSON.stringify({ source: SOURCE, detailers: NAME, scenarios: recorded }, null, 1) + '\n');
console.log(`planner: ${durationCases.length} durations, ${planCases.length} plans; engine: ${recorded.length} scenarios, ${recorded.reduce((n, s) => n + s.steps.length, 0)} steps`);
