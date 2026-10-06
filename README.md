English | [繁體中文](README.zh-TW.md)

# Threads Monitor

> **Summary**: Threads Monitor records the public numbers of any public Threads post (views, likes,
> replies, reposts, quotes, shares), its publish time and its text, fetching on a schedule during the
> first 72 hours after publishing and keeping the 72-hour final numbers. It reads the public post page
> that anyone can see without logging in: no login, no cookies, no API tokens. It comes in two editions:
> a download-and-run **folder edition** (`standalone/`, results written as CSV) and a self-hosted
> **web edition** (PHP 8.3 / Laravel 12, SQLite by default, multiple accounts). By default it fetches
> with a Googlebot User-Agent, which may violate Meta's Terms of Service; read [Risks and disclaimer](#risks-and-disclaimer)
> before using it. Not affiliated with Meta. MIT licensed.

## Which edition?

| | Folder edition | Web edition |
|---|---|---|
| Where | [`standalone/`](standalone/README.md) | This page (project root) |
| Setup | Download and run; no server, no database | Self-hosted server (PHP, web server, cron) |
| Who | One person | Multiple accounts, each with its own list; the same post shares one set of fetched data |
| Adding posts | Paste URLs into `urls.txt` | Paste a URL on the website |
| Where the numbers go | CSV files in the same folder | A database, viewed on the website, with built-in charts |
| Scheduling | Automatic schedule or manual update; **the computer must stay on** | The server runs it automatically, 24/7 |

For the folder edition, see [standalone/README.md](standalone/README.md). Everything below is about the web edition.

---

## What this is

Give it the URL of a Threads post and the system will:

- Record the post's **publish time** (shown by date and hour) and its **post text**
- Record views, likes, replies, reposts, quotes and shares on a schedule **during the first 72 hours after publishing**
- Keep the **72-hour final numbers** at exactly 72 hours, list every snapshot with its increase since the previous one, and draw a chart of views over time

Anyone's public post can be tracked; it does not have to be your own, and you do not need any Threads/Meta account or API token.

## Screenshots

> Screenshots to come: post list, post detail page (with the views chart), account management page.

## Features

- Paste a URL to start tracking; supports `threads.com`, `threads.net`, `/t/` short URLs and the `/share/…` share links produced by the app's "Share" button (resolved to the original post automatically)
- The publish time comes from the page; before the page has been fetched, it is estimated from the post code (labelled as estimated)
- The fetch frequency adapts to the time since publishing, with a last fetch at exactly 72 hours
- Snapshot history: every fetch is listed with the change since the previous fetch; when two fetches are at least half an hour apart, the change is also converted to a per-hour rate
- The post text is saved on the first successful fetch and shown in the list and on the post page
- Your own **groups**: the list is shown in collapsible sections, one per group, with posts without a group last under Ungrouped. Sections start closed; a filtered list opens the sections with matches, and otherwise your browser remembers which sections you opened. Groups are added, renamed, deleted and reordered from the list page
- Adding a post only needs its URL; the group, **tags** and **note** are edited afterwards, directly on the post's card in the list or at the top of the post page. Other accounts never see your groups, tags and notes
- Until a post has been fetched successfully, its account name comes from the URL you added and is labelled **unverified**; the "Open post" link always uses the standard `https://www.threads.com/t/<code>` address
- Search and filters by keyword, status, tag and group; the keyword also matches the names of your groups and ignores upper and lower case, also for letters outside English. Filtered lists have their own URL, so they can be bookmarked
- Numbers that cannot be obtained are shown as **Not available**; likes and views the author has hidden are shown as **Hidden by author**; **never replaced with 0**
- Failures are visible: being blocked, a changed page format and deleted posts are all shown with the reason and the number of consecutive failures; a deleted or failed post still shows the last numbers fetched before it stopped
- Multiple accounts: no self-registration, accounts are created by an admin; every person has a separate list
- The interface is available in Traditional Chinese and English and switches automatically by browser language; a button in the page header switches it by hand. The button shows the language it switches to: **EN** in the Chinese interface, **中文** in the English one
- Light and dark theme: follows the system setting until you pick one with the toggle in the page header
- SQLite by default, no separate database server needed; MySQL 8 is optional

## How it works (summary)

A plain HTTP GET to the post's public URL with the Googlebot User-Agent returns HTML that embeds the post's JSON data (likes, replies, reposts, quotes, shares, publish time, post text); the view count is in another configuration block on the same page. The schedule starts at the publish time: by default once an hour for 72 hours, and the 72-hour final numbers are written at exactly 72 hours. To fetch less often (lower risk of being blocked), set `THREADS_POLL_BANDS` (folder edition: `poll_bands` in `settings.ini`), for example back to every 3 hours after the first 24 hours.

