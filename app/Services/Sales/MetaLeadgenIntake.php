<?php

namespace App\Services\Sales;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads Meta Lead Ads leads (webhook change "leadgen") from the Graph API with the page access token and puts
 * them into the agency lead inbox with source `meta_lead_ad`. Field names of the form are mapped to the inbox
 * fields (full_name / phone_number / email / company_name / anything else as the message).
 */
final class MetaLeadgenIntake
{
    public function __construct(private readonly AgencyLeadInbox $inbox) {}

    /** @param  array<string, mixed>  $payload */
    public function handle(array $payload): int
    {
        $received = 0;
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) data_get($entry, 'changes', []) as $change) {
                $leadId = (string) data_get($change, 'value.leadgen_id', '');
                if (data_get($change, 'field') !== 'leadgen' || $leadId === '' || ! ctype_digit($leadId)) {
                    continue;
                }
                try {
                    $lead = $this->fetch($leadId);
                } catch (Throwable $exception) {
                    report($exception);

                    continue;
                }
                if ($lead !== null) {
                    // Meta delivers a leadgen webhook at-least-once; the leadgen_id keys the inbox row so redeliveries never duplicate a lead.
                    $this->inbox->receive($this->fields($lead) + ['utm_source' => 'meta_lead_ad', 'utm_campaign' => (string) ($lead['ad_name'] ?? $lead['campaign_name'] ?? '')], 'meta_lead_ad', 'meta_leadgen:'.$leadId);
                    $received++;
                }
            }
        }

        return $received;
    }

    /** @return array<string, mixed>|null */
    private function fetch(string $leadId): ?array
    {
        $token = (string) config('moxdop-leads.meta.page_access_token', '');
        if ($token === '') {
            return null;
        }
        $response = Http::timeout(20)->get('https://graph.facebook.com/'.config('moxdop-leads.meta.graph_version', 'v23.0').'/'.$leadId, [
            'fields' => 'field_data,created_time,ad_name,campaign_name,form_id', 'access_token' => $token,
        ]);

        return $response->successful() ? (array) $response->json() : null;
    }

    /**
     * @param  array<string, mixed>  $lead
     * @return array<string, string>
     */
    private function fields(array $lead): array
    {
        $map = ['full_name' => 'name', 'first_name' => 'name', 'phone_number' => 'phone', 'email' => 'email', 'company_name' => 'company'];
        $out = [];
        $rest = [];
        foreach ((array) ($lead['field_data'] ?? []) as $field) {
            $name = (string) ($field['name'] ?? '');
            $value = trim((string) (((array) ($field['values'] ?? []))[0] ?? ''));
            if ($value === '') {
                continue;
            }
            if (isset($map[$name]) && ! isset($out[$map[$name]])) {
                $out[$map[$name]] = $value;
            } else {
                $rest[] = $name.': '.$value;
            }
        }
        if ($rest !== []) {
            $out['message'] = implode("\n", $rest);
        }

        return $out;
    }
}
