# Persistent UAT — operator bootstrap checklist

Complete these **outside** Cursor Cloud. Do not paste secrets into chat or git.

## Blockers until completed

1. **Linux host** with SSH access for a deploy user  
2. **DNS** `A`/`AAAA` for `uat.dop.moximu.com` (or approved hostname) → host  
3. **TLS** certificate for that hostname  
4. **MySQL 8** database + user for UAT only  
5. **PHP 8.3+** with extensions used by MoxDOP + **PHP-FPM**  
6. **Nginx** site using `deploy/uat/nginx.conf.example`  
7. **Supervisor** program using `deploy/uat/supervisor-moxdop-worker.conf.example`  
8. **Cron** `* * * * * php artisan schedule:run` for the app user  

## First-time app setup (once)

```bash
sudo mkdir -p /var/www/moxdop-uat
sudo chown deploy:www-data /var/www/moxdop-uat
# clone repo as deploy user into /var/www/moxdop-uat
cd /var/www/moxdop-uat
git checkout cursor/async-operations-activity-center-ea01   # until merge
cp .cursor/dotenv.uat.example .env
# edit .env: MySQL password, APP_URL, generate APP_KEY ONCE:
php artisan key:generate --force
# verify APP_DEBUG=false, QUEUE_CONNECTION=database, DB_CONNECTION=mysql
bash deploy/uat/deploy.sh
php artisan dop:create-admin   # strong password; interactive
```

## After deploy — product data (controlled)

1. Create Customer / Brand / Meta Ads Digital Asset as needed  
2. Configure Meta Integration credentials in Admin (encrypted) — **no token in shell history if avoidable**  
3. Discover resources  
4. **Explicitly** bind `act_744654160596455` only if operator confirms  
5. Do **not** import Cloud SQLite  
6. Do **not** seed synthetic Meta fixtures  

## Prove runtime

```bash
curl -I https://uat.dop.moximu.com/app/login
php artisan queue:monitor   # or supervisorctl status
php artisan schedule:list
# optional synthetic stale: create running async run in a disposable way, wait for mark-stale
```

## Then run human Async UAT

Follow the checklist in `docs/operations/PERSISTENT_UAT.md`.
