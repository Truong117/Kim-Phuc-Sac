import assert from "node:assert/strict";
import { test } from "node:test";
import { moduleFrom } from "./helpers/load-spa-module.mjs";
const { spaScheduleItems, spaScheduleBounds, spaHourlyVisits } =
  await moduleFrom("src/features/spa/presentation.ts");
const task = (id, startTime, durationMinutes, technicianId = "tech-1") => ({
  id,
  startTime,
  durationMinutes,
  technicianId,
});
const visit = (
  id,
  tasks,
  status = "arrived",
  startTime = tasks[0]?.startTime ?? "09:00",
) => ({ id, tasks, status, startTime, customerName: id });
test("timeline separates overlapping/nested services, but adjacent services share a lane", () => {
  const visits = [
    visit("one", [
      task("a", "09:00", 120),
      task("b", "09:30", 30),
      task("c", "10:00", 30),
      task("d", "11:00", 60),
    ]),
  ];
  const items = spaScheduleItems(visits, "tech-1");
  assert.deepEqual(
    items.map((i) => [i.id, i.lane, i.lanes]),
    [
      ["a", 0, 2],
      ["b", 1, 2],
      ["c", 1, 2],
      ["d", 0, 1],
    ],
  );
  assert.deepEqual(
    visits[0].tasks.map((t) => t.id),
    ["a", "b", "c", "d"],
  );
});
test("pending handover appears for current and incoming owners, cancelled visits reserve no visible slot", () => {
  const pending = { ...task("a", "09:00", 60), handover: { toId: "tech-2" } };
  const visits = [
    visit("one", [pending]),
    visit("cancelled", [task("b", "09:00", 60)], "cancelled"),
  ];
  assert.equal(spaScheduleItems(visits, "tech-1")[0].incoming, false);
  assert.equal(spaScheduleItems(visits, "tech-2")[0].incoming, true);
  assert.equal(spaScheduleItems(visits, "tech-1").length, 1);
  assert.equal(spaScheduleItems(visits, "").length, 0);
});
test("timeline bounds include services outside changed opening hours and their end times", () => {
  const visits = [
    visit("one", [task("a", "07:30", 90), task("b", "19:30", 60)]),
  ];
  assert.deepEqual(spaScheduleBounds(visits, "08:00", "18:00"), {
    start: 420,
    end: 1260,
  });
  assert.deepEqual(spaScheduleBounds([], "08:00", "18:00"), {
    start: 480,
    end: 1080,
  });
});
test("hourly chart counts each appointment once, even with multiple services, and excludes cancelled visits", () => {
  const visits = [
    visit("one", [task("a", "09:00", 60), task("b", "10:00", 60)]),
    visit("two", [], "booked", "09:30"),
    visit("three", [], "cancelled", "09:45"),
  ];
  const hours = spaHourlyVisits(visits, "08:00", "18:00");
  assert.equal(hours.find((h) => h.label === "09:00").count, 2);
  assert.equal(hours.find((h) => h.label === "10:00").count, 0);
});
