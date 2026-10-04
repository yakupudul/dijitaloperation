<?php

namespace App\Http\Controllers\Integrations;

use App\Models\CoreIntegration;
use App\Services\WhatsApp\WhatsAppSignup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class WhatsAppSignupController
{
    public function show(Request $request, string $attempt, WhatsAppSignup $signup): Response
    {
        $row = $signup->owned($attempt, $request->user(), $request->session()->getId());
        $integration = CoreIntegration::query()->findOrFail($row->integration_id);
        $payload = $row->status === 'choose_phone' ? ($row->payload ?? []) : [];

        return response()->view('operator.whatsapp.connect', [
            'attempt' => $row->only(['id', 'status', 'mode', 'details', 'expires_at']),
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
            'waba_id' => ['required', 'regex:/^[0-9]{5,40}$/'],
            'phone_number_id' => ['nullable', 'regex:/^[0-9]{5,40}$/'],
            'event' => ['required', 'in:FINISH,FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING'],
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

    public function selectPhone(Request $request, string $attempt, WhatsAppSignup $signup): JsonResponse
    {
        $row = $signup->owned($attempt, $request->user(), $request->session()->getId());
        $phoneId = $request->input('phone_number_id');
        abort_unless(is_string($phoneId) && preg_match('/^[0-9]{5,40}$/', $phoneId), 422);
        $signup->selectPhone($row, $phoneId);

        return response()->json(['queued' => true, 'redirect' => route('operator.whatsapp')]);
    }
}
