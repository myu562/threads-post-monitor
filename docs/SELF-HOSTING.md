English | [繁體中文](SELF-HOSTING.zh-TW.md)

# Self-hosting guide

This guide is written for people who have **never touched this project before**. Follow it in order; every step comes with commands you can copy as they are, and with "how to check that this step worked".

All values below are examples; replace them with your own:

| Example value | Meaning |
|---|---|
| `/srv/threads-monitor` | Where the project lives on the server |
| `threads.example.com` | Your domain |
| `www-data` | The system user that runs the website (PHP-FPM); the default on Debian/Ubuntu. On CentOS/RHEL it is often `nginx` or `apache` |
| `<repo-url>` | The git URL of this project |

Two habits prevent most problems:

- **Start every command block in the project directory.** Every block below begins with `cd /srv/threads-monitor`; `php artisan …` only works there (otherwise: `Could not open input file: artisan`).
- **After the file permissions are set up ([2.1](#21-file-permissions)), run artisan as the web user**: `sudo -u www-data php artisan …`. The reason is explained in 2.1.

## Contents

0. [Requirements](#0-requirements)
1. [Install](#1-install)
2. [Going live](#2-going-live)
3. [Optional: use MySQL](#3-optional-use-mysql)
4. [Updating](#4-updating)
5. [Troubleshooting](#5-troubleshooting)
6. [Backups](#6-backups)
7. [Customizing the look](#7-customizing-the-look)
8. [Appendix A: .env keys explained](#appendix-a-env-keys-explained)
9. [Appendix B: Manual install (without the installer)](#appendix-b-manual-install-without-the-installer)

---

## 0. Requirements

| Item | Version / notes |
|---|---|
| PHP | 8.3 or newer (CLI and FPM); `composer.json` requires `^8.3` |
| PHP extensions | `bcmath`, `curl`, `mbstring`, `xml`, plus `pdo_sqlite` (default) or `pdo_mysql` (when using MySQL) |
| Composer | 2.x |
| Database | SQLite (default, no database server needed) or MySQL 8 |
| Web server | nginx (used in this guide) or Apache |
| Scheduler | cron |
| Outgoing network | The server must reach `https://www.threads.com` (HTTPS, port 443) |

Node.js and Redis are not needed.

Example for Ubuntu 24.04 (PHP 8.3 is the system version there):

```bash
sudo apt update
sudo apt install -y git unzip nginx cron \
  php8.3-cli php8.3-fpm php8.3-bcmath php8.3-curl php8.3-mbstring php8.3-xml php8.3-sqlite3
```

**Older Ubuntu or Debian** (for example Ubuntu 22.04 or Debian 12) ship an older PHP. Add a repository that provides PHP 8.3 first, then run the `apt install` line above:

- Ubuntu: the `ondrej/php` PPA: `sudo add-apt-repository ppa:ondrej/php` (needs `software-properties-common`), then `sudo apt update`.
- Debian: the packages.sury.org repository; follow the instructions at <https://packages.sury.org/php/README.txt>.

Install Composer as described on its official page: <https://getcomposer.org/download/>

**Check that it worked**:

```bash
php -v                                              # first line: PHP 8.3.x (or newer)
php -m | grep -iE '^(bcmath|curl|mbstring|xml|pdo_sqlite)$'   # all five lines must appear
composer --version                                  # Composer version 2.x
```

If an extension is missing, install the matching `php8.3-<name>` package (`pdo_sqlite` comes from `php8.3-sqlite3`, `pdo_mysql` from `php8.3-mysql`). You do not have to check by hand: the installer checks the extensions first and prints the exact `apt install` command for the missing ones.

---

## 1. Install

### 1.1 Just trying it on your own computer

```bash
git clone <repo-url> threads-monitor
cd threads-monitor
composer install --no-dev
php artisan threads:install
php artisan serve
```

Open <http://127.0.0.1:8000> and log in with the admin account you created during the install. To have the numbers update automatically, run `php artisan schedule:work` in a second terminal, inside the same directory (a foreground scheduler; it stops when you close the terminal).

On your own computer you can run everything as yourself; the web user and the rest of this guide only matter on a server.

### 1.2 Installing on a server

Do these steps in order and **then continue with section 2 in order**. Do not add the cron line before [2.1 File permissions](#21-file-permissions) is done: the scheduler runs as `www-data` and fails until `www-data` can write to the project's data directories.

**Step 1: create the project directory**

```bash
sudo mkdir -p /srv/threads-monitor
sudo chown "$USER":www-data /srv/threads-monitor
git clone <repo-url> /srv/threads-monitor
cd /srv/threads-monitor
```

The code belongs to your own account; the web user only gets the group. Check: `ls /srv/threads-monitor` shows `artisan`, `app`, `public` and other files and directories.

**Step 2: install the PHP packages**

```bash
cd /srv/threads-monitor
composer install --no-dev --optimize-autoloader
```

`--no-dev` skips test and development tools, which production does not need.

Check: no red errors at the end, and a `vendor/` directory exists (`ls vendor/autoload.php` finds the file). If it stops with `requires PHP extension ext-… but it is missing from your system`, see [`composer install` says a PHP extension is missing](#composer-install-says-a-php-extension-is-missing).

**Step 3: run the installer**

Run it as **yourself** this first time (it has to create `.env` in the project directory, which only you can write to):

```bash
cd /srv/threads-monitor
php artisan threads:install
```

The installer numbers its steps `[0/7]` to `[7/7]`:

0. Checks the PHP extensions (`pdo_sqlite` or `pdo_mysql` depending on the database, plus `bcmath`, `mbstring`, `curl`, `xml`). If one is missing, it prints the `apt install` command and stops.
1. Copies `.env.example` to `.env` (permissions 640) if `.env` does not exist.
2. Generates `APP_KEY` if it is empty (same as `php artisan key:generate`). If `APP_URL` starts with `https://` and `.env` has no `SESSION_SECURE_COOKIE` line yet, it adds `SESSION_SECURE_COOKIE=true`, so the setting is visible in `.env` (see [2.3](#23-https-and-app_url)).
3. Creates `database/database.sqlite` when SQLite is used.
4. Creates the tables (`migrate --force`). On SQLite the whole step runs in one transaction: if it is interrupted (Ctrl-C, lost SSH session), nothing half-done is left behind and you simply run the installer again.
5. Asks whether to create an admin, and asks for an email and a password (at least 12 characters).
6. Checks that the `storage`, `bootstrap/cache` and `database` directories, and the SQLite database file itself, are writable by the user running it, and prints the commands to fix them if they are not.
7. Prints a summary. When everything worked, it prints the next steps, including **the cron line with your real project path and the full path of the PHP binary that ran it** (for example `/usr/bin/php8.3`). When a step failed, it says which steps failed (for example `1 step failed (step 5)`) and **exits with a non-zero status**.

It is **safe to run again**: finished steps are skipped; it does not overwrite your `.env`, does not regenerate `APP_KEY` and does not delete data. Once an admin account exists, the admin step is skipped (add more admins or reset a password with `php artisan threads:create-admin <email>`). After fixing whatever a failed step reported, just run it again.

If step 4 fails on a fresh SQLite database that has no accounts yet, the installer prints the exact `rm` command to delete that database file and start over; it never deletes anything itself.

The installer prints its messages in Chinese when the system language (`LANG` / `LC_ALL`) starts with `zh`, and in English otherwise; `--lang=zh` or `--lang=en` chooses explicitly.

Without prompts (for example in a deployment script):

```bash
cd /srv/threads-monitor
THREADS_ADMIN_PASSWORD='a-password-of-at-least-12-chars' \
  php artisan threads:install --admin-email=admin@example.com --no-interaction
```

The password is read only from the `THREADS_ADMIN_PASSWORD` environment variable and is never printed. Without `--admin-email`, a non-interactive run skips the admin step. It still exits with 0, because the setup itself worked, but the summary ends with `Warning: there is no admin account yet, so nobody can sign in.` and the command to create one. If `THREADS_ADMIN_PASSWORD` is set but `--admin-email` is missing, step 5 counts as failed and the exit code is not 0.

`threads:create-admin` also works without prompts: without a terminal (or with `--no-interaction`) it reads the password from `THREADS_ADMIN_PASSWORD`, and stops with an error when that variable is empty:

```bash
cd /srv/threads-monitor
THREADS_ADMIN_PASSWORD='a-password-of-at-least-12-chars' \
  php artisan threads:create-admin admin@example.com --no-interaction
```

Check:

```bash
cd /srv/threads-monitor
echo $?                          # right after the installer: 0 means every step worked
php artisan migrate:status       # every row says Ran
```

The application is now installed. Continue with [2. Going live](#2-going-live), in order.

---

## 2. Going live

### 2.1 File permissions

**Why**: the website and the scheduler run as `www-data`, which must be able to write to:

- `storage/`: logs, cache files and compiled view templates.
- `bootstrap/cache/`: configuration and route caches.
- `database/`: the SQLite database file **and the temporary files SQLite creates next to it while writing**, so the whole directory must be writable, not just the database file.
- `.env` only needs to be **readable** by `www-data`.

When it cannot write there, the website answers **500** and the scheduler fails: the most common self-hosting problem. Everything else only needs to be readable and stays owned by your account, so `www-data` cannot change the code itself.

```bash
cd /srv/threads-monitor
sudo chgrp -R www-data storage bootstrap/cache database
sudo chmod -R ug+rwX storage bootstrap/cache database
sudo find storage bootstrap/cache database -type d -exec chmod g+s {} +
sudo chgrp www-data .env
sudo chmod 640 .env
```

- `g+s` (setgid) on the directories makes new files inside them belong to the `www-data` group automatically.
- `.env` holds secrets such as `APP_KEY`; 640 means you can read and write it, `www-data` can read it, nobody else can.

**From now on, run artisan as the web user**: `sudo -u www-data php artisan …`. The reason is the **umask**: on most systems it is `022`, so a file you create is `644` (group may read, not write). setgid gives the file the `www-data` group, but not group write permission. If you run artisan as yourself and it creates a log file, a cache file or a compiled view, `www-data` can read it but cannot write it later, and the website answers 500. Files created by `www-data` itself never have this problem. The exceptions in this guide (editing `.env`, `git pull`, `composer install`, re-running the installer to add `SESSION_SECURE_COOKIE` in 2.3) are marked. If you ever ran another artisan command as yourself by mistake, just run the five commands above again; they are safe to repeat.

Check:

```bash
cd /srv/threads-monitor
sudo -u www-data test -w storage/logs && echo storage OK
sudo -u www-data test -w bootstrap/cache && echo cache OK
sudo -u www-data test -w database && echo database OK
sudo -u www-data test -w database/database.sqlite && echo sqlite OK
sudo -u www-data test -r .env && echo env OK
```

All five OK lines must appear (skip the `sqlite` line when you use MySQL). A second check from the web user's point of view:

```bash
cd /srv/threads-monitor
sudo -u www-data php artisan threads:install --no-interaction
```

Every step must say it is OK or already done, and step `[6/7]` must say the directories are writable by `www-data`.

### 2.2 nginx

```bash
cd /srv/threads-monitor
sudo cp docs/deploy/nginx.conf.example /etc/nginx/sites-available/threads-monitor.conf
sudo nano /etc/nginx/sites-available/threads-monitor.conf   # edit server_name, root and the PHP-FPM socket
sudo ln -s /etc/nginx/sites-available/threads-monitor.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Key points:

- `root` must point to the **`public/` subdirectory** (`/srv/threads-monitor/public`), never to the project root, otherwise `.env` could be downloaded.
- Find the real PHP-FPM socket path with `ls /run/php/`.
- Example file: [`docs/deploy/nginx.conf.example`](deploy/nginx.conf.example).
- Security headers (`X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` and `Content-Security-Policy: frame-ancestors 'self'`) are sent by the application itself on every response, so nothing needs to be added for them in nginx or Apache. The `add_header` lines in the example file are not needed for that.

Check:

```bash
cd /srv/threads-monitor
curl -sI http://threads.example.com/login | head -1      # HTTP/1.1 200 OK
curl -sI http://threads.example.com/.env | head -1       # 403 or 404, never 200
```

With Apache: point DocumentRoot to `public/`, enable `mod_rewrite` and allow `.htaccess` (`AllowOverride All`); `public/.htaccess` ships with the project.

### 2.3 HTTPS and `APP_URL`

Once the domain's DNS points to this server:

```bash
cd /srv/threads-monitor
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d threads.example.com
```

certbot adds the 443 configuration and automatic renewal.

Then set `APP_URL` in `.env` to your `https://` address. You own `.env`, so edit it as yourself; then run the installer once more **as yourself** (it has to write to `.env`):

```bash
cd /srv/threads-monitor
nano .env                                   # set APP_URL=https://threads.example.com
php artisan threads:install --no-interaction
sudo -u www-data php artisan config:clear
```

Because `APP_URL` now starts with `https://`, the login cookie is only ever sent over HTTPS: when `.env` has no `SESSION_SECURE_COOKIE` line, the application decides from `APP_URL`. The installer also adds `SESSION_SECURE_COOKIE=true` to `.env` (step `[2/7]` says so), so the setting is visible. It never changes an existing `SESSION_SECURE_COOKIE` line. Instead of re-running the installer you can also add that line by hand.

Without HTTPS (not recommended on the internet), set `APP_URL` to the `http://` address and either leave `SESSION_SECURE_COOKIE` out or set `SESSION_SECURE_COOKIE=false`. With `SESSION_SECURE_COOKIE=true`, or with an `https://` `APP_URL` and no `SESSION_SECURE_COOKIE` line, logging in over plain `http://` does not work.

Check:

```bash
cd /srv/threads-monitor
curl -sI https://threads.example.com/login | head -1     # HTTP/2 200
grep -c '^SESSION_SECURE_COOKIE=true$' .env               # 1
sudo certbot renew --dry-run                              # ends with succeeded
```

### 2.4 Add the scheduler to cron

Only now, with the permissions from 2.1 in place, add the line printed at the end of the installer to the crontab of the **web user** (so files written by the scheduler have the same owner as the website):

```bash
cd /srv/threads-monitor
sudo crontab -u www-data -e
```

Paste the line exactly as the installer printed it. It looks like this, with your project path and the full path of PHP:

```
* * * * * cd /srv/threads-monitor && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1
```

Use the full PHP path (`command -v php8.3` shows it): when several PHP versions are installed, cron's plain `php` may be another version. The example, including a variant for `/etc/cron.d/`, is in [`docs/deploy/cron.example`](deploy/cron.example).

cron wakes up Laravel's scheduler once a minute, and the scheduler decides what is due: fetching due posts every 5 minutes (`threads:poll-due`) and clearing old debug data every day at 04:10 Taipei time (`threads:prune-raw`).

### 2.5 Check that the scheduler runs

```bash
cd /srv/threads-monitor
sudo -u www-data php artisan schedule:list
```

You should see two rows. The times are shown in **UTC** (the application's time zone), so the daily cleanup at 04:10 Taipei time appears as `10 20 * * *`:

```
  */5 *  * * *  php artisan threads:poll-due ...
  10  20 * * *  php artisan threads:prune-raw ...
```

Run one fetch by hand (it only fetches posts that are already due; if nothing is due it does nothing):

```bash
cd /srv/threads-monitor
sudo -u www-data php artisan threads:poll-due
```

It prints something like `Polled 1 post(s), 0 error(s), due candidates 1 (limit 60).`.

The real end-to-end check: add a public post on the website and wait 5–10 minutes. When the post page shows the first numbers and the last-fetched time updates, the whole chain cron → scheduler → fetch works.

### 2.6 Go-live checklist

- [ ] `APP_ENV=production` and `APP_DEBUG=false` in `.env` (the template defaults)
- [ ] `APP_URL` is the real `https://` address and `.env` has `SESSION_SECURE_COOKIE=true`
- [ ] `https://threads.example.com/.env` answers 403 or 404
- [ ] The admin password is long and not reused anywhere else
- [ ] The permission checks in 2.1 all say OK
- [ ] cron is set up in the `www-data` crontab and `schedule:list` shows both schedules
- [ ] Backups are set up (see [6. Backups](#6-backups))

---

## 3. Optional: use MySQL

SQLite is enough for normal use (hundreds of posts). To use MySQL 8 instead:

**1. Install the extension and create the database and user**

```bash
cd /srv/threads-monitor
sudo apt install -y php8.3-mysql
sudo systemctl restart php8.3-fpm
sudo mysql
```

Inside MySQL (generate your own password):

```sql
CREATE DATABASE threads_monitor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'threads_monitor'@'localhost' IDENTIFIED BY 'replace-with-a-long-password';
GRANT ALL PRIVILEGES ON threads_monitor.* TO 'threads_monitor'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

**2. Edit `.env`** (as yourself)

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=threads_monitor
DB_USERNAME=threads_monitor
DB_PASSWORD=replace-with-a-long-password
```

**3. Create the tables**

```bash
cd /srv/threads-monitor
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan threads:install
```

The installer creates the first admin on the new database. Check: every row of `sudo -u www-data php artisan migrate:status` says `Ran`, and the Database line of `sudo -u www-data php artisan about` shows `mysql`.

Note: switching from SQLite to MySQL **does not move any data**; you start from an empty database and must create the admin again. On MySQL, table changes are not transactional: if an update is interrupted in the middle of creating tables, check `migrate:status` before running it again.

---

## 4. Updating

Back up first (see [6. Backups](#6-backups)), then:

```bash
cd /srv/threads-monitor
sudo -u www-data php artisan down
git pull
composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan threads:install --no-interaction
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan up
```

- `git pull` and `composer install` run as yourself, because the code belongs to your account.
- **Use `threads:install`, not a bare `php artisan migrate`.** The installer runs the database update and, on SQLite, wraps it in one transaction. A bare `migrate` on SQLite has no such protection: if it is interrupted, some tables can be left behind without their record, and every later run fails. The installer also re-checks the extensions and the permissions as `www-data` and exits with a non-zero status if anything failed; if it does, fix what it reports before `up`.
- While the site is `down`, the scheduler does not fetch either.

Check: `echo $?` right after the installer prints 0, you can log in, the post list looks normal, and `sudo -u www-data php artisan migrate:status` shows `Ran` everywhere.

---

## 5. Troubleshooting

### `Could not open input file: artisan`

You are not in the project directory. Run `cd /srv/threads-monitor` first (every command block in this guide starts with it).

### `composer install` says a PHP extension is missing

`composer install` checks the PHP extensions before the installer can, and stops with a message like this (`ext-curl` or `ext-xml` look the same):

```
Your lock file does not contain a compatible set of packages. Please run composer update.

  Problem 1
    - Root composer.json requires PHP extension ext-bcmath * but it is missing from your system. Install or enable PHP's bcmath extension.
```

Despite the first line, do **not** run `composer update`, and do not use `--ignore-platform-req`: the project really needs these extensions, and skipping the check only moves the failure to later. Install the missing extension instead (the package is `php8.3-<name>`, as in [0. Requirements](#0-requirements)), then run `composer install` again:

```bash
cd /srv/threads-monitor
sudo apt install -y php8.3-bcmath php8.3-curl php8.3-xml
sudo systemctl restart php8.3-fpm
php -m | grep -iE '^(bcmath|curl|xml)$'
composer install --no-dev --optimize-autoloader
```

### The installer reports a failed step

Read the lines marked with that step number (`[0/7]` … `[6/7]`): each failure comes with the fix, for example the `apt install` command for a missing extension (restart PHP-FPM afterwards: `sudo systemctl restart php8.3-fpm`) or the permission commands. Fix it and run the installer again; finished steps are skipped.

### The website answers 500

**Check permissions first**; nine times out of ten that is the cause:

```bash
cd /srv/threads-monitor
tail -n 50 storage/logs/laravel.log
```

- `Permission denied`, `failed to open stream` or `attempt to write a readonly database` → redo [2.1 File permissions](#21-file-permissions). The usual cause is running artisan under your own account (see the umask note in 2.1).
- If the log file itself cannot be written, it may be empty; look at the nginx error log instead: `sudo tail -n 50 /var/log/nginx/error.log`.
- `No application encryption key has been specified` → `APP_KEY` in `.env` is empty; run `php artisan threads:install` again (as yourself, it writes `.env`).
- `could not find driver` → the `pdo_sqlite` or `pdo_mysql` extension is missing, see [0. Requirements](#0-requirements).

After changing `.env` or permissions, run `sudo -u www-data php artisan config:clear`.

### Logging in does not work

- Over plain `http://`, the login cookie is HTTPS-only when `.env` has `SESSION_SECURE_COOKIE=true`, or when it has no `SESSION_SECURE_COOKIE` line and `APP_URL` starts with `https://`. The browser then never sends it back. Use the `https://` address; for an `http://` setup, change `APP_URL` to the `http://` address or set `SESSION_SECURE_COOKIE=false` (a `SESSION_SECURE_COOKIE=true` line wins over `APP_URL`, so change or remove it too), then run `sudo -u www-data php artisan config:clear` (see [2.3](#23-https-and-app_url)).
- After too many attempts (10 per 10 minutes for one email, 5 per minute from one IP; IPv6 per /64), the server answers HTTP 429 until the time is up. Wait and try again.
- A disabled account cannot log in; the message is the same as for a wrong password.

### Posts stay `pending` (waiting for the first fetch)

The scheduler is not running. Check that the line is in the web user's crontab (`sudo crontab -u www-data -l`), that the PHP path in it exists, that the permissions from 2.1 are in place, and `schedule:list` as in [2.5](#25-check-that-the-scheduler-runs); then run `threads:poll-due` by hand and read its output.

### Many posts show `blocked`

`blocked` means Threads did not return the post data: it rate-limited the request (HTTP 429) or answered with a login wall or challenge page.

- **Once or twice**: normal; the system retries automatically after 15 minutes.
- **Every post is `blocked`**: Threads has started blocking this server's IP or this fetching method (see [How it works §11](HOW-IT-WORKS.md#11-known-risks)). This is not a configuration mistake and no setting will fix it; track fewer posts and check again after a while.
- Do not make the interval shorter; that only gets you blocked faster.

### Many posts show `parse_failed`

`parse_failed` means the page came back but the post data could not be found, **usually because Threads changed its page format**. The code needs an update ([4. Updating](#4-updating)), or report it as an issue on the project. If it happens to a single post now and then, that post's page may simply be unusual.

### `gone` (post deleted or not public)

The post was deleted, made non-public, or the account became private. Tracking stops; numbers already fetched are kept and the page shows the last numbers fetched before it stopped, with their real time.

### The interface is in the wrong language

The interface follows your browser language on the first visit (Chinese when it starts with `zh`, English otherwise). Use the language button in the page header (it shows the language it switches to: **EN** in the Chinese interface, **中文** in the English one), or clear the `locale` cookie for the site in your browser to go back to automatic detection. `APP_LOCALE` in `.env` only applies when there is neither a cookie nor a browser language.

### Times are off by 8 hours (or another offset)

- The database always stores **UTC** and converts to `THREADS_DISPLAY_TIMEZONE` (default `Asia/Taipei`) only for display. To change the display time zone, set this key, for example `THREADS_DISPLAY_TIMEZONE=Europe/London`, then run `sudo -u www-data php artisan config:clear`.
- The server's system time zone does not affect the data, but its clock must be correct: `timedatectl` should say `System clock synchronized: yes`.
- The daily cleanup (04:10) always uses Taipei time; `schedule:list` shows it in UTC (`10 20 * * *`).

### Forgot the admin password

Run on the server (with the same email):

```bash
cd /srv/threads-monitor
sudo -u www-data php artisan threads:create-admin admin@example.com
```

It asks for a new password and restores the account as an active admin. Without a terminal, give the new password in `THREADS_ADMIN_PASSWORD` and add `--no-interaction`, as in [1.2](#12-installing-on-a-server).

---

## 6. Backups

Only two things need backing up:

1. **The database**
2. **`.env`**

The code can be cloned again at any time; `storage/` holds only logs and caches.

If `APP_KEY` in `.env` is lost, the only effect is that existing logins end: everyone has to log in again. Passwords are not affected (they are stored as hashes that do not depend on `APP_KEY`). The installer creates a new key when `APP_KEY` is empty.

**SQLite** (sqlite3's online backup, safe even while the scheduler writes):

```bash
cd /srv/threads-monitor
sudo apt install -y sqlite3
mkdir -p ~/threads-backup
chmod 700 ~/threads-backup
sqlite3 database/database.sqlite ".backup '$HOME/threads-backup/threads-$(date +%F).sqlite'"
cp .env ~/threads-backup/env-$(date +%F)
chmod 600 ~/threads-backup/*
```

**MySQL**:

```bash
cd /srv/threads-monitor
mysqldump --single-transaction -u threads_monitor -p threads_monitor > ~/threads-backup/threads-$(date +%F).sql
chmod 600 ~/threads-backup/*
```

Check: the backup files are not 0 bytes; a SQLite backup can be read with `sqlite3 <backup file> "select count(*) from tracked_posts;"`.

**Restore** (SQLite; replace the dates with those of your backup):

```bash
cd /srv/threads-monitor
sudo -u www-data php artisan down
cp ~/threads-backup/threads-2026-10-06.sqlite database/database.sqlite
cp ~/threads-backup/env-2026-10-06 .env
sudo chgrp www-data database/database.sqlite .env
sudo chmod 664 database/database.sqlite
sudo chmod 640 .env
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan up
```

The `chgrp` / `chmod` lines give the restored files the same owner and permissions as in [2.1](#21-file-permissions): the database writable by the `www-data` group, `.env` readable by it and by nobody else. For MySQL, restore with `mysql -u threads_monitor -p threads_monitor < backup.sql` between `down` and `up`.

Backups contain every account's password hash, all tracking data and the saved post texts. Keep them where only you can read them, never inside the web directory, and never commit them to git.

---

## 7. Customizing the look

You can change the look without touching the shipped files, so updates with `git pull` keep working:

- **Footer credit line**: set in `.env` with `THREADS_FOOTER_ENABLED`, `THREADS_FOOTER_TEXT` and `THREADS_FOOTER_URL` (see [Appendix A](#appendix-a-env-keys-explained)).
- **Override layer `resources/views/personal/`**: when this directory exists, a template inside it is used instead of the template with the same name under `resources/views/`. For example `resources/views/personal/partials/footer.blade.php` replaces `partials/footer.blade.php`. The project does not ship this directory.
- **Two empty hooks**: the page layout includes `partials.head-extra` at the end of `<head>` and `partials.body-extra` at the end of `<body>`, if they exist. Create `resources/views/personal/partials/head-extra.blade.php` (for example with a `<link rel="stylesheet">` to your own CSS file under `public/`) or `…/body-extra.blade.php`.

After creating or removing `resources/views/personal/`, run:

```bash
cd /srv/threads-monitor
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan view:clear
```

Whether the directory exists is decided when the configuration loads, so a cached configuration would ignore it.

---

## Appendix A: .env keys explained

The installer creates `.env` for you. Most keys can stay at their defaults; usually only `APP_URL` needs changing.

| Key | Default | Meaning |
|---|---|---|
| `APP_NAME` | `Threads Monitor` | Name shown in the browser tab title and in the page header |
| `APP_ENV` | `production` | Use `local` when developing on your own computer |
| `APP_KEY` | (empty) | Key that protects login sessions; generated by the installer. **Never share it.** If it is lost or changed, everyone has to log in again; passwords are not affected |
| `APP_DEBUG` | `false` | Must be `false` in production, otherwise error pages reveal configuration details |
| `APP_URL` | `http://localhost` | Your address, for example `https://threads.example.com` |
| `APP_LOCALE` | `en` | Interface language used only when the visitor has neither a `locale` cookie nor a browser language: `en` or `zh_TW` |
| `APP_FALLBACK_LOCALE` / `APP_FAKER_LOCALE` | `en` / `en_US` | Keep the defaults |
| `LOG_LEVEL` | `warning` | Temporarily set to `debug` when investigating problems |
| `DB_CONNECTION` | `sqlite` | `sqlite` or `mysql` |
| `DB_DATABASE` | (commented out) | Keep it commented out for SQLite, which then uses `database/database.sqlite`; the database name for MySQL |
| `DB_HOST` / `DB_PORT` / `DB_USERNAME` / `DB_PASSWORD` | (commented out) | MySQL only, see [3.](#3-optional-use-mysql) |
| `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` | `database` | All kept in the same database; no Redis needed |
| `SESSION_LIFETIME` | `120` | Minutes of inactivity before you must log in again |
| `SESSION_SECURE_COOKIE` | (not set) | `true` sends the login cookie over HTTPS only; `false` also over plain `http://`. When it is not set, `APP_URL` decides: HTTPS only when `APP_URL` starts with `https://`. The installer adds `SESSION_SECURE_COOKIE=true` when `APP_URL` starts with `https://`. For plain `http://`, leave it out (with an `http://` `APP_URL`) or set `false`, or logging in fails |
| `MAIL_MAILER` | `log` | This project sends no email; keep `log` |
| `THREADS_USER_AGENT` | Googlebot string | User-Agent used for fetching. The default pretends to be Googlebot (see [the risks in the README](../README.md#risks-and-disclaimer)); with another value the numbers disappear, see [How it works §3](HOW-IT-WORKS.md#3-fetching-the-user-agent-decides-what-you-get) |
| `THREADS_TIMEOUT` | `30` | Timeout of a single fetch, in seconds |
| `THREADS_TRACK_HOURS` | `72` | How many hours after publishing a post is tracked. The fetch schedule (`poll_bands` in `config/threads.php`) only covers 0–72 hours: with a larger value, nothing is fetched between hour 72 and the end except the final fetch; with a smaller value, tracking simply ends earlier. Page labels (for example "72-hour final") follow this value |
| `THREADS_POLL_BANDS` | (empty) | Fetch schedule after publishing, as `from-to:minutes` bands separated by commas. Empty means every 60 minutes from 0 to 72 hours. To fetch less often (lower risk of being blocked), e.g. `0-24:60,24-72:180` (hourly for the first 24 hours, then every 3 hours). Gaps between bands are allowed and are not fetched: `0-24:60,48-72:60` fetches hourly up to hour 24 and from hour 48 to 72, nothing in between. An invalid value is ignored with a warning in the log and the default is used; rules in [How it works §8](HOW-IT-WORKS.md#8-polling-frequency) |
| `THREADS_MAX_CONSECUTIVE_FAILURES` | `5` | Consecutive failures before a post is marked failed and tracking stops |
| `THREADS_RETRY_MINUTES` | `15` | Minutes to wait before retrying after a failure |
| `THREADS_POLITENESS_SLEEP_MS` | `3000` | Pause between two fetches in the same run, in milliseconds. Lowering it is not recommended |
| `THREADS_SNAPSHOT_RAW_RETENTION_DAYS` | `7` | Days to keep the debug data of snapshots (the numbers themselves are kept forever) |
| `THREADS_DISPLAY_TIMEZONE` | `Asia/Taipei` | Time zone used for display; the database always stores UTC |
| `THREADS_FOOTER_ENABLED` | `true` | `false` hides the credit line at the bottom of every page |
| `THREADS_FOOTER_TEXT` | the author's credit | Text of the credit line; empty hides it |
| `THREADS_FOOTER_URL` | the author's page | Link of the credit line; only `http://` or `https://` addresses are used, anything else (or empty) shows the text without a link |

After any change to `.env`, run `sudo -u www-data php artisan config:clear`.

---

## Appendix B: Manual install (without the installer)

What `threads:install` does, step by step by hand:

```bash
cd /srv/threads-monitor
cp .env.example .env                       # 1. create the configuration file
chmod 640 .env
php artisan key:generate                   # 2. generate APP_KEY
touch database/database.sqlite             # 3. create the SQLite database file (skip for MySQL; follow section 3 instead)
php artisan migrate --force                # 4. create the tables (not protected by a transaction on SQLite)
php artisan threads:create-admin admin@example.com   # 5. create an admin (asks for the password twice, at least 12 characters)
```

Then finish with [2.1 File permissions](#21-file-permissions), [2.2 nginx](#22-nginx), [2.3 HTTPS and `APP_URL`](#23-https-and-app_url) (add `SESSION_SECURE_COOKIE=true` by hand for `https://`) and [2.4 cron](#24-add-the-scheduler-to-cron).

Check: every row of `sudo -u www-data php artisan migrate:status` says `Ran`, and you can log in with the admin you just created.
