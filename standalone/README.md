English | [繁體中文](README.zh-TW.md)

# ThreadsMonitor (folder edition)

ThreadsMonitor follows public Threads posts for the first 72 hours after they are published and writes views, likes, replies, reposts, quotes and shares into CSV files that open in Excel, Numbers or Google Sheets.

It is a single program in a single folder. There is no server, no database and no account to create. You paste post URLs into a text file called `urls.txt`, and the program writes the numbers into the same folder. You can let your computer update them automatically, or update them by hand whenever you like.

## What it does

- You list the posts you care about in `urls.txt`, one URL per line. You can sort them into groups (for example one group per client) and give them tags and a note.
- Every post gets its own folder. Each time the program checks a post it adds one line (a **snapshot**) to that post's `snapshots.csv`, with the date and the hour.
- Checking schedule: once an hour for the first 72 hours after publishing, and one last check at exactly 72 hours. That last check gives the **72-hour final numbers**. To check less often (lower risk of being blocked), set `poll_bands` in `settings.ini`.
- `index.csv` in the main folder lists every post on one line: latest numbers, 72-hour final numbers, status, tags, note, the start of the post text and where its folder is.
- Two ways to update: **automatic** (your computer runs the program every 10 minutes in the background) and **manual** (double-click the program to check every post right now).

## Before you start

Please read these limits first:

- **Your computer has to be on.** The automatic schedule only runs while the computer is on and awake. If it is off at the 72-hour mark, the program takes the final reading as soon as the computer is back, writes the real time, and marks it `late_final = true`. It never pretends a late reading was taken at 72 hours.
- **Public posts only.** The program reads the same public page anyone can open without logging in. Private, deleted or age-restricted posts cannot be read.
- **Threads can change at any time.** This tool reads a public web page, not an official API. If Threads changes that page or starts blocking these requests, the tool stops working until it is updated.
- **Views** are the number shown on the public web page. They may differ from what the author sees in their own statistics.
- When the author hides like and view counts, those columns stay empty. An empty cell always means "not available", never zero.

## Risks

- **It pretends to be Google's crawler.** By default the program tells Threads that it is Googlebot (Google's search crawler), because that is what makes Threads return the numbers without logging in. You can change this with `user_agent` in `settings.ini`, but with another value Threads may stop returning the numbers.
- **This kind of use may violate Meta's Terms of Service.** Threads may limit or block your internet connection (your IP address) because of it. Whether and how you use the tool is your decision and your responsibility.
- **It is not made by Meta.** ThreadsMonitor has no relationship with Meta Platforms, Inc. or Threads.
- **It saves other people's text on your computer.** The post text of every tracked post is stored in its `snapshots.csv` and `info.txt`, and the first 200 characters in `index.csv`. Treat these files with the same care as any other copy of someone else's content.
- **Views are the value shown on the web page**, not an official statistic.

## Step 1: Download

Download the file for your computer from the project's Releases page:

| Your computer | File to download |
| --- | --- |
| Windows 10 or 11 | `win-x64` |
| Mac with Apple chip (M1, M2, M3, M4 and later) | `osx-arm64` |
| Mac with Intel chip | `osx-x64` |
| Linux (64-bit) | `linux-x64` |

Not sure which Mac you have? Click the Apple menu, then **About This Mac**. "Chip: Apple M..." means `osx-arm64`; "Processor: Intel" means `osx-x64`.

**How to check it worked:** the program file is about 40 MB.

## Step 2: Unzip into its own folder

