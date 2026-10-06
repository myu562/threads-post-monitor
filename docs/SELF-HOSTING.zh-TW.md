[English](SELF-HOSTING.md) | 繁體中文

# 自架教學

這份教學寫給**第一次接觸這個專案**的人。照順序做，每一步都附上可以直接複製的指令，以及「怎麼確認這步成功」。

文中的值一律是範例，請換成你自己的：

| 範例值 | 意思 |
|---|---|
| `/srv/threads-monitor` | 專案放在主機上的路徑 |
| `threads.example.com` | 你的網域 |
| `www-data` | 執行網站（PHP-FPM）的系統使用者；Debian/Ubuntu 預設是它，CentOS/RHEL 常見的是 `nginx` 或 `apache` |
| `<repo-url>` | 這個專案的 git 網址 |

兩個習慣可以避開大部分問題：

- **每個指令區塊都在專案目錄裡執行。** 下面每個區塊都以 `cd /srv/threads-monitor` 開頭；`php artisan …` 只有在那裡才能跑（否則會出現 `Could not open input file: artisan`）。
- **設好檔案權限（[2.1](#21-檔案權限)）之後，artisan 一律用 web 使用者執行**：`sudo -u www-data php artisan …`。原因在 2.1 說明。

## 目錄

0. [需求](#0-需求)
1. [安裝](#1-安裝)
2. [正式上線](#2-正式上線)
3. [選用：改用 MySQL](#3-選用改用-mysql)
4. [更新版本](#4-更新版本)
5. [疑難排解](#5-疑難排解)
6. [備份](#6-備份)
7. [自訂外觀](#7-自訂外觀)
8. [附錄 A：.env 逐鍵說明](#附錄-aenv-逐鍵說明)
9. [附錄 B：手動安裝（不用 installer）](#附錄-b手動安裝不用-installer)

---

## 0. 需求

| 項目 | 版本／說明 |
|---|---|
| PHP | 8.3 以上（含 CLI 與 FPM）；`composer.json` 要求 `^8.3` |
| PHP 擴充 | `bcmath`、`curl`、`mbstring`、`xml`，以及 `pdo_sqlite`（預設）或 `pdo_mysql`（選用 MySQL 時） |
| Composer | 2.x |
| 資料庫 | SQLite（預設，不用另外安裝伺服器）或 MySQL 8 |
| Web server | nginx（本文範例）或 Apache |
| 排程 | cron |
| 對外連線 | 主機要能連到 `https://www.threads.com`（HTTPS，443 埠） |

不需要 Node.js，也不需要 Redis。

Ubuntu 24.04 的安裝範例（系統內建的就是 PHP 8.3）：

```bash
sudo apt update
sudo apt install -y git unzip nginx cron \
  php8.3-cli php8.3-fpm php8.3-bcmath php8.3-curl php8.3-mbstring php8.3-xml php8.3-sqlite3
```

**較舊的 Ubuntu 或 Debian**（例如 Ubuntu 22.04、Debian 12）內建的 PHP 版本比較舊。先加入提供 PHP 8.3 的套件庫，再執行上面的 `apt install`：

- Ubuntu：`ondrej/php` PPA：`sudo add-apt-repository ppa:ondrej/php`（需要 `software-properties-common`），然後 `sudo apt update`。
- Debian：packages.sury.org 套件庫，照 <https://packages.sury.org/php/README.txt> 的說明加入。

Composer 請照官方說明安裝：<https://getcomposer.org/download/>

**確認成功**：

```bash
php -v                                              # 第一行：PHP 8.3.x（或更新）
php -m | grep -iE '^(bcmath|curl|mbstring|xml|pdo_sqlite)$'   # 五行都要出現
composer --version                                  # Composer version 2.x
```

缺哪個擴充，就安裝對應的 `php8.3-<名稱>` 套件（`pdo_sqlite` 對應 `php8.3-sqlite3`，`pdo_mysql` 對應 `php8.3-mysql`）。不必自己逐一檢查：安裝程式第一步就會檢查擴充，並印出缺少的擴充要執行的 `apt install` 指令。

---

## 1. 安裝

### 1.1 只想先在自己電腦試用

```bash
git clone <repo-url> threads-monitor
cd threads-monitor
composer install --no-dev
php artisan threads:install
php artisan serve
```

打開 <http://127.0.0.1:8000>，用安裝時建立的管理員帳號登入。要讓數字自動更新，另開一個終端機，在同一個目錄執行 `php artisan schedule:work`（前景執行的排程，關掉終端機就停）。

在自己電腦上，全部用你自己的帳號執行即可；web 使用者與本文其餘部分只跟伺服器有關。

### 1.2 在伺服器上安裝

照順序做完以下步驟，**然後照順序繼續第 2 節**。在 [2.1 檔案權限](#21-檔案權限)做完之前不要加 cron：排程以 `www-data` 執行，在 `www-data` 能寫入專案的資料目錄之前都會失敗。

**步驟 1：放好專案目錄**

```bash
sudo mkdir -p /srv/threads-monitor
sudo chown "$USER":www-data /srv/threads-monitor
git clone <repo-url> /srv/threads-monitor
cd /srv/threads-monitor
```

程式碼屬於你自己的帳號，web 使用者只拿到群組。確認成功：`ls /srv/threads-monitor` 看得到 `artisan`、`app`、`public` 等檔案與目錄。

**步驟 2：安裝 PHP 套件**

```bash
cd /srv/threads-monitor
composer install --no-dev --optimize-autoloader
```

`--no-dev` 表示不安裝測試與開發工具，正式環境用不到。

確認成功：最後沒有紅色錯誤，而且出現 `vendor/` 目錄（`ls vendor/autoload.php` 有這個檔）。如果它停下來並顯示 `requires PHP extension ext-… but it is missing from your system`，見[`composer install` 說缺少 PHP 擴充](#composer-install-說缺少-php-擴充)。

**步驟 3：執行安裝程式**

第一次請用**你自己的帳號**執行（它要在專案目錄建立 `.env`，而那裡只有你能寫入）：

```bash
cd /srv/threads-monitor
php artisan threads:install
```

安裝程式的步驟編號是 `[0/7]` 到 `[7/7]`：

0. 檢查 PHP 擴充（依資料庫是 `pdo_sqlite` 或 `pdo_mysql`，加上 `bcmath`、`mbstring`、`curl`、`xml`）。缺少時印出 `apt install` 指令並停止。
1. `.env` 不存在時，從 `.env.example` 複製一份（權限 640）。
2. `APP_KEY` 是空的就自動產生（等同 `php artisan key:generate`）。`APP_URL` 以 `https://` 開頭、而且 `.env` 還沒有 `SESSION_SECURE_COOKIE` 這一行時，加上 `SESSION_SECURE_COOKIE=true`，讓這個設定在 `.env` 裡看得到（見 [2.3](#23-https-與-app_url)）。
3. 使用 SQLite 時建立 `database/database.sqlite`。
4. 建立資料表（`migrate --force`）。在 SQLite 上整個步驟是同一個交易：中途被打斷（Ctrl-C、SSH 斷線）也不會留下做到一半的東西，再執行一次安裝程式就好。
5. 詢問是否建立管理員，輸入 email 與密碼（至少 12 個字元）。
6. 檢查執行它的使用者能不能寫入 `storage`、`bootstrap/cache`、`database` 三個目錄以及 SQLite 資料庫檔本身；不能寫時印出修正指令。
7. 印出步驟總結。全部成功時印出接下來的步驟，包括**填好你的專案路徑與「執行安裝程式的 PHP 完整路徑」的 cron 設定**（例如 `/usr/bin/php8.3`）。有步驟失敗時，會說是哪幾步（例如 `1 step failed (step 5)`），並**以非 0 的結束碼結束**。

它可以**重複執行**：已經做過的步驟會略過，不會覆蓋你的 `.env`、不會重產 `APP_KEY`、不會清掉資料。已經有管理員帳號時會略過建立管理員這一步（要新增管理員或重設密碼，用 `php artisan threads:create-admin <email>`）。照失敗步驟的說明修好之後，再執行一次即可。

如果全新、還沒有任何帳號的 SQLite 資料庫在第 4 步失敗，安裝程式會印出刪除該資料庫檔重來的確切 `rm` 指令；它自己絕不刪任何東西。

系統語言（`LANG`／`LC_ALL`）以 `zh` 開頭時，安裝程式用中文顯示訊息，其他情況用英文；也可以用 `--lang=zh` 或 `--lang=en` 指定。

不想互動（例如寫進部署腳本）時：

```bash
cd /srv/threads-monitor
THREADS_ADMIN_PASSWORD='a-password-of-at-least-12-chars' \
  php artisan threads:install --admin-email=admin@example.com --no-interaction
```

密碼只從環境變數 `THREADS_ADMIN_PASSWORD` 讀取，畫面上不會印出。不互動執行又沒給 `--admin-email` 時，會略過建立管理員。這時結束代碼仍然是 0（安裝本身是成功的），但總結最後會出現 `注意：目前還沒有任何管理員帳號，沒有人能登入。` 的警告與補建指令。如果設了 `THREADS_ADMIN_PASSWORD` 卻沒給 `--admin-email`，第 5 步算失敗，結束代碼不是 0。

`threads:create-admin` 也可以不互動執行：沒有終端機（或加了 `--no-interaction`）時，它從 `THREADS_ADMIN_PASSWORD` 讀密碼；這個變數是空的就報錯停止：

```bash
cd /srv/threads-monitor
THREADS_ADMIN_PASSWORD='a-password-of-at-least-12-chars' \
  php artisan threads:create-admin admin@example.com --no-interaction
```

確認成功：

```bash
cd /srv/threads-monitor
echo $?                          # 緊接在安裝程式之後執行：0 表示每一步都成功
php artisan migrate:status       # 每一列都是 Ran
```

應用程式已經裝好。接著照順序做 [2. 正式上線](#2-正式上線)。

---

## 2. 正式上線

### 2.1 檔案權限

**為什麼**：網站與排程都以 `www-data` 執行，它必須能寫入：

- `storage/`：log、快取檔、編譯後的畫面樣板。
- `bootstrap/cache/`：設定與路由的快取。
- `database/`：SQLite 資料庫檔，**以及 SQLite 寫入時在旁邊建立的暫存檔**，所以整個目錄都要可寫，不是只有資料庫檔。
- `.env` 只需要讓 `www-data` **讀得到**。

寫不進去時網站會回 **500**、排程也會失敗，這是自架最常見的問題。其他檔案只需要可讀，維持屬於你的帳號，`www-data` 就改不了程式碼本身。

```bash
cd /srv/threads-monitor
sudo chgrp -R www-data storage bootstrap/cache database
sudo chmod -R ug+rwX storage bootstrap/cache database
sudo find storage bootstrap/cache database -type d -exec chmod g+s {} +
sudo chgrp www-data .env
sudo chmod 640 .env
```

- 目錄加上 `g+s`（setgid）後，裡面新建的檔案會自動屬於 `www-data` 群組。
- `.env` 裡有 `APP_KEY` 等機密；640 表示你可以讀寫、`www-data` 可以讀、其他人都不行。

**從現在起，artisan 一律用 web 使用者執行**：`sudo -u www-data php artisan …`。原因是 **umask**：大多數系統是 `022`，你建立的檔案權限是 `644`（群組只能讀、不能寫）。setgid 讓檔案屬於 `www-data` 群組，但不會給群組寫入權限。如果你用自己的帳號跑 artisan，而它建立了 log 檔、快取檔或編譯後的樣板，`www-data` 之後只能讀不能寫，網站就會回 500。由 `www-data` 自己建立的檔案不會有這個問題。本文的例外情況（編輯 `.env`、`git pull`、`composer install`、在 2.3 重跑安裝程式加上 `SESSION_SECURE_COOKIE`）都有標明。如果不小心用自己的帳號跑了其他 artisan 指令，把上面五行再執行一次就好，重複執行沒有副作用。

確認成功：

```bash
cd /srv/threads-monitor
sudo -u www-data test -w storage/logs && echo storage OK
sudo -u www-data test -w bootstrap/cache && echo cache OK
sudo -u www-data test -w database && echo database OK
sudo -u www-data test -w database/database.sqlite && echo sqlite OK
sudo -u www-data test -r .env && echo env OK
```

五行 OK 都要出現（使用 MySQL 時略過 `sqlite` 那一行）。再從 web 使用者的角度檢查一次：

```bash
cd /srv/threads-monitor
sudo -u www-data php artisan threads:install --no-interaction
```

每一步都要顯示正常或已完成，而且第 `[6/7]` 步要說這些目錄 `www-data` 可以寫入。

### 2.2 nginx

```bash
cd /srv/threads-monitor
sudo cp docs/deploy/nginx.conf.example /etc/nginx/sites-available/threads-monitor.conf
sudo nano /etc/nginx/sites-available/threads-monitor.conf   # 修改 server_name、root 與 PHP-FPM socket
sudo ln -s /etc/nginx/sites-available/threads-monitor.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

重點：

- `root` 一定要指到 **`public/` 子目錄**（`/srv/threads-monitor/public`），不能指到專案根目錄，否則 `.env` 可能被下載。
- PHP-FPM 的 socket 實際路徑用 `ls /run/php/` 查。
- 範例檔：[`docs/deploy/nginx.conf.example`](deploy/nginx.conf.example)。
- 安全標頭（`X-Frame-Options`、`X-Content-Type-Options`、`Referrer-Policy` 與 `Content-Security-Policy: frame-ancestors 'self'`）由程式本身在每個回應送出，nginx 或 Apache 都不必為此另外設定。範例檔裡的 `add_header` 幾行不是必要的。

確認成功：

```bash
cd /srv/threads-monitor
curl -sI http://threads.example.com/login | head -1      # HTTP/1.1 200 OK
curl -sI http://threads.example.com/.env | head -1       # 403 或 404，絕不能是 200
```

用 Apache 的話：DocumentRoot 指到 `public/`，啟用 `mod_rewrite`，並允許 `.htaccess`（`AllowOverride All`）；專案已附 `public/.htaccess`。

### 2.3 HTTPS 與 `APP_URL`

網域的 DNS 指到這台主機之後：

```bash
cd /srv/threads-monitor
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d threads.example.com
```

certbot 會自動加上 443 的設定與自動續期。

接著把 `.env` 的 `APP_URL` 改成你的 `https://` 網址。`.env` 屬於你，用你自己的帳號編輯；然後**用你自己的帳號**再執行一次安裝程式（它要寫入 `.env`）：

```bash
cd /srv/threads-monitor
nano .env                                   # 設定 APP_URL=https://threads.example.com
php artisan threads:install --no-interaction
sudo -u www-data php artisan config:clear
```

因為 `APP_URL` 現在以 `https://` 開頭，登入用的 cookie 從此只經由 HTTPS 傳送：`.env` 沒有 `SESSION_SECURE_COOKIE` 這一行時，程式依 `APP_URL` 自動判斷。安裝程式也會在 `.env` 加上 `SESSION_SECURE_COOKIE=true`（第 `[2/7]` 步會顯示），讓這個設定看得到。已經有 `SESSION_SECURE_COOKIE` 這一行時，它不會更動。也可以不重跑安裝程式，自己手動加上這一行。

不用 HTTPS（不建議放在網路上）時，`APP_URL` 設成 `http://` 網址，並且不要設定 `SESSION_SECURE_COOKIE`，或設成 `SESSION_SECURE_COOKIE=false`。設了 `SESSION_SECURE_COOKIE=true`，或 `APP_URL` 是 `https://` 又沒有 `SESSION_SECURE_COOKIE` 這一行時，用 `http://` 是登入不了的。

確認成功：

```bash
cd /srv/threads-monitor
curl -sI https://threads.example.com/login | head -1     # HTTP/2 200
grep -c '^SESSION_SECURE_COOKIE=true$' .env               # 1
sudo certbot renew --dry-run                              # 最後顯示 succeeded
```

### 2.4 把排程加進 cron

到這裡，2.1 的權限已經設好，才把安裝程式最後印出的那一行加進 **web 使用者**的 crontab（這樣排程寫出的檔案和網站是同一個擁有者）：

```bash
cd /srv/threads-monitor
sudo crontab -u www-data -e
```

照安裝程式印出的內容原樣貼上。它長得像這樣，路徑換成你的專案路徑與 PHP 完整路徑：

```
* * * * * cd /srv/threads-monitor && /usr/bin/php8.3 artisan schedule:run >> /dev/null 2>&1
```

PHP 要用完整路徑（`command -v php8.3` 可以查）：主機裝了好幾個 PHP 版本時，cron 裡單純的 `php` 可能指到別的版本。範例（含 `/etc/cron.d/` 的寫法）在 [`docs/deploy/cron.example`](deploy/cron.example)。

cron 每分鐘叫醒一次 Laravel 的排程器，由排程器決定該做什麼：每 5 分鐘抓取到期的貼文（`threads:poll-due`），每天台北時間 04:10 清理舊的除錯資料（`threads:prune-raw`）。

### 2.5 確認排程有在跑

```bash
cd /srv/threads-monitor
sudo -u www-data php artisan schedule:list
```

應該看到兩列。時間以 **UTC**（應用程式的時區）顯示，所以台北時間每天 04:10 的清理會顯示成 `10 20 * * *`：

```
  */5 *  * * *  php artisan threads:poll-due ...
  10  20 * * *  php artisan threads:prune-raw ...
```

手動跑一次抓取（只抓已經到期的貼文；沒有到期的就什麼都不做）：

```bash
cd /srv/threads-monitor
sudo -u www-data php artisan threads:poll-due
```

會印出類似 `Polled 1 post(s), 0 error(s), due candidates 1 (limit 60).` 的結果。

真正的端到端確認：在網頁上加一篇公開貼文，等 5–10 分鐘。貼文頁出現第一筆數字、「最後抓取」時間有更新，就代表 cron → 排程 → 抓取整條路都通了。

### 2.6 上線檢查清單

- [ ] `.env` 的 `APP_ENV=production`、`APP_DEBUG=false`（範本預設值）
- [ ] `APP_URL` 是正式的 `https://` 網址，且 `.env` 有 `SESSION_SECURE_COOKIE=true`
- [ ] `https://threads.example.com/.env` 回 403 或 404
- [ ] 管理員密碼夠長，且沒有在別處重複使用
- [ ] 2.1 的權限檢查全部 OK
- [ ] cron 設在 `www-data` 的 crontab，`schedule:list` 看得到兩個排程
- [ ] 已設定備份（見 [6. 備份](#6-備份)）

---

## 3. 選用：改用 MySQL

一般用量（幾百篇貼文）用 SQLite 就夠了。要改用 MySQL 8：

**1. 安裝擴充並建立資料庫與帳號**

```bash
cd /srv/threads-monitor
sudo apt install -y php8.3-mysql
sudo systemctl restart php8.3-fpm
sudo mysql
```

在 MySQL 裡（密碼請自己產生）：

```sql
CREATE DATABASE threads_monitor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'threads_monitor'@'localhost' IDENTIFIED BY 'replace-with-a-long-password';
GRANT ALL PRIVILEGES ON threads_monitor.* TO 'threads_monitor'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

**2. 修改 `.env`**（用你自己的帳號）

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=threads_monitor
DB_USERNAME=threads_monitor
DB_PASSWORD=replace-with-a-long-password
```

**3. 建立資料表**

```bash
cd /srv/threads-monitor
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan threads:install
```

安裝程式會在新資料庫建立第一個管理員。確認成功：`sudo -u www-data php artisan migrate:status` 每一列都是 `Ran`，`sudo -u www-data php artisan about` 的 Database 那一行顯示 `mysql`。

注意：從 SQLite 改成 MySQL **不會搬移資料**，等於從空的資料庫重新開始，管理員也要重建。MySQL 的建表動作沒有交易保護：更新在建表途中被打斷時，先看 `migrate:status` 再重跑。

---

## 4. 更新版本

先備份（見 [6. 備份](#6-備份)），然後：

```bash
cd /srv/threads-monitor
sudo -u www-data php artisan down
git pull
composer install --no-dev --optimize-autoloader
sudo -u www-data php artisan threads:install --no-interaction
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan up
```

- `git pull` 與 `composer install` 用你自己的帳號執行，因為程式碼屬於你。
- **用 `threads:install`，不要單獨執行 `php artisan migrate`。** 安裝程式會做資料庫更新，而且在 SQLite 上包成同一個交易。單獨的 `migrate` 在 SQLite 上沒有這層保護：中途被打斷時，可能留下沒有紀錄的資料表，之後每次執行都會失敗。安裝程式也會以 `www-data` 重新檢查擴充與權限，有任何一步失敗就以非 0 的結束碼結束；遇到這種情況，先照它的說明修好再 `up`。
- 網站處於 `down` 期間，排程也不會抓取。

確認成功：緊接在安裝程式之後執行 `echo $?` 顯示 0、可以登入、貼文清單正常，`sudo -u www-data php artisan migrate:status` 全部是 `Ran`。

---

## 5. 疑難排解

### `Could not open input file: artisan`

你不在專案目錄裡。先執行 `cd /srv/threads-monitor`（本文每個指令區塊都以它開頭）。

### `composer install` 說缺少 PHP 擴充

`composer install` 會比安裝程式更早檢查 PHP 擴充，缺少時停下來並顯示類似這樣的訊息（缺 `ext-curl` 或 `ext-xml` 時也長這樣）：

```
Your lock file does not contain a compatible set of packages. Please run composer update.

  Problem 1
    - Root composer.json requires PHP extension ext-bcmath * but it is missing from your system. Install or enable PHP's bcmath extension.
```

不要照第一行的建議執行 `composer update`，也不要用 `--ignore-platform-req`：這個專案真的需要這些擴充，跳過檢查只會讓錯誤晚一點出現。請改成安裝缺少的擴充（套件名稱是 `php8.3-<名稱>`，同 [0. 需求](#0-需求)），再執行一次 `composer install`：

```bash
cd /srv/threads-monitor
sudo apt install -y php8.3-bcmath php8.3-curl php8.3-xml
sudo systemctl restart php8.3-fpm
php -m | grep -iE '^(bcmath|curl|xml)$'
composer install --no-dev --optimize-autoloader
```

### 安裝程式回報有步驟失敗

看標著該步驟編號（`[0/7]` … `[6/7]`）的那幾行：每個失敗都附有修正方式，例如缺擴充時要執行的 `apt install` 指令（裝完要重新啟動 PHP-FPM：`sudo systemctl restart php8.3-fpm`），或權限修正指令。修好後再執行一次安裝程式，已完成的步驟會略過。

### 網站回 500

**先查權限**，十次有九次是它：

```bash
cd /srv/threads-monitor
tail -n 50 storage/logs/laravel.log
```

- 看到 `Permission denied`、`failed to open stream` 或 `attempt to write a readonly database` → 重做 [2.1 檔案權限](#21-檔案權限)。常見原因是用自己的帳號跑了 artisan（見 2.1 的 umask 說明）。
- 如果連 log 都寫不進去，log 檔可能是空的，改看 nginx 的錯誤 log：`sudo tail -n 50 /var/log/nginx/error.log`。
- `No application encryption key has been specified` → `.env` 的 `APP_KEY` 是空的，再執行一次 `php artisan threads:install`（用你自己的帳號，它要寫入 `.env`）。
- `could not find driver` → 少了 `pdo_sqlite` 或 `pdo_mysql` 擴充，見 [0. 需求](#0-需求)。

改過 `.env` 或權限之後，執行 `sudo -u www-data php artisan config:clear`。

### 無法登入

- 用 `http://` 開的時候，只要 `.env` 有 `SESSION_SECURE_COOKIE=true`，或沒有 `SESSION_SECURE_COOKIE` 這一行而 `APP_URL` 以 `https://` 開頭，登入 cookie 就只走 HTTPS，瀏覽器不會把它送回來。請改用 `https://` 網址；如果你的環境本來就只用 `http://`，就把 `APP_URL` 改成 `http://` 網址，或設 `SESSION_SECURE_COOKIE=false`（`SESSION_SECURE_COOKIE=true` 這一行優先於 `APP_URL`，所以有這一行時要一併改掉或刪掉），然後執行 `sudo -u www-data php artisan config:clear`（見 [2.3](#23-https-與-app_url)）。
- 嘗試次數太多（同一個 email 每 10 分鐘 10 次、同一個 IP 每分鐘 5 次；IPv6 以 /64 計）時，伺服器會回 HTTP 429，時間到了才能再試。
- 被停用的帳號不能登入，顯示的訊息和密碼錯誤相同。

### 貼文一直停在 `pending`（介面顯示「等待首次抓取」）

排程沒有在跑。確認那一行在 web 使用者的 crontab 裡（`sudo crontab -u www-data -l`）、裡面的 PHP 路徑存在、2.1 的權限已設好，並照 [2.5](#25-確認排程有在跑) 檢查 `schedule:list`；然後手動跑一次 `threads:poll-due`，看它印出什麼。

### 很多貼文顯示 `blocked`

`blocked` 表示 Threads 沒有回傳貼文資料：請求被限流（HTTP 429），或回了登入牆、驗證頁。

- **偶爾一兩次**：正常，系統 15 分鐘後會自動重試。
- **全部貼文都是 `blocked`**：代表 Threads 開始擋這台主機的 IP 或這個抓取方式（見[運作原理 §11](HOW-IT-WORKS.zh-TW.md#11-已知風險)）。這不是你設定錯了，換設定也救不回來；可以先減少追蹤量、等一段時間再看。
- 不要把抓取間隔調得更短，那只會更快被擋。

### 很多貼文顯示 `parse_failed`

`parse_failed` 表示頁面有回來，但找不到貼文資料，**通常是 Threads 改了頁面格式**。需要更新程式（[4. 更新版本](#4-更新版本)），或到專案回報問題。如果只是個別貼文偶爾出現，可能是那篇貼文的頁面比較特殊。

### `gone`（貼文已刪除或不公開）

貼文被刪除、改成不公開，或帳號轉為私人。追蹤會停止；已經抓到的數字會保留，頁面顯示停止前最後抓到的數字（標示「刪除前最後數字」）與它真正的時間。

### 介面語言不對

第一次造訪時，介面依瀏覽器語言決定（`zh` 開頭用中文，其他用英文）。用頁首的語言切換鈕切換（按鈕上寫的是要切換過去的語言：中文介面顯示 **EN**，英文介面顯示 **中文**），或在瀏覽器清除這個網站的 `locale` cookie，就會回到自動判斷。`.env` 的 `APP_LOCALE` 只在既沒有 cookie、也沒有瀏覽器語言時才用到。

### 時間差 8 小時（或其他時差）

- 資料庫一律存 **UTC**，只有顯示時才轉成 `THREADS_DISPLAY_TIMEZONE`（預設 `Asia/Taipei`）。要換顯示時區就設定這個鍵，例如 `THREADS_DISPLAY_TIMEZONE=Europe/London`，然後執行 `sudo -u www-data php artisan config:clear`。
- 主機的系統時區不影響資料，但時鐘要準：`timedatectl` 要顯示 `System clock synchronized: yes`。
- 每天的清理（04:10）固定用台北時間；`schedule:list` 以 UTC 顯示（`10 20 * * *`）。

### 忘記管理員密碼

在主機上執行（用同一個 email）：

```bash
cd /srv/threads-monitor
sudo -u www-data php artisan threads:create-admin admin@example.com
```

它會請你輸入新密碼，並把這個帳號恢復成啟用中的管理員。沒有終端機時，把新密碼放在 `THREADS_ADMIN_PASSWORD` 並加上 `--no-interaction`，同 [1.2](#12-在伺服器上安裝)。

---

## 6. 備份

需要備份的只有兩樣：

1. **資料庫**
2. **`.env`**

程式碼隨時可以重新 clone；`storage/` 只有 log 與快取。

`.env` 的 `APP_KEY` 遺失時，唯一的影響是既有的登入全部失效，每個人都要重新登入。密碼不受影響（密碼存的是雜湊值，與 `APP_KEY` 無關）。`APP_KEY` 是空的時，安裝程式會產生新的。

**SQLite**（sqlite3 的線上備份，排程正在寫入時也安全）：

```bash
cd /srv/threads-monitor
sudo apt install -y sqlite3
mkdir -p ~/threads-backup
chmod 700 ~/threads-backup
sqlite3 database/database.sqlite ".backup '$HOME/threads-backup/threads-$(date +%F).sqlite'"
cp .env ~/threads-backup/env-$(date +%F)
chmod 600 ~/threads-backup/*
```

**MySQL**：

```bash
cd /srv/threads-monitor
mysqldump --single-transaction -u threads_monitor -p threads_monitor > ~/threads-backup/threads-$(date +%F).sql
chmod 600 ~/threads-backup/*
```

確認成功：備份檔不是 0 位元組；SQLite 備份可以用 `sqlite3 <備份檔> "select count(*) from tracked_posts;"` 讀讀看。

**還原**（SQLite；日期換成你的備份日期）：

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

`chgrp`／`chmod` 這幾行讓還原的檔案回到和 [2.1](#21-檔案權限) 一樣的擁有者與權限：資料庫讓 `www-data` 群組可寫，`.env` 只讓它可讀、其他人不能讀。MySQL 則在 `down` 與 `up` 之間用 `mysql -u threads_monitor -p threads_monitor < backup.sql` 還原。

備份裡有所有帳號的密碼雜湊、全部追蹤資料與存下來的貼文內文。請放在只有你讀得到的地方，不要放在網站目錄底下，也不要 commit 進 git。

---

## 7. 自訂外觀

不必修改專案附的檔案就能改外觀，之後 `git pull` 更新也不受影響：

- **頁尾署名**：在 `.env` 用 `THREADS_FOOTER_ENABLED`、`THREADS_FOOTER_TEXT`、`THREADS_FOOTER_URL` 設定（見[附錄 A](#附錄-aenv-逐鍵說明)）。
- **覆蓋層 `resources/views/personal/`**：這個目錄存在時，裡面的樣板會取代 `resources/views/` 底下同名的樣板。例如 `resources/views/personal/partials/footer.blade.php` 會取代 `partials/footer.blade.php`。專案本身不附這個目錄。
- **兩個空的掛鉤**：頁面版型在 `<head>` 結尾載入 `partials.head-extra`、在 `<body>` 結尾載入 `partials.body-extra`（存在時才載入）。建立 `resources/views/personal/partials/head-extra.blade.php`（例如放一行 `<link rel="stylesheet">` 指向你放在 `public/` 底下的 CSS 檔）或 `…/body-extra.blade.php` 即可。

建立或移除 `resources/views/personal/` 之後執行：

```bash
cd /srv/threads-monitor
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan view:clear
```

這個目錄存不存在，是在載入設定時判斷的，所以設定被快取時會看不到它。

---

## 附錄 A：.env 逐鍵說明

`.env` 由安裝程式幫你建立。大部分鍵維持預設即可，通常只需要改 `APP_URL`。

| 鍵 | 預設值 | 意思 |
|---|---|---|
| `APP_NAME` | `Threads Monitor` | 顯示在瀏覽器分頁標題與頁首的名稱 |
| `APP_ENV` | `production` | 在自己電腦開發時改成 `local` |
| `APP_KEY` | （空） | 保護登入 session 的金鑰，由安裝程式產生。**不要外流。** 遺失或更換時，每個人都要重新登入；密碼不受影響 |
| `APP_DEBUG` | `false` | 正式環境一定要 `false`，否則錯誤頁會洩漏設定細節 |
| `APP_URL` | `http://localhost` | 你的網址，例如 `https://threads.example.com` |
| `APP_LOCALE` | `en` | 只有訪客既沒有 `locale` cookie、也沒有瀏覽器語言時才使用的介面語言：`en` 或 `zh_TW` |
| `APP_FALLBACK_LOCALE`／`APP_FAKER_LOCALE` | `en`／`en_US` | 維持預設 |
| `LOG_LEVEL` | `warning` | 查問題時可暫時改成 `debug` |
| `DB_CONNECTION` | `sqlite` | `sqlite` 或 `mysql` |
| `DB_DATABASE` | （註解掉） | SQLite 維持註解即可，會使用 `database/database.sqlite`；MySQL 時填資料庫名稱 |
| `DB_HOST`／`DB_PORT`／`DB_USERNAME`／`DB_PASSWORD` | （註解掉） | 只有 MySQL 需要，見 [3.](#3-選用改用-mysql) |
| `SESSION_DRIVER`／`CACHE_STORE`／`QUEUE_CONNECTION` | `database` | 都放在同一個資料庫，不需要 Redis |
| `SESSION_LIFETIME` | `120` | 閒置幾分鐘後要重新登入 |
| `SESSION_SECURE_COOKIE` | （未設定） | `true` 表示登入 cookie 只經由 HTTPS 傳送；`false` 表示 `http://` 也會送。沒設定時依 `APP_URL` 判斷：`APP_URL` 以 `https://` 開頭就只走 HTTPS。`APP_URL` 以 `https://` 開頭時，安裝程式會加上 `SESSION_SECURE_COOKIE=true`。只用 `http://` 時不要設定（`APP_URL` 也要是 `http://`）或設成 `false`，否則登入不了 |
| `MAIL_MAILER` | `log` | 本專案不寄信，維持 `log` |
| `THREADS_USER_AGENT` | Googlebot 字串 | 抓取時用的 User-Agent。預設值冒用 Googlebot（見 [README 的風險說明](../README.zh-TW.md#風險與免責聲明)）；改成其他值就拿不到數字，原因見[運作原理 §3](HOW-IT-WORKS.zh-TW.md#3-抓取方式user-agent-決定拿到什麼) |
| `THREADS_TIMEOUT` | `30` | 單次抓取的逾時秒數 |
| `THREADS_TRACK_HOURS` | `72` | 發文後追蹤幾小時。抓取排程（`config/threads.php` 的 `poll_bands`）只涵蓋 0–72 小時：設得比 72 大時，第 72 小時到結束之間除了最後一次抓取不會再抓；設得比 72 小的時候，追蹤就會提早結束。頁面上的標示（例如「72 小時最終」）會跟著這個值變 |
| `THREADS_POLL_BANDS` | （空白） | 發文後的抓取排程，格式是用逗號分隔的「`起-迄:分鐘`」區段。空白表示 0–72 小時每 60 分鐘一次。想少抓幾次（降低被擋的機率），例如 `0-24:60,24-72:180`（前 24 小時每小時、之後每 3 小時）。段與段之間可以留空檔，空檔不抓：`0-24:60,48-72:60` 是到第 24 小時為止每小時、第 48 到 72 小時每小時，中間不抓。設定不合法時會在 log 寫一筆警告並改用預設值；規則見[運作原理 §8](HOW-IT-WORKS.zh-TW.md#8-抓取頻率) |
| `THREADS_MAX_CONSECUTIVE_FAILURES` | `5` | 連續失敗幾次就標成失敗並停止追蹤 |
| `THREADS_RETRY_MINUTES` | `15` | 失敗後幾分鐘重試 |
| `THREADS_POLITENESS_SLEEP_MS` | `3000` | 同一輪內兩次抓取之間的間隔（毫秒），不建議調低 |
| `THREADS_SNAPSHOT_RAW_RETENTION_DAYS` | `7` | 快照的除錯資料保留天數（數字本身永久保留） |
| `THREADS_DISPLAY_TIMEZONE` | `Asia/Taipei` | 顯示用的時區；資料庫一律存 UTC |
| `THREADS_FOOTER_ENABLED` | `true` | 設成 `false` 會隱藏每一頁最下方的署名 |
| `THREADS_FOOTER_TEXT` | 作者署名 | 署名文字；空白就不顯示 |
| `THREADS_FOOTER_URL` | 作者的頁面 | 署名的連結；只接受 `http://` 或 `https://` 網址，其他值（或空白）只顯示文字、不加連結 |

改完 `.env` 都要執行 `sudo -u www-data php artisan config:clear`。

---

## 附錄 B：手動安裝（不用 installer）

`threads:install` 做的事，手動一步一步來：

```bash
cd /srv/threads-monitor
cp .env.example .env                       # 1. 建立設定檔
chmod 640 .env
php artisan key:generate                   # 2. 產生 APP_KEY
touch database/database.sqlite             # 3. 建立 SQLite 資料庫檔（MySQL 略過，改照第 3 節）
php artisan migrate --force                # 4. 建立資料表（在 SQLite 上沒有交易保護）
php artisan threads:create-admin admin@example.com   # 5. 建立管理員（會問兩次密碼，至少 12 個字元）
```

然後接著做 [2.1 檔案權限](#21-檔案權限)、[2.2 nginx](#22-nginx)、[2.3 HTTPS 與 `APP_URL`](#23-https-與-app_url)（用 `https://` 時自己加上 `SESSION_SECURE_COOKIE=true`）與 [2.4 cron](#24-把排程加進-cron)。

確認成功：`sudo -u www-data php artisan migrate:status` 每一列都是 `Ran`，並且能用剛建立的管理員登入。
