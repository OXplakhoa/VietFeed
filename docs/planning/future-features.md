# VietFeed NoSQL — Future and stretch backlog

> Supporting backlog for `docs/specs/vietfeed-nosql-storylens.md`. Listing an item here does not promote it into the approved implementation scope.

This file preserves useful ideas that are intentionally not required for the first defensible version. An item being listed here means **remember it**, not **commit to implementing it**. Promote an item only after the four-database vertical slice, existing-feature migration, tests, and deterministic replay demo are stable.

## Stretch goals if the core is complete

### Continuous live RSS ingestion

- Run scheduled RSS ingestion continuously during the demo in addition to the deterministic replay fixture.
- Keep replay as the reliable demo/test path; live RSS must never be the only way to demonstrate StoryLens.

### Opportunistic full-text crawling

- Crawl full article text only for sources whose structure and access rules permit it.
- Preserve title + RSS excerpt as the guaranteed fallback and expose crawl provenance/status.

### Origin Map

- Show when a claim/article was first observed and how coverage propagated across sources.
- Depends on reliable Cassandra observation timestamps and the Neo4j source/article/story graph.
- “First observed by VietFeed” must not be presented as “first published on the internet.”

### Push-based progressive StoryLens updates

- Replace or augment MVP HTTP polling with Server-Sent Events or WebSockets.
- Do this only if polling is demonstrably insufficient; backend processing semantics must remain unchanged.

### Admin permission templates

- Add reusable templates such as Content Admin, Moderation Admin, and Operations Admin.
- Templates populate the same fixed permission catalog; direct per-admin grants remain the underlying model.

## Later product directions

### Custom roles and scoped administration

- Let Super Admin create named role definitions and optionally scope permissions to selected categories or sources.
- Requires conflict rules for role permissions versus per-user overrides, role lifecycle handling, and a stronger authorization test matrix.

### Diversity Passport

- Explain a user’s source/topic exposure over time without claiming to diagnose or “fix” political bias.
- Depends on sufficient real or synthetic interaction history and a stable recommendation graph.

### Learned scoring or weight calibration

- Evaluate automatic tuning of clustering/trending/recommendation weights on a labelled dataset.
- Current project deliberately uses explainable heuristic weights with only small manual sanity checks.

### Billing audit projection in Cassandra

- Project accepted Stripe subscription events into a time-ordered audit view for analytics/support.
- Stripe remains externally authoritative and MongoDB remains the application’s current subscription projection; Cassandra must not enter the Billing critical path.

### Graph-assisted abuse investigation

- Explore repeated reports or coordinated abuse across users, comments, articles, and shared entities in Neo4j.
- Add only after the broken baseline Report feature is repaired and there is a concrete query that is better than a MongoDB lookup.
