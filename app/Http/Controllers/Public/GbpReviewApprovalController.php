<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\GbpReviewApproval;
use App\Services\Gbp\Desk\ReviewApprovals;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The brand's side of "Markaya onaya gönder" (`/onay/yorum-yanitlari/{token}`, no login, not indexed): the prepared
 * replies one under the other, each "Uygun" / edit / "Yanıtlamayalım", and a note. What the brand approves goes to Google
 * by itself (ReviewApprovals::answer, yakup 2026-10-26); a standing link (`live`) shows the current drafts each time.
 */
final class GbpReviewApprovalController extends Controller
{
    public function show(string $token, ReviewApprovals $approvals): View
    {
        $approval = $this->approval($token);

        return view('public.gbp-review-approval', ['approval' => $approval, 'items' => $approvals->items($approval), 'usable' => $approval->isUsable(), 'saved' => session('saved', false)]);
    }

    public function store(Request $request, string $token, ReviewApprovals $approvals): RedirectResponse
    {
        $approval = $this->approval($token);
        try {
            $approvals->answer($approval, (array) $request->input('decision', []), (array) $request->input('text', []), (string) $request->input('note', ''));
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors())->withInput();
        }

        return redirect()->route('gbp-review-approval', ['token' => $token])->with('saved', true);
    }

    private function approval(string $token): GbpReviewApproval
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{48}$/', $token) === 1, 404);

        return GbpReviewApproval::query()->with(['brand:id,name', 'digitalAsset:id,name'])->where('token', $token)->firstOrFail();
    }
}
