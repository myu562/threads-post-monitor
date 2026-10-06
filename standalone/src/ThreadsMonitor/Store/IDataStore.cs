using ThreadsMonitor.Core;

namespace ThreadsMonitor.Store;

/// <summary>
/// A post as found by the store. Its identity is the shortcode stored in its data, never the
/// name or location of anything on disk. <paramref name="InputKeys"/> are the input keys
/// (see InputKey) of the urls.txt lines that led to this post. <paramref name="Problem"/> is a
/// message for the user (unreadable data, conflict copies), shown in index.csv.
/// </summary>
public sealed record StoredPost(
    string Shortcode,
    PostMeta Meta,
    IReadOnlyList<Snapshot> Snapshots,
    IReadOnlyList<string> InputKeys,
    string? Group,
    string Location,
    bool ReadOnly,
    bool Archived,
    string? Problem = null);

/// <summary>
/// One line of the index to write. <paramref name="Note"/> is the program's status note;
/// <see cref="Tags"/> and <see cref="UserNote"/> come from urls.txt (empty when the post is not in it).
/// </summary>
public sealed record IndexEntry(StoredPost Post, PostState State, string? Note)
{
    public IReadOnlyList<string> Tags { get; init; } = [];
    public string? UserNote { get; init; }

    /// <summary>The group heading above the post's line in urls.txt (null when none or not listed).</summary>
    public string? InputGroup { get; init; }
}

/// <summary>
/// Persistence for tracking data. Scheduling, fetching and parsing never touch file paths or the
/// on-disk format; they only go through this interface. FolderStore is the current layout.
/// </summary>
public interface IDataStore
{
    /// <summary>Finds every post with its metadata and snapshots (including ones waiting in pending files).</summary>
    IReadOnlyList<StoredPost> LoadPosts();

    /// <summary>
    /// Moves snapshots saved while a data file was locked back into it. Idempotent and never throws
    /// for file problems (they are logged). Returns how many files were merged.
    /// </summary>
    int MergePending();

    /// <summary>
    /// Why a snapshot of this post could not be saved right now (null when it can), checked before
    /// fetching so nothing is fetched in vain: e.g. the folder path would be too long, or the post's
    /// folder exists but its data cannot be read. Remembered for the rest of the run.
    /// </summary>
    string? CannotStore(string shortcode, PostMeta meta, string? group);

    /// <summary>
    /// Saves one snapshot with the post's metadata. A post that does not exist yet is created
    /// (in <paramref name="group"/>, or the default group). Must never lose the snapshot: falls back to
    /// a pending file when the data file is locked. Throws for read-only (archived) posts.
    /// </summary>
    void AppendSnapshot(Snapshot snapshot, PostMeta meta, string? inputKey, string? group);

    /// <summary>Data files that were locked during this run, so rows went to pending files instead.</summary>
    IReadOnlyList<string> PendingWrites { get; }

    /// <summary>Messages for the user found while loading (sync conflict copies, unreadable folders).</summary>
    IReadOnlyList<string> Warnings { get; }

    /// <summary>
    /// Rewrites the derived files (the index, and the per-post info cards of posts changed in this run).
    /// Best effort: returns false when the index could not be written (locked); nothing is lost.
    /// </summary>
    bool WriteIndex(IReadOnlyList<IndexEntry> entries, DateTimeOffset now);
}
