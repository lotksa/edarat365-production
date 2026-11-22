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

## PHP and application security update

The cPanel deployment requires EasyApache PHP 8.5.13 or a later 8.5 patch, disabled upload progress, and a verified Brotli decoder when cURL enables it. `release-manifest.json` records the checksums of the reviewed application, Composer dependencies, and frontend assets.

Before pulling the update, verify the live paths and use the existing release helper to create a fresh database dump, uploads archive, and environment backup outside the application and web roots. The backup directory for this release is `/home1/edarat/deploy-backups/edarat365-20261122-security-v1`.

The deployment checks the backup and content hashes, briefly pauses API traffic while it activates the verified `vendor` directory, preserves the previous `vendor` directory in the backup, and verifies existing database rows and uploads after deployment. This update requires zero database migrations. Use the cPanel Git deployment for existing installations.
