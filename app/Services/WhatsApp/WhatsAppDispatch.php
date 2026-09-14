<?php

namespace App\Services\WhatsApp;

use App\Jobs\WhatsApp\GenerateWhatsAppSuggestion;
use App\Jobs\WhatsApp\ProcessWhatsAppReceipt;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppWebhookReceipt;

final class WhatsAppDispatch
{
    public function tick(): void
    {
        $integration = app(WhatsAppConnection::class)->integration();
        // Recover interrupted signup work and remove short-lived encrypted OAuth material.
        \App\Models\WhatsAppSignupAttempt::query()->whereIn('status', ['prepared', 'choose_phone', 'queued'])
            ->where('expires_at', '<', now())->update(['status' => 'expired', 'payload' => null, 'updated_at' => now()]);
        \App\Models\WhatsAppSignupAttempt::query()->where('status', 'running')->where('updated_at', '<', now()->subMinutes(3))
            ->update(['status' => 'interrupted', 'payload' => null, 'updated_at' => now()]);
        foreach (\App\Models\WhatsAppSignupAttempt::query()->where('status', 'queued')->limit(5)->get() as $attempt) {
            app(WhatsAppSignup::class)->dispatch($attempt);
        }
        if (! $integration?->isActive()) {
            return;
        }
        foreach (WhatsAppWebhookReceipt::query()->where('integration_id', $integration->id)
            ->where('status', 'pending')->orderBy('id')->limit(20)->pluck('id') as $id) {
            ProcessWhatsAppReceipt::dispatch((int) $id);
        }
        // Do not draft against a conversation while a history batch is still waiting for ingestion.
        if (WhatsAppWebhookReceipt::query()->where('integration_id', $integration->id)->where('status', 'pending')->exists()) {
            return;
        }
        WhatsAppConversation::query()->where('integration_id', $integration->id)->where('suggestion_status', 'running')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->update(['suggestion_status' => 'failed', 'error_code' => 'worker_interrupted', 'updated_at' => now()]);
        $states = data_get($integration->config, 'automatic_suggestions', false) ? ['pending', 'requested'] : ['requested'];
        foreach (WhatsAppConversation::query()->where('integration_id', $integration->id)
            ->whereIn('suggestion_status', $states)->where('updated_at', '<', now()->subSeconds(15))
            ->orderBy('updated_at')->limit(10)->pluck('id') as $id) {
            GenerateWhatsAppSuggestion::dispatch((int) $id);
        }
    }
}

