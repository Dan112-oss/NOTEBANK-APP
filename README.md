# Note Bank V1

Verified academic document marketplace — Landmark University first deployment.
Built to the project's *Note Bank V1 Implementation & Development Guide*.

## Stack

PHP 8.1+, MySQL/MariaDB (InnoDB, utf8mb4), vanilla HTML/CSS/JS. No frontend
framework, no build step. PHPMailer for SMTP email. Preview/download
watermarking runs on a pure-PHP pipeline (FPDI + TCPDF, both **pre-vendored
in this zip** — no `composer install` required to run) so it works even on
hosts like InfinityFree that disable `exec()`/`shell_exec()`; invoices use a
small dependency-free PDF writer built into this project
(`includes/pdfwriter.php`). See **PDF handling** below for the full picture.

## Quick start (local, XAMPP or equivalent)

**This is a PHP application, not a static site** — there is no
`index.html` and no flat list of pages to open in a file browser.
`.php` files only render as pages when a web server (Apache, via
XAMPP) executes them. Opening a `.php` file directly (double-click,
or a `file://` path in your browser) will show raw code or nothing at
all. You have to run it through Apache and visit it with a URL —
steps below.

1. Unzip this project into XAMPP's `htdocs` folder, e.g.
   `C:\xampp\htdocs\notebank`, and start **Apache** and **MySQL** from
   the XAMPP control panel.
2. A working `.env` **ships with this build**, pre-filled for a
   default XAMPP setup (`root` user, no password, `localhost`). You
   only need to touch it if your MySQL root user has a password or you
   changed ports — see `.env.example` for every available setting.
3. Create the database and import the schema:
   ```
   mysql -u root -p -e "CREATE DATABASE notebank CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   mysql -u root -p notebank < database/schema.sql
   ```
   (or use phpMyAdmin's Import tab, pointed at the same file). This
   seeds Landmark University's academic structure (a few faculties,
   departments, levels, courses) as ordinary data — see
   **Multi-university expansion** below — plus a default admin account
   and platform settings.
4. Nothing else to install for previews/downloads to work. FPDI and TCPDF
   ship already vendored in `vendor/` — `includes/watermark.php` detects
   them automatically and uses a pure-PHP watermarking path (no shell
   commands at all), which is what makes this safe to deploy to
   InfinityFree later. If `poppler-utils` (`pdftoppm`/`pdfinfo`) happens
   to be installed too, it's used only as a fallback for environments
   where the vendored libraries were removed — not needed on a normal
   XAMPP install.
5. Visit `http://localhost/notebank/setup-check.php` in your browser.
   It checks everything above in one page — PHP version/extensions,
   database connectivity, whether the pure-PHP watermarking path (FPDI/
   TCPDF) is active, upload size limits, storage permissions — and tells
   you exactly what's still missing instead of you hitting broken
   features one at a time. **Delete this file once everything passes**
   — it's a setup tool, not part of the app, and shouldn't be left
   reachable.
6. Visit `http://localhost/notebank/` (not a file path — an actual
   URL) once every check passes.
7. **Default admin login:** `admin@notebank.test` / `NoteBank@2026` —
   change this password immediately from Admin → Settings.

### PHPMailer (optional)

```
composer install
```
If you skip this, the app still runs fully: `send_mail()` falls back
to PHP's built-in `mail()` function, and every attempt is logged to
the `email_logs` table either way. Only needed for real outbound
email via SMTP.

## Project layout

Matches the guide's specified structure: `admin/`, `student/`, `api/`,
`config/`, `includes/`, `assets/`, `storage/`, `database/`. A few files
exist beyond the guide's explicit list because the app needs them to
actually run securely:

- `includes/pdfwriter.php` — the dependency-free PDF writer used by both
  `includes/invoice.php` and `includes/watermark.php`.
- `includes/partials/` — shared header/footer/flash-message templates for
  the admin and student layouts, so every page shares one navigation and
  one design system instead of duplicating markup.
- `admin/view-proof.php`, `admin/invoice.php` — authenticated streaming
  endpoints so payment evidence and invoices are never linked directly
  into `storage/` (which is blocked from the web entirely).
- `api/_bootstrap.php` — shared JSON-response auth guard for the `api/`
  endpoints (the page-level `require_admin()`/`require_student()` guards
  redirect on failure, which is wrong for a JSON API).

## Security notes

- `storage/`, `config/`, `includes/` and `database/` each carry an
  `.htaccess` with `Require all denied` — none of them are reachable by
  URL **on Apache with `AllowOverride All` enabled** (XAMPP's default for
  `htdocs`). `.htaccess` is Apache-only and is silently ignored by PHP's
  built-in dev server (`php -S`) and by Nginx. If you deploy behind
  Nginx, add an equivalent `location` block denying `/storage/`,
  `/config/`, `/includes/` and `/database/` before going live — the
  `.htaccess` files alone will not protect you there. Either way,
  confirm with a direct browser request before trusting it in production. Protected documents are only ever served through
  `api/preview.php`, `api/downloads.php`, `admin/view-proof.php` and
  `admin/invoice.php`, all of which check session, ownership/entitlement
  and (for previews) a short-lived signed token before streaming a file.
- Uploaded documents get a random filename (`bin2hex(random_bytes(16))`)
  — never the database ID, never the client's original filename.
- Every password uses `password_hash()`/`password_verify()`. Every form
  that changes state carries a CSRF token (`includes/csrf.php`), checked
  server-side regardless of what client-side JS already validated.
