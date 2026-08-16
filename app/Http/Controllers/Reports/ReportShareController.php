<?php

namespace App\Http\Controllers\Reports;

use App\Enums\ReportShareAccessEventType;
use App\Http\Controllers\Controller;
use App\Models\ReportShareGrant;
use App\Models\ReportShareSession;
use App\Models\ReportSnapshot;
use App\Services\ReportDelivery\GenerateReportPdfService;
use App\Services\ReportDelivery\ReportShareService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * External authenticated report share (Prompt 60).
 * Locator token locates grant only — email verification required.
 */
final class ReportShareController extends Controller
{
    public function __construct(
        private readonly ReportShareService $shares,
        private readonly GenerateReportPdfService $pdfs,
    ) {}

    public function locator(Request $request, string $token)
    {
        try {
            $grant = $this->shares->resolveGrantByLocator($token);
        } catch (ValidationException) {
            return response()->view('reports.share-unavailable', [], 404)
                ->withHeaders($this->securityHeaders());
        }

        $request->session()->put('report_share_locator', $token);

        return redirect()
            ->route('reports.share.verify.form')
            ->withHeaders($this->securityHeaders());
    }

    public function verifyForm(Request $request)
    {
        $token = (string) $request->session()->get('report_share_locator', '');
        try {
            $grant = $this->shares->resolveGrantByLocator($token);
        } catch (ValidationException) {
            return response()->view('reports.share-unavailable', [], 404)
                ->withHeaders($this->securityHeaders());
        }

        return response()
            ->view('reports.share-verify', [
                'maskedEmail' => $this->shares->maskEmail((string) $grant->recipient_email),
                'brandName' => $grant->reportSnapshot?->brand_name_snapshot,
            ])
            ->withHeaders($this->securityHeaders());
    }

    public function requestCode(Request $request)
    {
        $token = (string) $request->session()->get('report_share_locator', '');
        try {
            $grant = $this->shares->resolveGrantByLocator($token);
            $this->shares->requestVerification($grant, $request->ip(), $request->userAgent());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withHeaders($this->securityHeaders());
        }

        return back()->with('status', 'verification_sent')->withHeaders($this->securityHeaders());
    }

    public function verify(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'size:6']]);
        $token = (string) $request->session()->get('report_share_locator', '');

        try {
            $grant = $this->shares->resolveGrantByLocator($token);
            $result = $this->shares->verifyCode($grant, (string) $request->input('code'), $request->ip(), $request->userAgent());
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withHeaders($this->securityHeaders());
        }

        $cookieName = (string) config('report_delivery.share.cookie', 'moxdop_report_share_session');
        $request->session()->forget('report_share_locator');
        $request->session()->regenerate();

        return redirect()
            ->route('reports.share.view')
            ->withCookie(cookie(
                $cookieName,
                $result['session_token'],
                (int) config('report_delivery.share.session_ttl_minutes', 60),
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax',
            ))
            ->withHeaders($this->securityHeaders());
    }

    public function view(Request $request)
    {
        try {
            [$grant, $session] = $this->authenticatedGrant($request);
            $snapshot = ReportSnapshot::query()->findOrFail($grant->report_snapshot_id);
            if (! $grant->allowsHtml()) {
                throw ValidationException::withMessages(['share' => 'SHARE_PERMISSION_DENIED']);
            }
            $this->shares->audit($grant, ReportShareAccessEventType::ReportViewed, (int) $session->id, $request->ip(), $request->userAgent());
        } catch (ValidationException) {
            return response()->view('reports.share-unavailable', [], 403)
                ->withHeaders($this->securityHeaders());
        }

        return response()
            ->view('reports.share-view', [
                'snapshot' => $snapshot,
                'content' => $snapshot->content_payload,
                'allowsPdf' => $grant->allowsPdf(),
            ])
            ->withHeaders($this->securityHeaders());
    }

    public function downloadPdf(Request $request): Response|StreamedResponse
    {
        try {
            [$grant, $session] = $this->authenticatedGrant($request);
            if (! $grant->allowsPdf()) {
                throw ValidationException::withMessages(['share' => 'SHARE_PERMISSION_DENIED']);
            }
            $snapshot = ReportSnapshot::query()->findOrFail($grant->report_snapshot_id);
            $artifact = $this->pdfs->generate($snapshot, null, 'share:'.$grant->id.':pdf');
            $bytes = $this->pdfs->streamBytes($artifact);
            $this->shares->audit($grant, ReportShareAccessEventType::PdfDownloaded, (int) $session->id, $request->ip(), $request->userAgent());
        } catch (ValidationException) {
            return response()->view('reports.share-unavailable', [], 403)
                ->withHeaders($this->securityHeaders());
        }

        $filename = $this->safeFilename($snapshot);

        return response($bytes, 200, array_merge($this->securityHeaders(), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]));
    }

    /**
     * @return array{0: ReportShareGrant, 1: ReportShareSession}
     */
    private function authenticatedGrant(Request $request): array
    {
        $cookieName = (string) config('report_delivery.share.cookie', 'moxdop_report_share_session');
        $raw = (string) $request->cookie($cookieName, '');
        if ($raw === '') {
            throw ValidationException::withMessages(['session' => 'SHARE_SESSION_INVALID']);
        }
        $session = $this->shares->resolveSession($raw);
        $grant = ReportShareGrant::query()->find($session->share_grant_id);
        if ($grant === null || ! $grant->isActive()) {
            throw ValidationException::withMessages(['session' => 'SHARE_SESSION_INVALID']);
        }

        return [$grant, $session];
    }

    private function safeFilename(ReportSnapshot $snapshot): string
    {
        $brand = preg_replace('/[^a-zA-Z0-9\-]+/', '-', strtolower((string) $snapshot->brand_name_snapshot)) ?: 'brand';
        $period = $snapshot->period_start?->format('Y-m') ?? 'report';

        return trim($brand, '-').'-report-'.$period.'.pdf';
    }

    /**
     * @return array<string, string>
     */
    private function securityHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'",
            'X-Robots-Tag' => 'noindex, nofollow',
        ];
    }
}
