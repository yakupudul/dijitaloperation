<?php

namespace App\Http\Controllers\Integrations;

use App\Models\CoreIntegration;
use App\Services\WhatsApp\WhatsAppSignup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class WhatsAppSignupController
{
    public function show(Request $request, string $attempt, WhatsAppSignup $signup): Response
    {
        try {
            $row = $signup->owned($attempt, $request->user(), $request->session()->getId());
        } catch (HttpException $exception) {
            // Started in another session (re-login, another browser): back to the WhatsApp screen with the reason.
            if ($exception->getStatusCode() !== 403) {
                throw $exception;
            }

            return redirect()->route('operator.whatsapp')->with('whatsapp_notice', $exception->getMessage());
        }
        $integration = CoreIntegration::query()->findOrFail($row->integration_id);
        $payload = $row->status === 'choose_phone' ? ($row->payload ?? []) : [];

        return response()->view('operator.whatsapp.connect', [
            'attempt' => $row->only(['id', 'status', 'mode', 'details', 'expires_at', 'launched_at']),
            'phones' => $payload['phones'] ?? [],
            'appId' => (string) data_get($integration->config, 'app_id'),
            'configId' => (string) data_get($integration->config, 'signup_config_id'),
            'graphVersion' => (string) config('whatsapp.graph_version', 'v23.0'),
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function complete(Request $request, string $attempt, WhatsAppSignup $signup): JsonResponse
    {
        $row = $signup->owned($attempt, $request->user(), $request->session()->getId());
        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:4096'],
            'event' => ['required', 'in:FINISH,FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING,FINISH_ONLY_WABA,CODE_ONLY'],
            'waba_id' => ['nullable', 'required_unless:event,CODE_ONLY', 'regex:/^[0-9]{5,40}$/'],
            'phone_number_id' => ['nullable', 'regex:/^[0-9]{5,40}$/'],
            'finish_event' => ['nullable', 'in:FINISH,FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING,FINISH_ONLY_WABA'],
        ]);
        // Do not flash OAuth codes into the session on a malformed browser request.
        if ($validator->fails()) {
            return response()->json(['message' => 'Meta eksik veya geçersiz bağlantı bilgisi döndürdü. Bağlantıyı yeniden başlatın.'], 422);
        }
        try {
            $signup->submit($row, $validator->validated());
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'Bağlantı tamamlanamadı.', 'errors' => $exception->errors()], 422);
        }

        return response()->json(['queued' => true, 'redirect' => route('operator.whatsapp')]);
    }

    /**
     * What the connect page saw: along the way (popup opened, Facebook's answer, Meta events, a failed request, the
     * page left; also sent as a beacon) or an ending without a result (where it stopped or the error Meta showed).
     */
    public function report(Request $request, string $attempt, WhatsAppSignup $signup): JsonResponse
    {
        $row = $signup->owned($attempt, $request->user(), $request->session()->getId());
        $data = $request->validate([
            'event' => ['required', 'in:'.implode(',', [...WhatsAppSignup::TRACE_EVENTS, ...WhatsAppSignup::END_EVENTS])],
            'note' => ['nullable', 'string', 'max:300'],
            'current_step' => ['nullable', 'string', 'max:100'],
            'error_message' => ['nullable', 'string', 'max:500'],
            'error_id' => ['nullable', 'string', 'max:100'],
            'session_id' => ['nullable', 'string', 'max:200'],
        ]);
        if (! $signup->report($row, $data)) {
            return response()->json(['saved' => false, 'message' => 'Bu bağlantı oturumu artık geçerli değil (süresi doldu ya da yeni bir deneme başlatıldı). WhatsApp ekranından yeniden başlatın.'], 409);
        }

        return response()->json(['saved' => true]);
    }

    public function selectPhone(Request $request, string $attempt, WhatsAppSignup $signup): JsonResponse
    {
        $row = $signup->owned($attempt, $request->user(), $request->session()->getId());
        $phoneId = $request->input('phone_number_id');
        abort_unless(is_string($phoneId) && preg_match('/^[0-9]{5,40}$/', $phoneId), 422);
        $signup->selectPhone($row, $phoneId);

        return response()->json(['queued' => true, 'redirect' => route('operator.whatsapp')]);
    }
}
