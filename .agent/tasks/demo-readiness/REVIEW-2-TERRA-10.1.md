# REVIEW-2 — Gate 10.1

**Verdict: APPROVE WITH FIXES.** The scheduled consumers now have the requested deadline and alarm, but the alarm handling introduces regressions for existing alarms and manual runs.

| Opus finding | Status |
|---|---|
| F1 — Budget and alarm | **Partially fixed.** The monotonic deadline is checked between individual deliveries, and the 270-second alarm precedes the 300-second lock expiry. Missing PCNTL makes scheduled runs fail before consuming; manual runs retain the delivery budget. Alarm handling has the regressions below. |
| F2 — Fixture-only selection | **Fixed.** Selection requires fixture URLs, matching StoryLens keys and assigned Stories; incomplete or incompatible corpora are rejected. |
| F3 — Removal documentation | **Fixed.** The guide recommends dedicated stores and explains the recovery window and retained history. |
| F4 — Admin counts | **Fixed.** Granted unlocks now exclude synthetic rows. |
| F5 — Lock test | **Fixed.** The schedule test checks both lock durations. |

**New findings**

- **MAJOR — Existing alarms are postponed and their handlers suppressed.** [ProjectionConsumeBudget.php:23](/home/dolakhoa/code/VietFeed/app/Console/ProjectionConsumeBudget.php:23) replaces the handler and resets any earlier alarm to 270 seconds. A caller’s alarm due during consumption cannot fire on time; if the worker blocks, the default action kills the process instead. Restoration at [line 42](/home/dolakhoa/code/VietFeed/app/Console/ProjectionConsumeBudget.php:42) only occurs after a return or exception, and an expired prior alarm is delayed by another second. Preserve the earlier deadline and its behavior while still enforcing the scheduler bound.
- **MINOR — Manual consumers now receive a fatal alarm.** [ProjectionConsumeBudget.php:21](/home/dolakhoa/code/VietFeed/app/Console/ProjectionConsumeBudget.php:21) arms the default-action alarm for *every* consumer when PCNTL is present. A manual `--consumer=local` run blocked for 270 seconds is therefore killed, even though it has no scheduler lock; previously it had no such termination. Restrict the fatal scheduler alarm to scheduled runs or make manual timeout behavior explicit and opt-in.

Review was read-only; no tests or commands that change repository state were run.