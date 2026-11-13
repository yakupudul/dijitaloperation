<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Social\ThreadsApi;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "Threads'i bağla" on Sosyal › Hesaplar: start the Threads sign-in and take its answer back. */
class ThreadsOAuthController extends Controller
{
    public function authorize(Request $request, ThreadsApi $threads): RedirectResponse
    {
        $this->admin($request);
        $result = $threads->begin((int) $request->user()->id);
        if (isset($result['error'])) {
            ThreadsApi::rememberAttempt(false, 'Bağlantı başlatılamadı: '.$result['error']);
            DemoState::flash('Threads bağlantısı başlatılamadı: '.$result['error'], 'error');

            return redirect()->route('operator.social', ['sekme' => 'hesaplar']);
        }

        return redirect()->away($result['url']);
    }

    public function callback(Request $request, ThreadsApi $threads): RedirectResponse
    {
        $this->admin($request);
        $result = $threads->handleCallback(
            $request->query('code'), $request->query('state'), $request->query('error'), $request->query('error_description'),
            (int) $request->user()->id,
        );
        $text = isset($result['error'])
            ? 'Threads bağlantısı kurulamadı: '.$result['error']
            : 'Threads bağlandı: '.$result['name'].'. Blog yazıları artık kısa metin olarak Threads’te de paylaşılır.';
        ThreadsApi::rememberAttempt(! isset($result['error']), $text);
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
