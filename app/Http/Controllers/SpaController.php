<?php

namespace App\Http\Controllers;

use App\Services\AccountService;
use App\Services\StatusService;
use App\Util\Localization\Localization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use League\CommonMark\CommonMarkConverter;

class SpaController extends Controller
{
    public function index(Request $req): RedirectResponse|View
    {
        abort_unless(config('exp.spa'), 404);
        if (! $req->user()) {
            return redirect('/login');
        }

        return view('layouts.spa');
    }

    public function webPost(Request $request, $id): RedirectResponse|View
    {
        abort_unless(config('exp.spa'), 404);
        abort_unless(is_string($id) && preg_match('/\A[0-9]+\z/D', $id), 404);
        if ($request->user()) {
            return view('layouts.spa');
        }

        $post = StatusService::get($id, false);

        if ($post && ! in_array($post['visibility'], ['public', 'unlisted'])) {
            return redirect('/login');
        }

        if (
            $post &&
            isset($post['account']['username']) &&
            is_string($post['account']['username']) &&
            preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.-]*\z/D', $post['account']['username']) &&
            isset($post['local']) &&
            $post['local'] === true
        ) {
            return redirect('/p/'.$post['account']['username'].'/'.$id);
        }

        return redirect('/login');
    }

    public function webProfile(Request $request, $id): RedirectResponse|View
    {
        abort_unless(config('exp.spa'), 404);
        if ($request->user()) {
            if (str_starts_with($id, '@')) {
                $id = AccountService::usernameToId(substr($id, 1));

                return redirect("/i/web/profile/{$id}");
            }

            return view('layouts.spa');
        }

        $account = AccountService::get($id);

        if ($account && ! empty($account['local'])
            && is_string($account['username'] ?? null)
            && preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.-]*\z/D', $account['username'])) {
            // Account URLs may contain remote data; this route must never
            // redirect off-origin, including when the browser session expired.
            return redirect('/'.$account['username']);
        }

        return redirect('/login');
    }

    public function updateLanguage(Request $request): array
    {
        abort_unless(config('exp.spa'), 404);
        abort_unless($request->user(), 404);
        $this->validate($request, [
            'v' => 'required|in:0.1,0.2',
            'l' => 'required|alpha_dash|max:12',
        ]);

        $lang = $request->input('l');
        $user = $request->user();

        abort_if(! in_array($lang, Localization::languages()), 400);

        $user->language = $lang;
        $user->save();
        session()->put('locale', $lang);

        return ['language' => $lang];
    }

    public function getPrivacy(Request $request): array
    {
        abort_unless($request->user(), 404);
        $body = $this->markdownToHtml('views/page/privacy.md');

        return [
            'body' => $body,
        ];
    }

    public function getTerms(Request $request): array
    {
        abort_unless($request->user(), 404);
        $body = $this->markdownToHtml('views/page/terms.md');

        return [
            'body' => $body,
        ];
    }

    protected function markdownToHtml($src, $ttl = 600)
    {
        return Cache::remember(
            'pf:doc_cache:markdown:'.$src,
            $ttl,
            function () use ($src) {
                $path = resource_path($src);
                $file = file_get_contents($path);
                $converter = new CommonMarkConverter;

                return (string) $converter->convertToHtml($file);
            });
    }

    public function usernameRedirect(Request $request, $username): RedirectResponse
    {
        abort_unless($request->user(), 404);
        $id = AccountService::usernameToId($username);
        if (! $id) {
            return redirect('/i/web/404');
        }

        return redirect('/i/web/profile/'.$id);
    }

    public function hashtagRedirect(Request $request, $tag): RedirectResponse|View
    {
        if (! $request->user()) {
            return redirect('/discover/tags/'.$tag);
        }

        return view('layouts.spa');
    }
}
