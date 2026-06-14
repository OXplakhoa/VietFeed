---
title: The Request Lifecycle
date: 2026-06-12
---

# The Request Lifecycle

**Insight:** User now has a concrete 7-step model for tracing any HTTP request through VietFeed: Entry Point → Bootstrap → Global Middleware → Router → Controller → Eloquent → Blade → Response.

**Key concepts introduced:**
- `public/index.php` as the single entry point
- Middleware as a cross-cutting concern (e.g. `CheckUserSanction` applied globally)
- MVC separation: no SQL in views, no HTML in controllers
- Sensational score computed in SQL (not PHP) for performance — this is a strong talking point
- `when()` as a clean conditional query builder pattern
- Personalisation via the `category_user` pivot (many-to-many from Lesson 1)

**Zone of proximal development:** User has completed Laravel/Eloquent basics (Lesson 1) and lifecycle fundamentals (Lesson 2). Next logical step: Admin middleware & role-based access control, or the RSS ingestion pipeline — both are highly likely lecturer questions.
