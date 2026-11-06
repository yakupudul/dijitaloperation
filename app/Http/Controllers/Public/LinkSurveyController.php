<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\LinkSurvey;
use App\Services\Site\Backlinks\LinkSurveys;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The client's side of the "Bağlantı kaynakları" form (`/baglanti-kaynaklari/{token}`, no login, not indexed). The form
 * can be answered again at any time; earlier answers are shown filled in.
 */
final class LinkSurveyController extends Controller
{
    public function show(string $token): View
    {
        $survey = $this->survey($token);

        return view('public.link-survey', ['survey' => $survey, 'saved' => session('saved', false)]);
    }

    public function store(Request $request, string $token, LinkSurveys $surveys): RedirectResponse
    {
        $survey = $this->survey($token);
        $request->validate(['answers' => ['array'], 'answers.*' => ['nullable', 'string', 'max:4000'], 'note' => ['nullable', 'string', 'max:2000']]);
        $surveys->answer($survey, (array) $request->input('answers', []), (string) $request->input('note', ''));

        return redirect()->route('link-survey', ['token' => $token])->with('saved', true);
    }

    private function survey(string $token): LinkSurvey
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{48}$/', $token) === 1, 404);

        return LinkSurvey::query()->with('brand:id,name')->where('token', $token)->firstOrFail();
    }
}
