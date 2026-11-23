# REVIEW-3 — Gate 9.7a RBAC, fix round 2 (Claude Opus 5.5, read-only)

## Verdict: APPROVE

All REVIEW-2 items verified fixed (MINOR-1 Cassandra-outage stop without consuming attempts; 35 s budget + CLI alarm; `dead`
paging; `replay --all` CAS + bound; guard repair audit target `super-admins`; abandoned bootstrap candidate clear). No regressions.
No BLOCKER/MAJOR/MINOR.

## NITs (follow-ups, not blocking)

- **NIT-1** `app/Authorization/AuthorizationAudit.php` `project()`: every `append` Throwable maps to "outage" (`null`), so a
  per-row Cassandra `InvalidRequest` (e.g. empty `target`, far-future `at` → TTL > 20 y) would stall the queue head forever without
  dead-lettering. Not reachable from current producers (always `now()` + real id / `super-admins`). Hardening: validate `target`
  non-empty and `at` window in `encode`, or classify only connection/timeout errors as outage.
- **NIT-2** `app/Console/Commands/RbacBootstrap.php` `--clear-candidate`: no liveness fence and no audit event; clearing while
  the original bootstrap still runs could yield two committed Super Admins. Low impact (operator-chosen accounts; runbook says
  confirm the process stopped). Option: `bootstrap_started_at` with the 60-minute staleness rule and/or a guard audit event.
