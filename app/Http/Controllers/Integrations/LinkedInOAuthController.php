<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Social\LinkedInConnector;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "LinkedIn'i bağla" on Sosyal › Hesaplar: start the LinkedIn sign-in and take its answer back. */
class LinkedInOAuthController extends Controller
{
    public function authorize(Request $request, LinkedInConnector $connector): RedirectResponse
    {
        $this->admin($request);
        $result = $connector->begin((int) $request->user()->id);
        if (isset($result['error'])) {
            LinkedInConnector::rememberAttempt(false, 'Bağlantı başlatılamadı: '.$result['error']);
            DemoState::flash('LinkedIn bağlantısı başlatılamadı: '.$result['error'], 'error');

            return redirect()->route('operator.social', ['sekme' => 'hesaplar']);
        }

        return redirect()->away($result['url']);
    }

    public function callback(Request $request, LinkedInConnector $connector): RedirectResponse
    {
        $this->admin($request);
        $result = $connector->handleCallback(
            $request->query('code'), $request->query('state'), $request->query('error'), $request->query('error_description'),
            (int) $request->user()->id,
        );
        $text = isset($result['error'])
            ? 'LinkedIn bağlantısı kurulamadı: '.$result['error']
            : 'LinkedIn bağlandı: '.$result['page'].'. LinkedIn metni olan onaylı gönderiler artık saatinde kendiliğinden paylaşılır.';
        LinkedInConnector::rememberAttempt(! isset($result['error']), $text);
        DemoState::flash($text, isset($result['error']) ? 'error' : 'success');

        return redirect()->route('operator.social', ['sekme' => 'hesaplar']);
    }

    private function admin(Request $request): void
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->hasRole(Roles::ADMIN)) {
            abort(403);
        }
    }
}
