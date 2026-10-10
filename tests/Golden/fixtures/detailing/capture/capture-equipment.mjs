// Records what the SOURCE's equipment engine does (src/utils/equipmentUtils.js),
// for the port's characterization test.
//
//   node capture-equipment.mjs <dir holding equipmentUtils.mjs> <out dir>
//
// Money goes in as centavos and is handed to the source in pesos; times are ISO
// instants; a purchase date is a date-only string, which the source reads as
// UTC midnight (`new Date('2026-01-15')`).
import { writeFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import path from 'node:path';

const [legacyDir, outDir] = process.argv.slice(2);
const E = await import(pathToFileURL(path.join(legacyDir, 'equipmentUtils.mjs')));

const NOW = '2026-10-10T04:00:00.000Z';
const daysAgo = (d, now = NOW) => new Date(new Date(now).getTime() - d * 86_400_000).toISOString();

// A small deterministic generator, so the fixture can be re-recorded identically.
let seed = 20261010;
const rnd = () => { seed = (seed * 1664525 + 1013904223) % 4294967296; return seed / 4294967296; };
const pick = (list) => list[Math.floor(rnd() * list.length)];
const between = (lo, hi, step = 1) => lo + Math.floor(rnd() * ((hi - lo) / step + 1)) * step;

const tool = (id, over = {}) => ({
  id, status: 'available', conditionRating: 'good', isActive: true,
  purchaseDate: null, createdAt: daysAgo(400), purchaseCostCents: 0,
  baselineHours: 0, baselineCycles: 0,
  serviceIntervalHours: null, serviceIntervalCycles: null, serviceIntervalDays: null,
  lastServiceAt: null, expectedLifeHours: null, ...over,
});
const job = (serviceId, completedAt, status = 'completed') => ({ serviceId, status, completedAt });

const scenarios = [];
const add = (name, equipment, ctx, now = NOW) => scenarios.push({ name, now, equipment, ctx });

// ----------------------------------------------------------- named behaviour
add('a tool with no interval is not tracked, and one with no profile never ages from bookings',
  [tool('untracked'), tool('unmapped', { serviceIntervalHours: 100 })],
  { bookings: [job('wash', daysAgo(3)), job('wash', daysAgo(9))], serviceEquipment: [{ equipmentId: 'someone-else', serviceId: 'wash', hoursPerJob: 2 }], usageLogs: [], maintenanceLogs: [] });

add('a tool ages only on the packages it is mapped to, and only on completed bookings',
  [tool('polisher', { serviceIntervalHours: 10, expectedLifeHours: 100, baselineHours: 12.5, baselineCycles: 4 })],
  { bookings: [job('correction', daysAgo(2)), job('wash', daysAgo(2)), job('correction', daysAgo(5), 'on-going'), job('correction', daysAgo(12)), job('correction', null)],
    serviceEquipment: [{ equipmentId: 'polisher', serviceId: 'correction', hoursPerJob: 2.5 }], usageLogs: [], maintenanceLogs: [] });

add('usage is counted from the last service; the lifetime meter from the beginning',
  [tool('washer', { serviceIntervalHours: 20, serviceIntervalCycles: 30, lastServiceAt: daysAgo(10), baselineHours: 100, expectedLifeHours: 400 })],
  { bookings: [job('wash', daysAgo(1)), job('wash', daysAgo(4)), job('wash', daysAgo(10)), job('wash', daysAgo(15)), job('wash', daysAgo(40))],
    serviceEquipment: [{ equipmentId: 'washer', serviceId: 'wash', hoursPerJob: 0.75 }],
    usageLogs: [{ equipmentId: 'washer', hours: 3.25, cycles: 2, usedAt: daysAgo(2) }, { equipmentId: 'washer', hours: 1, cycles: 7.9, usedAt: daysAgo(20) }, { equipmentId: 'other', hours: 50, cycles: 50, usedAt: daysAgo(2) }],
    maintenanceLogs: [] });

for (const [label, used] of [['just under due soon', 7.99], ['exactly due soon', 8], ['just under overdue', 9.99], ['exactly overdue', 10], ['well overdue', 25]]) {
  add(`the service bands: ${label}`, [tool('t', { serviceIntervalHours: 10 })],
    { bookings: [], serviceEquipment: [], usageLogs: [{ equipmentId: 't', hours: used, cycles: 0, usedAt: daysAgo(1) }], maintenanceLogs: [] });
}

add('the first trigger to trip binds: days here, with hours and cycles behind',
  [tool('gauge', { serviceIntervalHours: 400, serviceIntervalCycles: 500, serviceIntervalDays: 90, lastServiceAt: daysAgo(80) })],
  { bookings: [], serviceEquipment: [], usageLogs: [{ equipmentId: 'gauge', hours: 12, cycles: 9, usedAt: daysAgo(5) }], maintenanceLogs: [] });

add('days run from the last service, else the purchase date, else the day it was registered',
  [tool('from-service', { serviceIntervalDays: 30, lastServiceAt: daysAgo(12.5), purchaseDate: '2025-01-15' }),
   tool('from-purchase', { serviceIntervalDays: 365, purchaseDate: '2026-01-15' }),
   tool('from-registration', { serviceIntervalDays: 180, createdAt: daysAgo(200) })],
  { bookings: [], serviceEquipment: [], usageLogs: [], maintenanceLogs: [] });

add('the forecast: a burn rate from the last 30 days, none for an idle tool, today for an overdue one',
  [tool('busy', { serviceIntervalHours: 100 }), tool('idle', { serviceIntervalHours: 100 }), tool('late', { serviceIntervalHours: 5 }), tool('by-cycles', { serviceIntervalCycles: 40 })],
  { bookings: [job('wash', daysAgo(3)), job('wash', daysAgo(8)), job('wash', daysAgo(29)), job('wash', daysAgo(31))],
    serviceEquipment: [{ equipmentId: 'busy', serviceId: 'wash', hoursPerJob: 4 }, { equipmentId: 'by-cycles', serviceId: 'wash', hoursPerJob: 0.5 }],
    usageLogs: [{ equipmentId: 'idle', hours: 30, cycles: 0, usedAt: daysAgo(60) }, { equipmentId: 'late', hours: 9, cycles: 0, usedAt: daysAgo(2) }],
    maintenanceLogs: [] });

for (const [label, base] of [['69%', 69], ['70%', 70], ['89.9%', 89.9], ['90%', 90], ['99.9%', 99.9], ['100%', 100], ['140%', 140]]) {
  add(`the life bands: ${label} of rated life`, [tool('t', { expectedLifeHours: 100, baselineHours: base })], { bookings: [], serviceEquipment: [], usageLogs: [], maintenanceLogs: [] });
}

add('reliability and cost: MTBF over repairs, availability over downtime, cost per hour over the total cost of ownership',
  [tool('compressor', { purchaseCostCents: 8_500_000, baselineHours: 480, serviceIntervalHours: 500 }), tool('new', { purchaseCostCents: 1_250_050 })],
  { bookings: [], serviceEquipment: [], usageLogs: [{ equipmentId: 'compressor', hours: 20, cycles: 0, usedAt: daysAgo(10) }],
    maintenanceLogs: [
      { equipmentId: 'compressor', kind: 'repair', costCents: 1_250_000, downtimeHours: 16, performedAt: daysAgo(200) },
      { equipmentId: 'compressor', kind: 'preventive', costCents: 185_075, downtimeHours: 2.5, performedAt: daysAgo(90) },
      { equipmentId: 'compressor', kind: 'repair', costCents: 320_000, downtimeHours: 6, performedAt: daysAgo(30) },
      { equipmentId: 'compressor', kind: 'inspection', costCents: 0, downtimeHours: 0, performedAt: daysAgo(31) },
      { equipmentId: 'new', kind: 'calibration', costCents: 99_999, downtimeHours: 1, performedAt: daysAgo(3) },
    ] });

for (const status of ['available', 'in_use', 'maintenance', 'repair', 'retired']) {
  for (const conditionRating of ['excellent', 'good', 'fair', 'poor']) {
    add(`the health score: ${status}, ${conditionRating}`,
      [tool('t', { status, conditionRating, serviceIntervalHours: 10, expectedLifeHours: 200, baselineHours: 60 })],
      { bookings: [], serviceEquipment: [], usageLogs: [{ equipmentId: 't', hours: 13, cycles: 0, usedAt: daysAgo(1) }], maintenanceLogs: [] });
  }
}
add('the health score bottoms out at zero', [tool('t', { status: 'retired', conditionRating: 'poor', serviceIntervalHours: 1, expectedLifeHours: 10, baselineHours: 500 })],
  { bookings: [], serviceEquipment: [], usageLogs: [{ equipmentId: 't', hours: 40, cycles: 0, usedAt: daysAgo(1) }], maintenanceLogs: [] });

// ------------------------------------------------------- a generated floor
const packages = ['essential', 'executive', 'elite', 'correction', 'coating', 'interior'];
for (let round = 0; round < 6; round++) {
  const equipment = [];
  const serviceEquipment = [];
  for (let i = 0; i < 9; i++) {
    const id = `tool-${round}-${i}`;
    equipment.push(tool(id, {
      status: pick(['available', 'available', 'in_use', 'maintenance', 'repair', 'retired']),
      conditionRating: pick(['excellent', 'good', 'good', 'fair', 'poor']),
      isActive: rnd() > 0.12,
      purchaseDate: rnd() > 0.5 ? `2025-${String(between(1, 12)).padStart(2, '0')}-${String(between(1, 28)).padStart(2, '0')}` : null,
      createdAt: daysAgo(between(20, 600)),
      purchaseCostCents: between(0, 9_000_000, 12_345),
      baselineHours: pick([0, 0, 12.5, 150, 990.5]),
      baselineCycles: pick([0, 0, 40, 260]),
      serviceIntervalHours: pick([null, 40, 100, 150.5, 500]),
      serviceIntervalCycles: pick([null, null, 50, 250]),
      serviceIntervalDays: pick([null, null, 90, 365]),
      lastServiceAt: rnd() > 0.45 ? daysAgo(between(1, 200) + rnd()) : null,
      expectedLifeHours: pick([null, 1000, 1500.5, 4000]),
    }));
    for (const pkg of packages) if (rnd() > 0.6) serviceEquipment.push({ equipmentId: id, serviceId: pkg, hoursPerJob: pick([0, 0.25, 0.5, 0.75, 1.25, 2.5]) });
  }
  const bookings = [];
  for (let i = 0; i < 70; i++) bookings.push(job(pick(packages), daysAgo(between(0, 240) + rnd()), pick(['completed', 'completed', 'completed', 'confirmed', 'cancelled'])));
  const usageLogs = [];
  for (let i = 0; i < 25; i++) usageLogs.push({ equipmentId: pick(equipment).id, hours: pick([0, 0.5, 1.75, 3, 12.25]), cycles: pick([0, 0, 1, 4, 12]), usedAt: daysAgo(between(0, 260) + rnd()) });
  const maintenanceLogs = [];
  for (let i = 0; i < 18; i++) maintenanceLogs.push({ equipmentId: pick(equipment).id, kind: pick(['preventive', 'repair', 'inspection', 'calibration', 'part_replacement']), costCents: between(0, 800_000, 4_321), downtimeHours: pick([0, 0.5, 2, 8, 36]), performedAt: daysAgo(between(1, 300) + rnd()) });
  add(`a generated floor of nine tools (${round + 1})`, equipment, { bookings, serviceEquipment, usageLogs, maintenanceLogs });
}

// ------------------------------------------------------------------ record
const iso = (v) => (v == null ? null : new Date(v).toISOString());
const recorded = scenarios.map((s) => {
  const now = new Date(s.now).getTime();
  const ctx = {
    bookings: s.ctx.bookings,
    serviceEquipment: s.ctx.serviceEquipment,
    usageLogs: s.ctx.usageLogs,
    maintenanceLogs: s.ctx.maintenanceLogs.map((l) => ({ ...l, cost: l.costCents / 100 })),
  };
  const reports = s.equipment.map((e) => E.buildEquipmentReport({ ...e, purchaseCost: e.purchaseCostCents / 100 }, ctx, now));
  return {
    name: s.name, now: s.now, equipment: s.equipment, ctx: s.ctx,
    expect: {
      reports: reports.map((r) => ({
        id: r.equipment.id,
        lifetime: r.lifetime,
        service: r.service && {
          level: r.service.level, progress: r.service.progress, binding: r.service.binding.key,
          triggers: r.service.triggers.map((t) => ({ key: t.key, used: t.used, interval: t.interval, ratio: t.ratio, remaining: t.remaining })),
          hoursSince: r.service.hoursSince, cyclesSince: r.service.cyclesSince, daysSince: r.service.daysSince,
        },
        life: r.life,
        forecast: r.forecast && { days: r.forecast.days, date: iso(r.forecast.date), rate: r.forecast.rate },
        reliability: { ...r.reliability, lastServicedAt: iso(r.reliability.lastServicedAt) },
        utilization: r.utilization,
        health: r.health,
      })),
      summary: E.summarizeFleet(reports),
    },
  };
});

writeFileSync(path.join(outDir, 'equipment.json'), '{\n "source": ' + JSON.stringify({
  repository: 'detailing-app_v2 (Samahuzai Carwash and Auto Detailing)', commit: 'c30da77', js: 'src/utils/equipmentUtils.js',
  notes: 'Costs are given in centavos and were handed to the source in pesos; expected costs are in pesos, as the source computes them.',
}) + ',\n "scenarios": [\n' + recorded.map((r) => '  ' + JSON.stringify(r)).join(',\n') + '\n ]\n}\n');
console.log(`${recorded.length} scenarios, ${recorded.reduce((n, r) => n + r.equipment.length, 0)} tool reports`);
