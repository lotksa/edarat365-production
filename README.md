# Edarat365 - Production Deployment Package

This repository contains the **production-ready** build for cPanel deployment.

## Structure

```
edarat365-production/
├── public_html/         → goes to /home/USER/public_html/
├── laravel-app/         → goes to /home/USER/laravel-app/
├── database/
│   └── edarat365.sql    → import via phpMyAdmin
├── .cpanel.yml          → cPanel auto-deploy config
└── DEPLOYMENT_GUIDE.md  → full step-by-step guide
```

## Quick Deploy on cPanel

1. **cPanel** → **Git Version Control** → **Create**
2. Clone URL: `https://github.com/lotksa/edarat365-production.git`
3. Repository Path: `/home/USER/edarat365-deploy/`
4. Click **Create**
5. After clone → **Manage** → **Pull or Deploy** → **Update from Remote** → **Deploy HEAD Commit**

The `.cpanel.yml` will automatically copy files to the right locations.

Then follow `DEPLOYMENT_GUIDE.md` for database setup and `.env` configuration.
# Current release: association workspace and document identity

This release is limited by `release-manifest.json`. cPanel runs the existing PHP/Brotli security gate, then `laravel-app/tools/release-guard.php`.

Before deploying, verify the live paths and create a fresh database dump (with routines/triggers/events), uploads archive and environment backup outside the application/web roots. The helper checks archive hashes and preserves a fingerprint of existing database rows and uploads. It copies only reviewed code/assets, runs the two named additive migrations, and verifies data preservation afterward. It never runs seeders, cleanup commands, or storage-link replacement.

The backup directory named in `.cpanel.yml` must contain a fresh `verified.json` created by the helper. A later release must use its own backup directory and manifest. Do not use `setup.sh` for updates of an existing installation.