The details (the source of every field, what the view count really is, how share links are resolved, how a time is decoded from the post code, the state machine, known risks) are in **[docs/HOW-IT-WORKS.md](docs/HOW-IT-WORKS.md)**.

## Quick start

Requires PHP 8.3 or newer (with the `bcmath`, `curl`, `mbstring`, `xml` and `pdo_sqlite` extensions) and Composer. To just try it on your own computer:

```bash
git clone <repo-url> threads-monitor
cd threads-monitor
composer install --no-dev
php artisan threads:install
php artisan serve
```

`threads:install` first checks the PHP extensions, then creates the configuration file and the database, asks for an admin account, and at the end prints a summary (and exits with a non-zero code if any step failed). Then open <http://127.0.0.1:8000> and log in with that admin account.

If `composer install` stops before that with `requires PHP extension ext-bcmath … but it is missing from your system` (or `ext-curl`, `ext-xml`), install the missing extension as in [Requirements](docs/SELF-HOSTING.md#0-requirements) and run it again; do not run `composer update` and do not use `--ignore-platform-req` (details: [Troubleshooting](docs/SELF-HOSTING.md#composer-install-says-a-php-extension-is-missing)).

On a server, the installer also prints one cron line. It goes into the **web user's** crontab (`sudo crontab -u www-data -e`), and only **after** the file permissions are set up; otherwise the scheduler fails. Follow the server guide in order.

## Full self-hosting guide

Installing on a server (file permissions, nginx, HTTPS, cron, in that order), switching to MySQL, updating, troubleshooting and backups are covered in **[docs/SELF-HOSTING.md](docs/SELF-HOSTING.md)**. Example configuration files are in [`docs/deploy/`](docs/deploy/nginx.conf.example).

## Accounts and data scope

- **No self-registration**. The first admin is created during installation (or with `php artisan threads:create-admin <email>`); an admin creates, edits, resets passwords for, disables or deletes other accounts at `/users`.
- Everyone can change their own password at `/account/password`. Changing your password signs out your other devices; an admin resetting your password or disabling your account signs you out everywhere.
- Logging in is rate limited: 10 attempts per 10 minutes per email address, and 5 attempts per minute per IP address (IPv6 addresses are counted per /64 network). A failed login takes the same time whether or not the email belongs to an account.
- **Separate lists, shared fetch data**: however many people track the same post, the system fetches it once and everyone sees the same numbers. A person who adds a post later also sees the snapshots fetched before they added it.
- Groups, tags and notes belong to each person's own list.
- The messages shown when you add or remove a post are the same whether or not anyone else tracks it.
- "Remove from my list" only affects you; tracking stops and the data is deleted only when the last person removes the post.
- Admins cannot see other people's lists either.
- The system stores the clean post URL, the public numbers and the post text; the share code and tracking parameters of a share link are never stored.

## Risks and disclaimer

- **The default User-Agent pretends to be Googlebot.** Fetching works only because the default `THREADS_USER_AGENT` is Google's crawler string, sent from a server that is not Google. This kind of use **may violate Meta's Terms of Service**, and your IP may be limited or blocked as a result. You can change it with `THREADS_USER_AGENT` in `.env`, but with any other User-Agent the page no longer contains the numbers (see [How it works §3](docs/HOW-IT-WORKS.md#3-fetching-the-user-agent-decides-what-you-get)).
- **Not affiliated with Meta**: this project has no relationship with Meta Platforms, Inc. or Threads, and is not endorsed or sponsored by them. "Threads" is a trademark of its respective owner.
- **Other people's content is stored**: the system saves the text of every tracked public post, which may be other people's posts. Handle it as you would any personal data you collect.
- **Shared data is visible to newcomers**: because fetched data is shared, anyone who later adds the same post sees all snapshots, including those fetched before they joined.
- **Views are the number shown on the web page**: it lags by a few minutes and is not guaranteed to match what the author sees in the app or the official API; it is also the field most likely to disappear in a redesign.
- **It can stop working at any time**: fetching depends on the internal format of the Threads web page and on the Googlebot User-Agent. When Meta redesigns the page or starts verifying crawlers, every fetch fails (shown as `parse_failed` or `blocked`) until the code is updated.
- **Rate limits are unknown**: only small-scale tests were done. When tracking many posts, watch the share of blocked fetches, and do not make the fetch interval shorter.
- Part of the "post deleted" detection is an assumption. Only public posts can be tracked; private accounts and deleted posts give no numbers.

Whether and how to use this project is your own decision, and you bear full responsibility for it. The software is provided "as is", without warranty of any kind; see the license.

## License

[MIT License](LICENSE)

Author: Sony Chou