- All SQL goes through PDO prepared statements — see `includes/functions.php`
  and every `admin/`/`student/`/`api/` file for the pattern.
- Cart/order totals are always recalculated server-side from `documents.price`
  at checkout — the price a browser sends is never trusted.

## PDF handling: preview watermarking, download watermarking, invoices

- **Invoices** (`includes/invoice.php`) use a small dependency-free
  `SimplePdf` class (`includes/pdfwriter.php`) — base-14 Helvetica text,
  lines, filled rectangles — to draw text and the Note Bank logo
  directly. No external library needed.
- **Preview/download watermarking** (`includes/watermark.php`) has two
  independent implementations, chosen automatically at runtime by
  `fpdi_available()`:
  - **Pure-PHP path (primary)** — FPDI imports each page of the
    protected original as a vector template (original content stays
    crisp, nothing is rasterized), and TCPDF draws a tiled, rotated,
    translucent watermark (Note Bank + the viewer's email + a
    timestamp) on top. Zero shell commands. Both libraries ship
    **pre-vendored in `vendor/`** — no `composer install` needed to run
    — which is what makes this build safe to deploy to InfinityFree and
    other hosts that disable `exec()`/`shell_exec()`/`proc_open()`.
  - **poppler fallback (secondary)** — if the vendored libraries are
    ever removed, the app falls back to rasterizing the original with
    `pdftoppm`, stamping the watermark onto each page image with GD,
    and reassembling the result with `SimplePdf`. This path needs
    poppler-utils installed and shell functions enabled, so it won't
    work on InfinityFree — it exists purely as a safety net for other
    hosting environments.
  - Either way, if neither path is available, preview/download
    requests fail closed (HTTP 503) rather than ever risk exposing an
    unwatermarked original.
  - The stamp's intensity is deliberately different for the two cases
    (`stamp_watermark_tcpdf()`/`watermark_image()`, `$mode` parameter):
    **previews** use a big, dense, dark watermark — the point is that a
    screenshot of the free preview shouldn't give away clean, readable
    content, since that's what's supposed to sell the purchase.
    **Downloads** use a small, sparse, light watermark — that's the
    paying student's own copy, so it stays there for traceability if it
    ever leaks, without fighting the real content for attention.

Both paths were built and verified against real PDFs end-to-end during
development (upload → import/rasterize → watermark → render), including
running the actual `includes/watermark.php` functions directly and
inspecting the resulting PDFs page-by-page. `setup-check.php` reports
which path is active on your server.

## Email verification

Registration still sends a verification email and records `email_verified`
on the student, but **it no longer blocks checkout** — a student can
register and purchase immediately. This was a deliberate choice: on a
free host like InfinityFree, outbound mail is unreliable (see below),
so gating a paying customer on an email that might never arrive isn't
worth it. The verification link/token machinery
(`student/verify-email.php`, the `email_verifications` table) is left
in place and harmless if unused — it's a small change to re-add the
gate later (`student/checkout.php`, `api/orders.php`) if you move to a
host with reliable email and want it back, e.g. for fraud control or
password-reset trust.

If you ever do want real outbound email in production: most free PHP
hosts, InfinityFree included, block or badly rate-limit port 25/465/587
SMTP traffic, so PHPMailer configured with a normal SMTP relay often
just won't deliver. The reliable options at that point are (a) a
transactional email API (Brevo, Mailjet, Resend, SendGrid — free tiers
exist) called over HTTPS instead of SMTP, which isn't blocked, or (b)
upgrading to paid hosting with real outbound SMTP.

## Multi-university expansion

Landmark University's faculties, departments, levels and courses are
**seed data** in `database/schema.sql`, not hard-coded PHP. Section 19 of
the guide is followed exactly: add a new university, its faculties,
departments, levels and courses entirely through Admin → Academic
Structure, and the whole application — catalogue, cart, checkout,
watermarking, reporting — works for it without any code change. Course
codes are scoped to `(university_id, department_id)`, not global.

## Testing

`database/schema.sql` was imported against a real MySQL/MariaDB instance
during development, and the full purchase flow was exercised end-to-end
against it: student registration → browse → add to cart → checkout →
submit MTN/Orange payment evidence → admin review → approve →
entitlement created exactly once → invoice generated → student
downloads a **genuinely watermarked** PDF. Before your own deployment, walk
through the guide's section 16 testing checklist against your production
configuration (HTTPS, real SMTP, real payment account numbers).

## Deployment checklist (see the guide, section 17)

- [ ] `APP_ENV=production` in `.env` (disables error display, keeps logging)
- [ ] HTTPS enforced; `APP_URL` uses `https://`
- [ ] Real database credentials, least-privileged DB user
- [ ] Real SMTP credentials for `send_mail()`
- [ ] `storage/` is writable by the web server user but not web-reachable
      (already enforced by `.htaccess`; confirm your host honors it)
- [ ] `vendor/` (FPDI + TCPDF) was uploaded along with the rest of the
      project — it's what makes watermarking work without `exec()` on
      InfinityFree; no `composer install` needed unless you removed it
- [ ] If you're on a host that *does* allow shell functions and you'd
      rather manage dependencies via Composer instead of the vendored
      copy: `composer install --no-dev --optimize-autoloader`
- [ ] Default admin password changed
- [ ] Real MTN Mobile Money / Orange Money account details set in
      Admin → Settings
- [ ] Database backup schedule in place before go-live
- [ ] `setup-check.php` deleted (it's a local setup aid, not for production)
- [ ] The `.env` that ships in this zip (with a working local `APP_KEY`)
      is replaced with your own — never reuse a development key/secret
      in production
