<?php

namespace App\Services\WebPush;

use App\Models\Profile;

class EventSupport
{
    public static function normalizeActorUsername(Profile $actor): ?string
    {
        $username = $actor->username;
        $remote = $actor->domain !== null;
        if ($remote && is_string($username) && str_starts_with($username, '@')) {
            // Imported profiles store @name@domain. Remove exactly one marker;
            // a second leading @ must still fail validation below.
            $username = substr($username, 1);
        }
        // Helpers::extractUsername permits remote names beginning with . or -.
        // Keep the local rule unchanged and require an alphanumeric remote name
        // after removing its permitted punctuation, as the importer does.
        $pattern = $remote
            ? '/\A[A-Za-z0-9_.-][A-Za-z0-9_.@-]*\z/D'
            : '/\A[A-Za-z0-9_][A-Za-z0-9_.@-]*\z/D';
        if (! is_string($username) || strlen($username) > 255
            || ! preg_match($pattern, $username)
            || ($remote && ! ctype_alnum(str_replace(['_', '.', '-'], '', explode('@', $username)[0])))) {
            return null;
        }

        return $username;
    }
}
