# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# First-time setup
composer run setup          # install deps, copy .env, migrate, build assets

# Development (runs 4 concurrent processes)
composer run dev            # artisan serve + queue:listen + pail + vite dev

# Individual processes
php artisan serve
npm run dev                 # Vite with HMR
php artisan queue:listen --tries=1 --timeout=0
php artisan pail            # live log viewer

# Assets
npm run build               # production Vite build → public/build/

# Database
php artisan migrate
php artisan db:seed                              # all seeders
php artisan db:seed --class=CategorySeeder      # one seeder

# RSS feeds (also runs on scheduler every 30 min)
php artisan feeds:fetch

# Tests (uses SQLite in-memory, see phpunit.xml)
composer run test           # clears config cache, then phpunit

# Code style
vendor/bin/pint             # Laravel Pint formatter
```

## Architecture

**Stack**: Laravel 13, PHP 8.3+, MySQL, Blade + Bootstrap 5 + Alpine.js, Vite + Tailwind CSS.

### Request lifecycle

Public traffic hits controllers in `app/Http/Controllers/`. Authenticated routes use Laravel Breeze middleware groups. Admin routes additionally pass through `app/Http/Middleware/AdminMiddleware.php` which checks `user->isAdmin()`.

Route groups in `routes/web.php`:
- **Guest** — home, articles, categories, search, auth pages
- **`auth`** — bookmark toggle (soft-gated: returns JSON if unverified)
- **`auth` + `verified`** — bookmarks index, comments, profile, onboarding
- **`auth` + `admin`** — `/admin/dashboard`
- **API** — `/api/live-search` (JSON, 6 results), `/api/articles` (infinite scroll pagination)

### Models & relationships

```
User ─── 1:M ──→ Bookmark ──── M:1 ──→ Article ──── M:1 ──→ Category
  └──── 1:M ──→ Comment ─────────────────────────────────────────────
  └──── M:M ──→ Category (pivot: category_user)
                  ↑
              1:M ──→ Source ──── 1:M ──→ Article
```

- `User` — `role` field ('user'|'admin'), helpers `isAdmin()` / `isUser()`, implements `MustVerifyEmail`
- `Article` — `original_url` is the dedup key; has `readingTime` computed attribute
- `Comment` — threaded via `parent_id`
- `Source` — RSS feed config; tracks `last_fetched_at`

### RSS aggregation

`app/Console/Commands/FetchFeeds.php` — scheduled every 30 min (`routes/console.php`). Uses `SimpleXML` with entity decoding for Vietnamese text. Calls `updateOrCreate` on `original_url` to avoid duplicates. Extracts images from enclosure, `media:content`, or inline `<img>` tags.

### Frontend interactivity (`resources/js/app.js`)

All client-side logic is in a single file: dark mode (localStorage), auto-hide navbar on scroll, sticky category tabs, bookmark toggle AJAX, live search dropdown (min 2 chars), infinite scroll on homepage, comment reply toggle, email verification modal.

### Views

Blade templates in `resources/views/` grouped by feature: `home/`, `articles/`, `auth/`, `admin/`, `bookmarks/`, `search/`, `profile/`, `onboarding/`, `categories/`. Shared components in `components/`. Two layouts: `AppLayout` (navbar + sidebar) and `GuestLayout` (auth pages). Bootstrap 5 pagination is configured in `AppServiceProvider`.

## Key config

- `APP_URL=http://vietfeed.test` locally
- `APP_LOCALE=vi`; timezone UTC
- Session, cache, and queue all use the **database** driver
- Tests run against **SQLite in-memory** (set in `phpunit.xml`) regardless of `.env`
