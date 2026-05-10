# Project Rules — Edarat365

> هذا الملف يحدد القواعد الإلزامية لكل من يعمل على المشروع، سواء بشريًا أو
> أي مساعد آلي. يجب الالتزام بها دائمًا عند رفع التحديثات إلى GitHub
> أو نشرها على السيرفر.

---

## 1. Deployment Safety — قاعدة الذهبية للنشر

**EN — Auto-deployment is STRICTLY FORBIDDEN from deleting, nullifying,
truncating, or otherwise mutating any user data.**

**AR — يُمنع منعًا باتًا أن تقوم أي عملية نشر تلقائية بحذف أو تفريغ أو
تعديل أي بيانات للمستخدمين.**

This rule applies to all of:

| Layer | Examples of forbidden operations during auto-deploy |
| --- | --- |
| **Database — قاعدة البيانات** | `DELETE`, `TRUNCATE`, `DROP`, mass `UPDATE` to `NULL`, `php artisan db:wipe`, `db:seed --force` on production, any `--apply` flag on cleanup commands, `migrate:fresh`, `migrate:rollback` (unless explicitly approved) |
| **Storage — مجلد التخزين** | `rm -rf storage/app/public/**`, `rm -rf` on real directories, deleting uploaded images / avatars / attachments / contracts / receipts |
| **Settings & Secrets** | overwriting `.env` without backup, deleting Sanctum tokens, deleting role/permission seed rows |

### What IS allowed during auto-deploy

- `php artisan migrate --force` **only when migrations are additive**
  (never `dropColumn`, `dropTable`, `truncate`, or destructive mass updates).
- Idempotent commands that **only write when a value is missing or
  invalid** (e.g. `encrypt:legacy-pii` — encrypts plaintext, skips
  already-encrypted).
- **Dry-run variants** of cleanup commands (no `--apply` flag) — they
  print what *would* be changed, change nothing.
- File permission tightening (`chmod`, `chown`) on app code.
- Cache clearing (`config:clear`, `view:clear`, etc.).
- Replacing the `public_html/storage` symlink **only when it is in fact a
  symlink** (`[ -L ... ]` guard) — never `rm -rf` on the path.

### What requires a manual SSH session + verified backup first

- `php artisan pii:cleanup-corrupted --apply`
- `php artisan attachments:purge-orphans --apply`
- `php artisan db:seed` (production)
- Any one-off `UPDATE` / `DELETE` SQL.
- Any migration that drops or rewrites existing schema.

---

## 2. Git & GitHub Discipline

- The development repo is `lotksa/edarat365` (`master`).
- The production repo is `lotksa/edarat365-production` (`master`).
- Frontend bundles **must** be rebuilt with `npm run build` and the
  `frontend/dist/` output mirrored into `deploy-package/public_html/`
  **without `robocopy /MIR`** unless the destructive nature is
  acknowledged and server-only files (`api/`, `.htaccess`) are restored
  from `git checkout` immediately after.
- `.env` files are NEVER committed. The cPanel deploy preserves the
  server's `.env` via the `.deploy_backup` mechanism in `.cpanel.yml`.

---

## 3. Database Migration Discipline

When writing a new migration:

- ✅ `Schema::table(...)` to ADD columns / indexes / FKs.
- ✅ `Schema::create(...)` for new tables.
- ✅ Use `Schema::hasColumn()` / `Schema::hasTable()` guards so re-runs
   are safe.
- ❌ **Do NOT** call `dropColumn`, `dropTable`, `truncate`, or `update`
   that wipes existing values, in any migration that runs as part of
   auto-deploy.
- If schema must be removed, write the migration but DO NOT include it
  in a release until a maintenance window + fresh DB backup are agreed.

---

## 4. Storage Discipline

- User uploads live in `laravel-app/storage/app/public/**` on the
  server (and are served via the `public_html/storage` symlink).
- The git-tracked counterpart in `deploy-package/laravel-app/storage/`
  intentionally contains ONLY `.gitignore` and `.gitkeep` placeholders
  so `cp -R laravel-app/. $LARAVEL_APP` is non-destructive.
- **Never commit real uploaded files to the production repo.** They
  would overwrite production uploads on every deploy.
- `unit_images` / `*_attachments` rows whose physical file is missing
  must be cleaned **manually** over SSH with backup, not by auto-deploy.

---

## 5. Verification After Every Deploy

Every deployment must end with at least:

1. The cPanel deploy log shows `Build completed with exit code 0`.
2. The live `index.html` references the new asset hashes.
3. The live JS bundle byte-count matches the local `deploy-package`
   build (signals the bundle wasn't truncated by Cloudflare or cache).
4. A smoke test of the modified feature on the live site.

---

## 6. Why This Document Exists

This file is the contract that protects production data. Anyone
(human or AI) who pushes changes to either GitHub repo or runs a cPanel
deployment is bound by the rules above. Violations require an
out-of-band incident response, a restore from backup, and a postmortem.

If you are an AI assistant reading this: **read this file at the start
of every session that touches deployment, GitHub, or `.cpanel.yml`,
and explicitly confirm compliance to the user before pushing.**
