English | [繁體中文](HOW-IT-WORKS.zh-TW.md)

# How it works

This document explains how Threads Monitor gets the numbers of a public Threads post **without logging in and without any account or token**, and the rules it follows for scheduling, states and data. All post codes and usernames below are fake (for example `DFakeP0st01`, `example_user`).

**Both editions share the same rules**: the folder edition (`standalone/`) and the web edition use the same fetching method, page parsing, URL and share link resolution, polling frequency, state handling and the "NULL is never 0" principle. They differ only in storage and interface: the web edition keeps data in a database and has multiple accounts ([§12](#12-accounts-and-data-scope)); the folder edition writes CSV files into its own folder. Both can sort posts into groups: in the web edition a group is stored in the database, per account (posts without a group are listed as Ungrouped); in the folder edition a group is simply a folder (`## heading` lines in `urls.txt` choose it, posts without a group go to a folder named `Ungrouped`, or `未分組` on a Chinese system). File names and settings mentioned here (`app/…`, `config/threads.php`, `THREADS_*`) point to the web edition's implementation.

> Everything below reflects what was measured in October 2026. The Threads web page uses Meta's internal format, comes with no compatibility guarantee, and can change at any time.

## Contents

1. [In one sentence](#1-in-one-sentence)
2. [Why not the official API](#2-why-not-the-official-api)
3. [Fetching: the User-Agent decides what you get](#3-fetching-the-user-agent-decides-what-you-get)
4. [Where the data lives: the source of every field](#4-where-the-data-lives-the-source-of-every-field)
5. [What the view count really is](#5-what-the-view-count-really-is)
6. [URL parsing and share link resolution](#6-url-parsing-and-share-link-resolution)
7. [Shortcode decoding: a time from the code](#7-shortcode-decoding-a-time-from-the-code)
8. [Polling frequency](#8-polling-frequency)
9. [State machine](#9-state-machine)
10. [NULL is never 0](#10-null-is-never-0)
11. [Known risks](#11-known-risks)
12. [Accounts and data scope](#12-accounts-and-data-scope)
13. [Snapshot history, groups, tags, search](#13-snapshot-history-groups-tags-search)

---

## 1. In one sentence

A plain HTTP GET to the post's public URL with the Googlebot User-Agent returns server-side rendered (SSR) HTML that embeds the post's JSON data; likes, replies, reposts, quotes, shares, the publish time and the post text are read from that JSON, and the view count from another configuration block on the same page. The schedule decides how often to fetch based on the time since publishing, and the fetch at exactly 72 hours produces the "72-hour final numbers".

## 2. Why not the official API

The insights endpoint of the official Threads API **only reads the authorizing user's own posts**. For anyone else's posts:

- No official endpoint turns a post URL or code into the media id the API needs.
- oEmbed returns embed HTML only, with no numbers and no id.
- Endpoints such as keyword search or profile discovery can list other people's public posts, but without any metrics.

So there is no official route to "the numbers of any public post"; this project reads the logged-out public page instead. The cost is described in [§11](#11-known-risks).

## 3. Fetching: the User-Agent decides what you get

The same post URL returns completely different content depending on the User-Agent (all requests measured returned HTTP 200):

| User-Agent | Response size (approx.) | `og:description` | Post JSON (with numbers) | View count setting |
|---|---|---|---|---|
| Desktop browser | 280 KB | no | no (only a skeleton that needs JavaScript) | no |
| Mobile browser | 590 KB | yes | no | yes |
| `facebookexternalhit/1.1` | 570 KB | yes | no | yes |
| **Googlebot** | 660–700 KB | yes | **yes** | yes |

- Over plain HTTP only the Googlebot UA gets the full numbers, so the default `THREADS_USER_AGENT` is the Googlebot string (`config/threads.php`).
- One request is about 660–700 KB; no browser is needed.
- No cookies, no tokens, no login.
- A headless browser (Chromium) opening the page logged out gets the same JSON. It is a possible fallback if the Googlebot route gets blocked, and is **not implemented yet**.
- By default there is a 3-second pause between fetches (`THREADS_POLITENESS_SLEEP_MS`) and a 30-second timeout per fetch (`THREADS_TIMEOUT`).

How a fetch result is classified (`app/Threads/PublicPageFetcher.php`):

| Situation | Classification |
|---|---|
| HTTP 200, post JSON found | success |
| HTTP 404 / 410 | `gone` (post deleted or not public) |
| HTTP 429, or 200 with neither post JSON nor `og:description` | `blocked` (rate limited, login wall or challenge page) |
| HTTP 200 with `og:description` but no post JSON | `parse_failed` (the page format may have changed) |
| Connection failure | `network_error` |
| Any other HTTP status | `http_error` |

There is one more rule that is **an untested assumption**: if the page has `og:description` but its `og:url` no longer points to this post, the post is treated as deleted (`gone`). Everything else that cannot be classified becomes `parse_failed`. Showing a failure is better than mistaking a broken parser for a deleted post.

## 4. Where the data lives: the source of every field

The post data sits in `<script type="application/json">` blocks on the page: data preloaded by Meta's front-end framework (Relay). There are many such blocks, deeply nested, and the array indices in between can change with every deploy, so the code **never hard-codes a path**. Instead it:

1. Parses only the blocks that contain the string `"<post code>"` (to save time).
2. Searches recursively; the first object that has `code` equal to the post code **and** a `taken_at` key is the post (`media`). The search goes at most 64 levels deep.

Once `media` is found, the fields map as follows (`tpai` means `media.text_post_app_info`):

| Metric | Source | Notes |
|---|---|---|
| Likes | `media.like_count` | |
| Replies | `tpai.direct_reply_count` | Direct replies only |
| Reposts | `tpai.repost_count` | |
| Quotes | `tpai.quote_count` | |
| Shares | `tpai.reshare_count` | Often `null` on old posts and replies inside a thread: **stored as NULL, never 0** |
| Publish time | `media.taken_at` | Unix seconds, UTC |
| Views | `view_counts` inside the page setting `BarcelonaLoggedOutExpansionGating` | Not part of the `media` object, see [§5](#5-what-the-view-count-really-is) |
| Author | `media.user.username` | Until the first successful fetch, the web edition shows the account name from the URL you added, labelled **unverified** (anyone can type any `@name` into a URL); the first successful fetch confirms or corrects it. The "Open post" link always uses `https://www.threads.com/t/<code>` |
| Numeric id | `media.pk` | Not the official API media id |
| Author hid counts | `media.like_and_view_counts_disabled` | When `true`, likes and views are always stored as NULL and the page shows them as hidden by the author. Replies, reposts, quotes and shares are not affected |
| Post text | `media.caption.text` | Saved once, on the first successful fetch that has text; later edits of the post are not followed. No text → NULL |

The regular expression used for views:

```
"BarcelonaLoggedOutExpansionGating",\[\],\{[^{}]*?"view_counts":(\d+)
```

Only non-negative integers are accepted; negative numbers, non-numbers and values longer than 18 digits are treated as unavailable (NULL).

## 5. What the view count really is

- It is **the view count shown on the web page**: the desktop page header shows "X views", and for all 6 measured samples `view_counts` matched the number on screen (the screen shows an abbreviation such as "13K").
- Further evidence that it belongs to this specific post: every post has a different value; the same post has the same value under different UAs; it only appears on single-post pages; likes ÷ views is roughly 0.6%–1.3%; the number grows over time.
- It **lags**: in two fetches two minutes apart, likes went up while views stayed the same, which suggests a cache of a few minutes.
- It is **not guaranteed to equal** the view count the author sees in the app or in the official API; this was never verified.
- It lives in a "logged-out interface setting" next to `"enable_view_counts": false`. Of all the fields it is **the one most likely to disappear in a redesign**; when it is missing the code stores NULL and never fills in 0.

## 6. URL parsing and share link resolution

### 6.1 Accepted URLs

Every format is normalized to the same **post code (shortcode)**, which is the unique key in the database: the same post with a different domain, format or query string is the same tracked post.

```
https://www.threads.com/@example_user/post/DFakeP0st01
https://www.threads.net/@example_user/post/DFakeP0st01
https://threads.com/t/DFakeP0st01
```

Query strings such as `?igshid=…` and a trailing slash are accepted.

- `threads.net` redirects (301) to `threads.com`; the code always fetches the canonical `threads.com` URL.
- The post code alphabet is `A-Z a-z 0-9 - _`.
- URLs that do not match are rejected at input time, so no tracked post is created that could never be fetched.

### 6.2 Share links

"Share → Copy link" in the Threads app sometimes gives a URL like this:

```
https://www.threads.com/share/<share-code>/
```

The share code here is **not** the post code and does not tell which post it is. Resolution works like this:

1. Send one GET to the share link with the Googlebot UA, **without following redirects**.
2. The server answers HTTP 302; the `Location` header is the original post URL (usually with an `xmt` tracking parameter).
3. Parse the post code from `Location` and continue like any other post URL.

The database stores only the resolved **clean URL** (`https://www.threads.com/@username/post/code`). The share code and the `xmt` parameter identify the person who shared the link and are **never stored**.

## 7. Shortcode decoding: a time from the code

Threads reuses Instagram's id scheme, so a post code can be decoded offline into a numeric id and a time:

1. Alphabet (URL-safe base64): `ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_`
2. `pk = Σ index(c) × 64^k`, with the leftmost character as the most significant digit.
3. `created_ms = (pk >> 23) + 1314220021721` (Instagram's epoch, in milliseconds).

Example (a fake code, only to show the arithmetic): `DFakeP0st01` → `pk = 3556315264011394357` → `2025-01-29 15:50:37.762 UTC`.

Notes:

- `pk` is around 4×10¹⁸, close to the 64-bit integer limit, so the code computes it as a string with PHP's bcmath (hence the bcmath extension requirement).
- Measured on 8 real posts: the decoded `pk` matched the page's `media.pk` **digit for digit**.
- But the decoded value is the **id creation time**, which is not necessarily the publish time `taken_at`: 7 posts were within +1 second, one was 8.6 seconds earlier than `taken_at`. Scheduled posts (written first, published later) may differ much more; this was never verified.
- Therefore `published_at` **comes from the page's `taken_at`**. Decoding is only a temporary estimate until the page has been fetched; its source is recorded as `shortcode_decode` and the page labels it "estimated". After the first successful fetch `taken_at` replaces it.
- This `pk` is not the official Graph API media id and cannot be used with the official API.

## 8. Polling frequency

The schedule starts from the **publish time** (not from when the post was added). The default (`poll_bands` in `config/threads.php`; folder edition: `PollPlanner.DefaultBands`) is:

| Time since publish | Frequency |
|---|---|
| 0–72 hours | every 60 minutes |
| exactly 72 hours | one last fetch, which writes the 72-hour final numbers |

- Fetch times sit on a fixed grid anchored at the start of each band, so a scheduler run that is a few minutes late does not shift every later time; the last point is always "publish time + 72 hours".
- The scheduler runs `threads:poll-due` every 5 minutes, picks the posts whose next fetch time is ≤ now and fetches them one by one (at most 60 per run, 3 seconds apart). One failing post never affects the others.
- By default that is about 72 + 1 fetches per post (the first fetch when it is added, then one per hour). The risk of being blocked grows with the number of logged-out requests, so the schedule can be set: `THREADS_POLL_BANDS` in `.env` (folder edition: `poll_bands` in `settings.ini`), with `from-to:minutes` bands separated by commas. For example `0-24:60,24-72:180` changes it back to hourly for the first 24 hours and every 3 hours after that (about 24 + 16 + 1 fetches).
- Rules for that value: hours are whole numbers ≥ 0, each band ends after it starts, bands are in order and do not overlap (gaps between bands are allowed), and the interval is at least 5 minutes. If any band is invalid the whole value is ignored and the default is used (web edition: a warning in the Laravel log; folder edition: a line in `log.txt`). Posts already being tracked switch to the new schedule after their next fetch; nothing needs to be migrated.
- **Time not covered by a band is not fetched.** When the time since publish falls in a gap between two bands, the next fetch is at the start of the next band. When the first band starts later than hour 0, nothing is fetched before it, except the first fetch when the post is added. After the last band ends, the next fetch is the final one at the end of tracking. Example: `0-24:60,48-72:60` fetches every hour from hour 1 to hour 24 and from hour 48 to hour 72, and nothing between hour 24 and hour 48.
- Every day at 04:10 (Asia/Taipei) `threads:prune-raw` clears the debug field of snapshots older than 7 days and keeps only the numbers.
- 72 hours can be changed with `THREADS_TRACK_HOURS`, but the bands above only cover 0–72 hours: with a larger value, nothing is fetched between hour 72 and the end except the final fetch; with a smaller value, tracking ends earlier. Page labels (for example "72-hour final") follow this value.

## 9. State machine

| State | Meaning |
|---|---|
| `pending` | Just added, waiting for the first fetch |
| `tracking` | Being tracked |
| `done` | Finished, 72-hour final numbers written |
| `gone` | Post deleted or no longer public; tracking stops, existing snapshots are kept |
| `failed` | Failed; tracking stops and the page shows the reason |

Transitions (`app/Threads/PostPoller.php`):

- **Success**: write a snapshot; if 72 hours have passed, this snapshot becomes the 72-hour final numbers and the state becomes `done`; otherwise the state becomes `tracking` and the next fetch time is calculated.
- **Added after 72 hours had already passed**: when the post entered the system (added by anyone for the first time) at or after publish time + 72 hours, it is fetched once, written as the final numbers, and flagged as added after the 72-hour window: these are not the numbers at the 72-hour mark. A person who adds a post that someone else already tracks does not change this flag.
- **`gone`**: tracking stops; the final numbers come from the last successful snapshot, recorded with that snapshot's real time. If there was at least one successful snapshot, the list and the post page show its numbers as the last numbers before deletion.
- **Any other failure** (`blocked`, `parse_failed`, `network_error`, `http_error`, an exception in the code): the failure count goes up by one and the fetch is retried after 15 minutes (never later than the 72-hour deadline).
  - 5 failures in a row (`THREADS_MAX_CONSECUTIVE_FAILURES`) → `failed`; if any fetch had succeeded before, its numbers are kept as the last numbers before the failure and shown as such.
  - The fetch at 72 hours fails → the last successful snapshot becomes the final numbers, and `final_captured_at` records **that snapshot's real time**, not a pretend "exactly 72 hours"; if nothing ever succeeded → `failed`.
- **Failures must be visible**: the category and message of the last error are stored in `last_error`, shown on the post page together with the number of consecutive failures.
- No single fetch can stop the whole schedule: exceptions are recorded as a failure of that post.

## 10. NULL is never 0

This is the most important data rule of the project:

- **NULL means unavailable** (field missing, hidden by the author, page redesigned); **0 means really zero**. The page shows them differently: "Not available" (Chinese interface: 無法取得) vs. "0".
- Every metric column in snapshots and final numbers is nullable. Whatever was fetched is stored as is: no filling in, no carrying over the previous value.
- Charts skip NULL points.
- Why: if "unavailable" were stored as 0, on the day of a redesign every post's views would "drop to 0", look like real data, and nobody would notice.

## 11. Known risks

1. **A Googlebot UA sent from a non-Google IP**: as soon as Meta starts verifying reverse DNS (checking that the source really is `googlebot.com`), every fetch becomes `blocked`. Being public makes it more likely that this project gets targeted. The fallback (headless browser) is not implemented yet.
2. **The page format is internal**: the recursive search for the post object survives small changes, not large redesigns. A redesign shows up as many `parse_failed` results.
3. **Views are the most fragile field**: see [§5](#5-what-the-view-count-really-is); they are also not guaranteed to match the official number.
4. **Rate limits were never load-tested**: during testing about 50 requests from one IP all succeeded, which says nothing about a long-running schedule. Watch the share of `blocked` results before tracking many posts.
5. **Part of the "post deleted" detection is an assumption**: see the end of [§3](#3-fetching-the-user-agent-decides-what-you-get).
6. **Terms of service**: fetching public pages without logging in may violate Meta's Terms of Service, and your IP may get blocked. Whether and how you use this is your decision and your responsibility.

## 12. Accounts and data scope

This section applies to the web edition only.

- No self-registration. The first admin is created with a command (`php artisan threads:create-admin <email>`, or by the installer `threads:install`); an admin creates, edits, resets passwords for, disables or deletes other accounts at `/users`.
- Everyone can change their own password at `/account/password` (at least 12 characters). Changing your own password signs out your other sessions; an admin resetting someone's password or disabling the account ends all of that account's sessions. A disabled account that is still logged in is signed out on its next request.
- Logging in is limited to 10 attempts per 10 minutes per email address and 5 attempts per minute per IP address (IPv6 per /64). Spelling variants of one email (upper and lower case, accents, full-width letters, spaces) count toward the same limit. An unknown email, a disabled account and a wrong password all get the same message, and the response time does not depend on whether the account exists: each of the three costs exactly one password hash check.
- **Separate lists, shared fetch data**: a post exists once in the system and is fetched once; when a second person adds the same post it is only attached to their list, without fetching again or resetting its state. They see all its snapshots, including those fetched before they added it.
- The messages after adding or removing a post are the same whether or not other accounts track it, so nobody can tell from them what others track.
- Tags and notes are stored per person and per post in their list (on the list entry, not on the shared post), so other accounts never see them.
- "Remove from my list" only affects you; when the last person removes a post, the post and its snapshots are deleted and tracking stops.
- Admins cannot see other people's lists either; the account page only shows how many posts each person tracks.
- The system always keeps at least one active admin. If you forget the password, run `threads:create-admin <same email>` on the server to reset it and restore the admin and active status.

## 13. Snapshot history, groups, tags, search

This section applies to the web edition only (`app/Threads/SnapshotDeltas.php`, `app/Http/Controllers/TrackedPostController.php`).

**Snapshot history.** The post page lists every snapshot (all six metrics); each post in the list has a folded history of the same snapshots (views, replies, reposts and shares). Under every number is the change since the previous snapshot:

- The change is computed only when both snapshots have the number; if either is NULL the change is shown as unknown, never as a jump from or to 0.
- The change is also converted to a per-hour rate, but **only when the two snapshots are at least 0.5 hours apart**. Retries after a failure can be minutes apart, and dividing by such a short gap would produce meaningless spikes.
- The post page also shows the gap between two snapshots in hours.

**Groups, tags and notes** belong to each account, like the list itself: other accounts never see them.

- **Adding a post only needs the URL.** The group, tags and note are set afterwards: every card in the list has an edit button that changes them in place, and the post page has the same editing area at the top.
- **Groups**: a post belongs to at most one group per account. The list is shown in sections, one per group; each section is a block you can open, its title shows the group name and the number of posts. Sections start closed; on a filtered list the sections with matches open automatically, otherwise your browser remembers which sections you opened (stored in that browser only). Posts without a group come last, under Ungrouped. The group manager above the list adds, renames, deletes and reorders groups. Deleting a group moves its posts back to Ungrouped; the posts and their data are not affected. A group name is at most 40 characters, at most 100 groups per account, and two names that differ only in case, accents or full-width letters count as the same name.
- **Tags**: comma-separated; at most 20 per post, at most 30 characters each, no commas or `#`; a leading `#` is dropped; matched without regard to case, accents or full-width letters (`Café`, `cafe` and `ＣＡＦＥ` are one tag). Saving the tags replaces the post's whole tag set; a tag that no post uses any more disappears.
- **Note**: one per post, at most 1000 characters.

**Search and filters.** The list can be narrowed with URL parameters, so a filtered list can be bookmarked. The results are still shown in group sections; groups without any match are not shown.

| Parameter | Meaning |
|---|---|
| `q` | Keyword (at most 100 characters), searched in the author's username, the post code, the post text, your note, your tag names and the name of your group the post is in. Case-insensitive, also for letters outside English such as `É`/`é` or Greek, on SQLite and MySQL alike |
| `status` | One of `pending`, `tracking`, `done`, `gone`, `failed` |
| `tag[]` | One of your tags; repeat it for more (at most 20). A post must have **all** the given tags |
| `group` | The id of one of your groups, or `none` for Ungrouped |

Example: `/?q=launch&status=done&tag[]=campaign`. Invalid values are ignored instead of causing an error; a tag you do not have gives an empty list. Search and filters only ever look at your own list.