1. Create a new folder for the tool, for example `ThreadsMonitor` in your user folder.
   - On a Mac, do **not** use Desktop, Documents or Downloads: macOS stops background programs from writing there. Use your home folder (the one with the house icon) instead.
   - On Windows, a short path such as `D:\Threads` is best (see [Sync folders](#sync-folders-onedrive-dropbox-google-drive)).
2. Unzip the download and move the program into that folder.

Everything the tool creates will appear in this same folder. You can move the whole folder later, but uninstall the schedule first (Step 5) and install it again afterwards.

**How to check it worked:** the folder contains just one file, `ThreadsMonitor.exe` (Windows) or `ThreadsMonitor` (Mac, Linux).

## Step 3: Add posts to urls.txt

1. Run the program once (Step 4 explains how). On the first run it only creates `urls.txt` with instructions in it and then stops. It does not go online.
2. Open `urls.txt` with Notepad (Windows) or TextEdit (Mac).
3. Paste one Threads post URL per line. You can copy the URL from the browser or use "Copy link" in the Threads app. Share links work too.
4. The new file contains only explanations: every line starts with `#`. Its last lines show an example group as a comment (`# ## Client A`), which does nothing. URLs you paste at the end of the file, with no `## name` line above them, have no group: their folders go into the `Ungrouped` folder. To use a group, write a line `## name` (without `# ` in front) above its URLs.
5. Save the file. Keep it as UTF-8 (the default in Notepad and TextEdit).

**How to check it worked:** the next run prints one line per post, like `[1/3] @example_user DFakeP0st01 ...`. A line that is not a valid post URL is reported with its line number and skipped.

### What a line can contain

```text
# Lines starting with # are notes for yourself and are ignored.
https://www.threads.com/@example_user/post/DFakeP0st01 #video

## Client A
https://www.threads.com/@example_user/post/DFakeP0st02 #video #launch | first post of the campaign
https://www.threads.com/t/CFake_Old01

##Autumn sale
https://www.threads.com/share/ShareFake01/ #sale | shared by the client
```

| What you write | What it means |
| --- | --- |
| A URL alone | Track this post. |
| `## Name` or `##Name` | Start a group. Every URL below it, until the next group line, belongs to the group "Name". |
| `##` with nothing after it | End the current group. URLs below it have no group again. |
| A line starting with `#` (and not a group line) | A comment. It is ignored. `###` lines are comments too. |
| ` #word` after the URL (space before `#`) | A **tag**. A line can have several tags. |
| `\|` after the URL | Everything after the first `\|` is a **note**. |
| `#` right after the URL with no space | Part of the URL, not a tag. |

Typing with a Chinese or Japanese input method is fine:

- A full-width `＃` or `｜` counts as `#` or `|` (in group lines, tags and notes alike).
- Invisible characters that come along when you copy from a chat app (zero-width spaces, direction marks and similar) are removed before the line is read. The invisible joiners inside emoji are kept.
- A line that starts with a tag and then has the URL, such as `#video https://www.threads.com/@example_user/post/DFakeP0st01`, is a comment, so that post is **not** tracked. The program warns: `urls.txt line N: this line is a comment, so it is not tracked. Tags go after the URL: <URL> #tag | note`. Lines starting with `# ` (a space after `#`) and URLs commented out as `#https://...` are left alone.
- Problems found in `urls.txt` are written to `log.txt` and also shown in the window, except during a scheduled run.

Accepted URL forms: `threads.com` or `threads.net`, with or without `www.`, with or without `https://`, `/@account/post/CODE`, `/t/CODE`, and `/share/CODE` share links. A share link is turned into the post URL once (this needs the internet) and then remembered.

### Tags and notes

- A tag is 1 to 30 characters, with no spaces and no `;`, `,` or `|`. Chinese and other languages are fine. The leading `#` is not part of the tag.
- At most 20 tags per line. Extra tags and invalid tags are skipped, written to `log.txt` and shown in the window (except during a scheduled run).
- Tags are compared like `search` compares text: upper and lower case do not matter, and full-width letters and digits count as normal ones, so `#Video`, `#video` and `#ｖｉｄｅｏ` are the same tag. The spelling used first in the file is the one shown. `--tag` uses the same rule.
- A note is at most 1000 characters. A longer note is cut off and `log.txt` says so.
- Text after the URL that is neither a tag nor a note is ignored and written to `log.txt`.
- Tags and notes live **only** in `urls.txt`. Change them there and they change in `index.csv` and `info.txt` on the next run (`status` and `search` see the change at once). If you delete a post's line from `urls.txt`, its tags and note disappear from `index.csv` too.

### The same post on several lines

- The post is checked once.
- Its folder goes into the group of the **first** line.
- It gets the tags of **all** its lines together, and the **first** note that is not empty.

## Step 4: Run it for the first time

Double-click the program. A window opens, checks every post in `urls.txt`, prints a summary table and waits for you to press a key.

**How to check it worked:** a new folder appears for each post (inside its group folder), and `index.csv` appears next to the program.

The first time you open it, your system may warn you because the program is not signed by a paid certificate:

### Windows: SmartScreen

If you see "Windows protected your PC":

1. Click **More info**.
2. Click **Run anyway**.

You only need to do this once.

### macOS: Gatekeeper

If you see "ThreadsMonitor cannot be opened because Apple cannot check it for malicious software" (or "Not Opened"):

1. Click **Done** (or **OK**).
2. Open **System Settings**, then **Privacy & Security**.
3. Scroll down. Next to the message about ThreadsMonitor, click **Open Anyway**, then confirm with your password.
4. Double-click the program again.

If you are comfortable with Terminal, this does the same thing (run it inside the tool's folder):

```bash
xattr -d com.apple.quarantine ThreadsMonitor
```

### Linux

Make the file executable once, then run it from a terminal in the tool's folder:

```bash
chmod +x ThreadsMonitor
./ThreadsMonitor
```

## Step 5: Turn on automatic updates

Automatic updates run the program every 10 minutes in the background. It only checks the posts that are due, so most runs finish immediately without going online. Each folder gets its own schedule.

**Only one computer per folder.** If the folder is in OneDrive, Dropbox or Google Drive, turn on the schedule on one computer only. If another computer used the folder in the last 30 minutes, the program refuses to run and tells you the other computer's name.

### Windows

1. Open the tool's folder in File Explorer.
2. Click the address bar, type `cmd` and press Enter. A black Command Prompt window opens in that folder.
3. Type this and press Enter:

```bat
ThreadsMonitor.exe install-schedule
```

**How to check it worked:** it prints `Schedule installed: Task Scheduler "ThreadsMonitor-..."`. You can also open **Task Scheduler** and find a task named `ThreadsMonitor-` followed by 8 letters and digits.

Good to know:

- The task also runs when a laptop is on battery.
- It does not wake a sleeping computer. A run that was missed starts as soon as the computer is awake again.
- Every 10 minutes a black window flashes for a moment: that is the check running. It is normal.

### macOS

1. Open **Terminal** (Applications, Utilities).
2. Type `cd ` (with a space), drag the tool's folder into the Terminal window, press Enter.
3. Type this and press Enter:

```bash
./ThreadsMonitor install-schedule
```

**How to check it worked:** it prints `Schedule installed:` followed by the path of a `.plist` file in `Library/LaunchAgents`. Within a few minutes `log.txt` in the tool's folder gets new lines.

### Linux

```bash
./ThreadsMonitor install-schedule
```

The program shows the crontab line it wants to add and asks before changing anything. Answer `y` to add it.

**How to check it worked:** `crontab -l` shows a line ending with `# ThreadsMonitor-` and 8 letters and digits.

### Turning it off

Run the same command with `uninstall-schedule` instead of `install-schedule`, in the same folder.

## Step 6: Read the results

All CSV files are UTF-8 with a byte order mark, so Excel shows Chinese and emoji correctly when you double-click them. Times are in your computer's time zone, written like `2026-10-05T14:00:00+08:00`. Every time also has a separate date column and hour column so you can sort and filter easily. Column names are always in English.

Opening the files to look at them is always fine, even while the program is running. If you want to change something, see [Excel](#excel).

### The folder after a few runs

```text
ThreadsMonitor/
  ThreadsMonitor.exe
  urls.txt
  index.csv
  log.txt
  Ungrouped/
    2026-10-06_09h_@example_user_DFakeP0st01/
      snapshots.csv
      info.txt
  Client A/
    2026-10-03_14h_@example_user_DFakeP0st02/
      snapshots.csv
      info.txt
```

- Group folders are named after the `## Name` line. Posts without a group go to `Ungrouped` (`未分組` when your system language is Chinese). Whichever of the two exists first is kept.
- Post folders are named `yyyy-MM-dd_HHh_@account_CODE`: the publish date and hour in your time zone, the account and the post code. If the account is not known yet, the `@account_` part is left out. If two folders would get the same name, the second one gets `_2`.
- If the publish time is not known yet when the folder is created, the folder starts with `0000-00-00_`. The program renames it once the time is known, but only while it still sits directly inside its group folder. This is the only time the program ever renames a folder; it never moves or deletes them.
- Group names longer than 40 characters are shortened. Characters that are not allowed in folder names (`\ / : * ? " < > |`) become `_`, and a name starting with `.` gets `_` instead of the dot.
- A group called `archive` or `封存` would make its posts read-only (see [Archiving](#archiving)), so the program puts them in a folder called `_archive` or `_封存` instead.

### index.csv: one line per post

Newest post first. `index.csv` is rebuilt on every run: if you delete it, it comes back on the next run, and any change you make in it is lost.

| Column | Meaning |
| --- | --- |
| `shortcode`, `username`, `url` | The post's code, account and clean URL |
| `published_date`, `published_hour`, `published_at_local` | When the post was published |
| `published_at_source` | `page` (read from the post) or `shortcode` (estimated from the post code until the page has been read) |
| `status` | `tracking`, `done` (72 hours finished), `gone` (deleted or made private), `failed` (see [When checks fail](#when-checks-fail)) |
| `hours_since_publish` | How many hours after publishing the latest numbers were taken |
| `views`, `likes`, `replies`, `reposts`, `quotes`, `shares` | Latest numbers |
| `final_views` ... `final_shares` | The 72-hour final numbers |
| `final_captured_at_local` | The real time the final numbers were taken |
| `late_final` | `true` when the final numbers were taken more than 20 minutes after the 72-hour mark (computer was off), or when the final check kept failing and the last good numbers were used instead |
| `added_after_window` | `true` when the post was added after its 72 hours had already passed, so only one check was possible |
| `counts_hidden` | `true` when the author hides likes and views |
| `last_error` | The last problem, or a message about the folder (archived, removed from `urls.txt`, copies found) |
| `next_due_local` | When the next automatic check is due |
| `group` | The group folder the post is in right now (empty when the post folder is directly in the main folder) |
| `folder` | Where the post's folder is right now, for example `Client A/2026-10-03_14h_@example_user_DFakeP0st02` |
| `archived` | `true` for posts in an archive folder |
| `tags` | The post's tags from `urls.txt`, in alphabetical order, separated by `;` |
| `note` | The post's note from `urls.txt` |
| `caption` | The first 200 characters of the post text, on one line (`…` when it is longer) |

**Filter by tag in Excel:** use the filter on the `tags` column with "Contains", for example "Contains video".

### snapshots.csv: every check of one post

Each post folder has a `snapshots.csv` with one line per check. This file is the real data: everything else (`index.csv`, `info.txt`) is built from it.

| Column | Meaning |
| --- | --- |
| `captured_at_local`, `captured_date`, `captured_hour` | When the check happened |
| `shortcode`, `url`, `username` | The post |
| `input_key` | A short code that lets the program recognise the `urls.txt` line this post came from (see below) |
| `published_at_local`, `published_at_source` | When the post was published, and where that time came from |
| `counts_hidden` | `true` when the author hides likes and views |
| `hours_since_publish` | Hours between publishing and this check |
| `status` | `ok`, or why the check failed: `network_error`, `blocked`, `http_error`, `parse_failed`, `gone`, `exception` |
| `views`, `likes`, `replies`, `reposts`, `quotes`, `shares` | The numbers at this check (empty when not available) |
| `error` | What went wrong, if anything |
| `track_hours` | How many hours this post is tracked (72) |
| `caption` | The post text. It is written once, in the first line that had it; later lines leave it empty |

About `input_key`: the program does **not** save the line you pasted. A pasted URL can contain tracking codes after `?`, and a share link contains a private share code. Instead it saves the first 16 characters of a one-way fingerprint (SHA-256) of the post code or share code. It cannot be turned back into the original URL; it only lets the program recognise a share link it has already resolved, so it does not have to go online again.

### info.txt: a readable card

Each post folder also has an `info.txt` with the URL, account, post code, publish time, status, latest numbers, 72-hour final numbers, last error, next check, tags, note and the full post text. It is rewritten automatically whenever something in it changes, so do not write in it.

## Find posts: status and search

Both commands only read the files on your computer. They never go online and never change anything, so you can run them at any time, even while the schedule is running. Open a command window in the tool's folder first (as in Step 5). On a Mac or Linux, write `./ThreadsMonitor` instead of `ThreadsMonitor`.

```bat
ThreadsMonitor status
ThreadsMonitor status --tag video
ThreadsMonitor status --tag video --tag launch
ThreadsMonitor status --group "Client A"
ThreadsMonitor search sale
ThreadsMonitor search sale --group "Client A"
ThreadsMonitor search -- -5%
```

- `status` shows the summary table of every post.
- `--tag` keeps only posts that have that tag. With several `--tag`, a post needs **all** of them. Write the tag without `#`.
- `--group` keeps only posts in that group. It matches the group folder the post is in now, or the group of its line in `urls.txt`, ignoring upper and lower case.
- `search` looks for the words in the account, the post code, the tags, the note and the full post text. Upper and lower case do not matter, full-width letters and digits match normal ones, and a part of a word is enough. Several words are searched as one phrase. `--tag` and `--group` can be added to `search` too.
- `--tag` and `--group` only work with `status` and `search`.
- To search for words that start with `-` (like `-5%`), put `--` before them: `search -- -5%`. Everything after `--` is searched, even when it starts with `-`.
- An option without its value (for example `--tag` at the end of the line, or followed by another option) is an error: the program says `--tag needs a value after it (a value cannot start with --).` and exits with code 2. The same goes for `--group`, `--dir` and `--lang`.
- Archived posts and posts removed from `urls.txt` are included too. Posts removed from `urls.txt` have no tags or note any more.

The summary table has these columns:

| Column | Meaning |
| --- | --- |
| `#` | Row number |
| `Post` | Account and post code |
| `Status` | `tracking`, `done`, `deleted` or `failed`; a `*` after it means the post is archived |
| `Hours` | Hours after publishing of the latest numbers |
| `Views`, `Likes`, `Replies`, `Reposts` | Latest numbers |
| `Next fetch` | Date and time of the next automatic check, `now`, or `-` when there is none |
| `Matched in` | Only for `search`: where the words were found (`account`, `code`, `tag`, `note`, `post text`) |

**How to check it worked:** `status --tag video` starts with a line like `Posts with #video: 2 of 5`, and `search sale` with a line like `Search "sale": 1 of 5 post(s) match`. When nothing matches it says `No post matches.`

## Organising your posts

### Moving folders between groups

- The group in `urls.txt` is only used when a post's folder is created. To change the group of an existing post, drag its folder into another group folder. Changing the `## Name` line later does not move existing folders.
- The program recognises a post by the data inside its `snapshots.csv`, not by the folder's name or place. You can rename post folders, rename group folders and add your own subfolders, up to 6 levels deep.
- Folders whose name starts with `.` are ignored.

### Archiving

Create a folder named `archive` or `封存` anywhere inside the tool's folder and drag finished post folders into it. Any folder on the way with one of those names counts, in upper or lower case. Posts inside an archive folder are read-only: the program never checks or changes them, but still lists them in `index.csv` with `archived = true`. Drag a folder back out of the archive and the program treats it normally again.

### Stopping and deleting

- To **stop tracking** a post but keep its numbers: delete its line from `urls.txt`. The folder and data stay, and `last_error` says `Removed from urls.txt, no longer tracked.` Put the line back to continue.
- **Deleting a post folder (or zipping it) deletes that post's data.** If its URL is still in `urls.txt`, the program creates a new, empty folder for it on the next run. A post that is already past 72 hours then gets one single check, marked `added_after_window`. So: remove the URL from `urls.txt` first, or move the folder into an archive folder.
- If you copy a post folder, the program sees the same post twice and stops checking it until only one copy is left outside the archive. The `last_error` column of `index.csv` tells you when this happens.

## Commands

Open a command window in the tool's folder first (as in Step 5). On a Mac or Linux, write `./ThreadsMonitor` instead of `ThreadsMonitor`.

| Command | What it does |
| --- | --- |
| (double-click, no command) | Check every tracked post now, show the summary, wait for a key |
| `update` | Check every tracked post now (manual update) |
| `run` | Check only the posts that are due (this is what the schedule uses) |
| `status` | Show the summary without going online; `--tag` and `--group` filter it |
| `search <words>` | Find posts by account, code, tag, note or post text, without going online; `search -- <words>` for words that start with `-` |
| `install-schedule` | Run `run` automatically every 10 minutes |
| `uninstall-schedule` | Remove the automatic schedule |
| `--dir <folder>` | Use another work folder instead of the program's folder |
| `--lang en` or `--lang zh` | Choose the language of the messages |
| `--help` | Show all commands and the checking schedule that is in effect (read from `settings.ini`) |

Messages are shown in Traditional Chinese when your system language is Chinese, otherwise in English.

An unknown command or option, or an option without its value, prints a message and exits with code 2.

A manual update also adds snapshots, but it does not move the checking schedule. If two copies of the program start at the same time in the same folder, the second one says `Another ThreadsMonitor is already running in this folder. Skipping this run.` and stops.

## settings.ini (optional)

Create `settings.ini` in the tool's folder only if you want to change something. Every line is optional. Lines starting with `#` or `;` are comments. A line the program does not understand (an unknown key, an invalid value, or a line without `=`) is ignored and the default is used; the line is written to `log.txt` and also shown in the window, except during a scheduled run (these messages are in English). When the line contains full-width digits such as `２４`, the message adds `(use half-width digits 0-9)`; when it contains a full-width `＝`, `：`, `，` or `－`, it adds `(use half-width = : , -)`. Type those characters again in half-width. A `user_agent` with characters other than plain ASCII is ignored.

```ini
# seconds to wait between two requests (default 3)
sleep_seconds = 3
# seconds before a request gives up (default 30)
timeout_seconds = 30
# minutes before retrying a failed check (default 15)
retry_minutes = 15
# failed checks in a row before a post is given up (default 5)
max_consecutive_failures = 5
# a final check later than this many minutes after 72 hours is marked late_final (default 20)
late_final_tolerance_minutes = 20
# hours to track new posts (default 72); posts that already started keep their own
track_hours = 72
# checking schedule after publishing, as from-to:minutes bands (default: every 60 minutes, 0 to 72 hours)
# to check less often (lower risk of being blocked), e.g. hourly for 24 hours, then every 3 hours:
# poll_bands = 0-24:60,24-72:180
# an invalid value is written to log.txt and the default is used
# how the program introduces itself to Threads (default: Googlebot, see Risks)
# user_agent = ...
```

Rules for `poll_bands`: hours are whole numbers, every band ends after it starts, bands are in order and do not overlap, and the interval is at least 5 minutes. Gaps between bands are allowed, and time not covered by a band is not checked:

- When the time since publishing falls in a gap between two bands, the next check is at the start of the next band.
- When the first band starts later than hour 0, nothing is checked before it, except the first check when the post is added.
- After the last band ends, the next check is the final one at the end of tracking.

Example: `poll_bands = 0-24:60,48-72:60` checks every hour from hour 1 to hour 24 and from hour 48 to hour 72, and nothing between hour 24 and hour 48.

To see which schedule is in effect, run `ThreadsMonitor --help`. Its last lines describe the schedule from your `settings.ini`; with the example above: `Schedule (settings.ini poll_bands): 0-24 h after publishing every 60 minutes, no fetch 24-48 h, 48-72 h after publishing every 60 minutes, and a last fetch at exactly 72 hours.`

## When checks fail

- **No internet** (Wi-Fi off, laptop offline): this never counts as a failure. If the very first check of a run cannot reach anything, the program stops for this run, prints `No network connection. Nothing was counted as a failure; will try again next time.` and tries again on the next run. A post never becomes `failed` because your computer was offline.
- **Threads answers with a problem** (it refuses the request, sends an error page, or sends a page the program cannot read): this counts as a failure. The program tries the post again after 15 minutes. After 5 failures in a row the post is given up. One good check resets the count.
- If a post is given up **before** its 72 hours are over, its status is `failed`, and its last good numbers are kept as the final numbers.
- **After** the 72-hour mark, failed final checks are still retried every 15 minutes. If 5 in a row fail, the post becomes `done` with its last good numbers as the final numbers and `late_final = true`. It only becomes `failed` when there has never been a single good check.
- **Deleted or private post**: the post becomes `gone` at once. Its last good numbers are kept as the final numbers.
- **Final check a little late**: a final check up to 20 minutes after the 72-hour mark still counts as on time. Later than that, `late_final = true`.
- **Wrong computer clock**: snapshots dated more than a day in the future are ignored and `last_error` says `Some snapshots are dated in the future. Please check the computer clock.` If the clock is earlier than the publish time, the snapshot gets 0 hours and `clock_before_publish` in its `error` column.
- **Passwords never appear in messages**: error messages in the window, `log.txt` and `snapshots.csv` replace a user name and password inside a web address (for example a proxy address) with `***` and drop everything after `?`.

## Sync folders (OneDrive, Dropbox, Google Drive)

- The tool works inside a synced folder, but **only one computer may run the schedule** for that folder. The program writes the computer's name into a hidden `.lock` file. If another computer used the folder in the last 30 minutes, it stops with: `This folder is being used by another computer (NAME, last run TIME). Only one computer may run the schedule for a folder. Remove the schedule on one of them (uninstall-schedule).`
- When a sync program makes a conflicting copy of a file or a post folder (names like `snapshots (1).csv`, `folder-COMPUTERNAME` or `conflicted copy`), the program keeps tracking the original, counts the rows of every copy, and asks you: `Found copies made by a sync program (OneDrive/Dropbox): .... Please merge them by hand and delete the copies.`
- With OneDrive **Files On-Demand**, the program reads every `snapshots.csv` on each run, including archived ones, which can make OneDrive download archived folders. If that is a problem, keep archived posts outside the synced folder, or mark the folder "Always keep on this device".
- **Windows only:** Windows cannot handle very long paths (the limit used is 240 characters). If your folder is deep inside OneDrive, the program uses a shorter name for new post folders (`yyyy-MM-dd_CODE`). If even that is too long, the post is not checked and the program asks you to move the whole tool folder somewhere shorter, for example `D:\Threads`. Mac and Linux have no such limit.

## Excel

Looking is always safe. Saving needs a little care:

- **index.csv**: any change is lost, because the file is rebuilt on every run. Write notes in `urls.txt` after `|` instead.
- **snapshots.csv**: the safest way is **Save As** under another name. If you did save over it, the program still reads it in most cases:
  - columns are matched by their name, so changing the order of columns is fine;
  - files that Excel saved with `;` or tab between the columns (common with some regional settings) are read and new lines are written the same way;
  - new lines follow the file's own columns: columns you deleted are skipped, columns you added stay empty;
  - a line whose time Excel changed into something unreadable is skipped (and written to `log.txt`);
  - if the `shortcode` column can no longer be read at all, that folder becomes read-only and `index.csv` says so. Save it again as CSV (comma separated) or restore it from a backup.
- Text that Excel would run as a formula (starting with `=`, `+`, `-` or `@`) is written with a `'` in front, so Excel shows it as text. This applies to every text column that comes from outside the program: in `index.csv` the account, post code, group, folder, tags, note, post text and `last_error`; in `snapshots.csv` the account, post code, `error` and post text. When the program reads `snapshots.csv` back, it removes the `'` again.
- While a file is open in Excel on Windows, the program cannot write to it:
  - `snapshots.csv`: the new lines go to a `snapshots.pending-...csv` file in the same folder and are added back on a later run. Nothing is lost.
  - `index.csv`: it is skipped this time and rebuilt on the next run.

## Common questions

**I opened index.csv in Excel and saved it. Is my data broken?**
No. `index.csv` is only a summary and is rebuilt on every run; your real data is in each post's `snapshots.csv`. Your changes to `index.csv` will disappear, though.

**I opened a snapshots.csv in Excel and saved it. Is my data broken?**
Usually not. See [Excel](#excel) for what the program can still read. If `index.csv` shows a "Cannot read snapshots.csv" message in `last_error` for that post, restore the file from a backup or save it again as CSV (comma separated).

**My laptop was closed for three days. What happens?**
Nothing is checked while it is closed, and the missed hourly checks cannot be taken afterwards. When you open it again, the next run checks every overdue post once. For posts that passed 72 hours meanwhile, that check becomes the final reading with its real time and `late_final = true`.

**The Wi-Fi was down for an hour. Will posts fail?**
No. Network problems on your side are never counted as failures. The program simply tries again on the next run.

**I moved a post folder to another group folder.**
That is fine. The program finds it in its new place, keeps tracking it, and `index.csv` shows the new `group` and `folder`. Its tags and note still come from `urls.txt`.

**I want to change the tags or note of a post.**
Edit its line in `urls.txt` and save. `status` and `search` see the change at once; `index.csv` and `info.txt` are updated on the next run.

**I removed a URL from urls.txt by mistake.**
Its folder and numbers are still there. Paste the line back; the program continues where it stopped (missed checks cannot be taken afterwards).

**I want to find all posts with "sale" in the text.**
Run `ThreadsMonitor search sale`.

**I use the folder on two computers through OneDrive.**
Install the schedule on one of them only. You can still open the files and run `status` or `search` on the other one.

**The program says a post is in more than one folder.**
You (or a backup tool) copied its folder. Delete the extra copy or move it into an archive folder. Until then the post is not checked.

**Can I track a post that was published a week ago?**
Yes, but it gets only one check, which becomes its final numbers, and `added_after_window = true`.

## Troubleshooting

| What you see | What to do |
| --- | --- |
| `Another ThreadsMonitor is already running in this folder. Skipping this run.` | Wait a minute and try again. |
| `This folder is being used by another computer ...` | Run `uninstall-schedule` on one of the two computers. |
| `Cannot write the lock file ...` | The `.lock` file or the folder is read-only. Remove the read-only setting or check the folder permissions. |
| `snapshots.csv is open in another program (Excel?) ...` | Nothing is lost: the new lines were saved to a `snapshots.pending-...csv` file and are added back on the next run. Close the file in Excel. |
| `index.csv is open in another program (Excel?) ...` | Close it in Excel. It is rebuilt on the next run. |
| `urls.txt line N: not a Threads post URL, skipped` | Check that line: it must be a Threads post URL or share link. |
| `urls.txt line N: this line is a comment, so it is not tracked ...` | The line starts with a tag. Move the tags after the URL: `<URL> #tag \| note`. |
| `settings.ini: ignored line ... (use half-width digits 0-9)` or `(use half-width = : , -)` | Type the numbers and the `=` `:` `,` `-` of that line again in half-width. Until then the default is used. |
| `--tag needs a value after it ...` | Write the value right after the option, for example `--tag video`. To search for words starting with `-`, use `search -- <words>`. |
| `Could not resolve share link ...` | Paste the post's own URL (`https://www.threads.com/@account/post/CODE`) instead of the share link. |
| `No network connection ...` | Your computer is offline. Nothing was counted as a failure. |
| A post shows `blocked` errors | Threads is refusing requests for now. The program retries every 15 minutes (see [When checks fail](#when-checks-fail)). |
| A post shows `gone` | The post was deleted or made private. Its last numbers are kept as final. |
| `Some snapshots are dated in the future ...` | Check your computer's date, time and time zone. |
| `Found copies made by a sync program ...` | Copy any extra lines into the original file by hand, then delete the copies. |
| `The folder path is too long ...` | Move the whole tool folder somewhere shorter, for example `D:\Threads`. |
| Nothing happens automatically | Check that the computer was on and awake, and look at `log.txt`. On a Mac, make sure the folder is not in Desktop, Documents or Downloads. |

`log.txt` in the tool's folder records what happened on every run. When it grows past 1 MB, it is renamed to `log.1.txt` and a new one is started.

## Building from source

You need the .NET 10 SDK.

```bash
dotnet test
./build.sh
```

`build.sh` creates `dist/win-x64`, `dist/osx-arm64`, `dist/osx-x64` and `dist/linux-x64`, each with one self-contained program. The program uses no third-party packages; the tests use xUnit.

## Disclaimer

This is an independent, unofficial tool. It has no relationship with Meta Platforms, Inc. or Threads, and is not made, endorsed or supported by them. "Threads" is a trademark of its respective owner. It reads public web pages without logging in, slowly (one request every few seconds), and by default identifies itself as Googlebot. **This kind of use may violate Meta's Terms of Service**, and your IP address may be limited or blocked as a result. You are responsible for using it in line with the Threads terms of service and the laws that apply to you, including the rules about storing other people's content. Numbers are provided as they appear on the public page, without any guarantee of accuracy or completeness. The software is provided "as is", without warranty of any kind.

## License

MIT
