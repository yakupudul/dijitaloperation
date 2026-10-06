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
 * replies one under the other, each "Uygun" / edit / "Yanıtlamayalım", and a note. Nothing is published from here.
 */
final class GbpReviewApprovalController extends Controller
{
    public function show(string $token): View
    {
        $approval = $this->approval($token);

        return view('public.gbp-review-approval', ['approval' => $approval, 'usable' => $approval->isUsable(), 'saved' => session('saved', false)]);
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

        return GbpReviewApproval::query()->with('brand:id,name')->where('token', $token)->firstOrFail();
    }
}
