<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Models\TrackedPost;
use App\Models\TrackedPostUser;
use App\Support\InvalidInput;
use App\Support\UserMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Tags on one post in the current user's own list (contract K3).
 *
 * Input is a comma-separated string of names; the result replaces the post's whole tag set.
 * Names are resolved only against the user's own tags (case-insensitive, first spelling kept),
 * so another account's tag can never be attached — no tag ids are accepted from the request.
 */
class TagController extends Controller
{
    public function update(Request $request, TrackedPost $trackedPost): RedirectResponse
    {
        $user = $request->user();
        $entry = TrackedPostUser::forUserOrFail($user, $trackedPost);

        $input = $request->input('tags', '');
        if (! is_string($input) && $input !== null) {
            return back()->withErrors(['tags' => __('posts.tags.invalid_format')], 'tags');
        }
        $input = (string) $input;

        try {
            $names = Tag::parseList($input);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['tags' => UserMessage::of($e)], 'tags')->withInput(['tags' => mb_substr($input, 0, 2000, 'UTF-8')]);
        }

        try {
            DB::transaction(fn () => Tag::syncForEntry($user, $entry, $names));
        } catch (InvalidInput $e) {
            return back()->withErrors(['tags' => UserMessage::of($e)], 'tags')->withInput(['tags' => mb_substr($input, 0, 2000, 'UTF-8')]);
        }

        return redirect()->route('posts.show', $trackedPost)->with('status', $names === [] ? __('posts.flash.tags_cleared') : __('posts.flash.tags_updated'));
    }
}
