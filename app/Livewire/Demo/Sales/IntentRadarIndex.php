<?php

namespace App\Livewire\Demo\Sales;

use App\Enums\IntentPurchaseStage;
use App\Enums\IntentSignalStatus;
use App\Models\SalesIntentSignal;
use App\Services\Sales\DataForSeoIntentSearchAdapter;
use App\Support\Options\AgencyServiceOptions;
use App\Support\Sales\IntentSearchConfig;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Intent Radar')]
class IntentRadarIndex extends Component
{
    #[Url]
    public string $status = 'new';

    public function render(DataForSeoIntentSearchAdapter $adapter): View
    {
        $query = SalesIntentSignal::query()
            ->with('searchProfile')
            ->orderByRaw('case when purchase_stage = ? then 0 else 1 end', [IntentPurchaseStage::HighIntent->value])
            ->orderByDesc('intent_confidence')
            ->orderByDesc('discovered_at');

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        $rows = $query->limit(100)->get()->map(fn (SalesIntentSignal $signal): array => [
            'id' => (string) $signal->id,
            'source_title' => $signal->source_title ?: $signal->source_url,
            'source_url' => $signal->source_url,
            'snippet' => $signal->observed_snippet,
            'service' => AgencyServiceOptions::label($signal->service_definition_code),
            'intent_confidence' => $signal->intent_confidence,
            'identity_confidence' => $signal->identity_confidence,
            'identity_status' => $signal->identity_status->value,
            'purchase_stage' => $signal->purchase_stage?->value,
            'verification' => $signal->source_verification_state->value,
            'status' => $signal->status->value,
            'discovered_at' => $signal->discovered_at?->diffForHumans(),
            'reason' => $signal->classification_reason,
        ])->all();

        $reality = $adapter->reality();
        $fixturesEnabled = IntentSearchConfig::fixturesEnabled();
        $paidCallsEnabled = IntentSearchConfig::paidCallsEnabled();

        return view('livewire.demo.sales.intent-radar-index', [
            'rows' => $rows,
            'engine' => [
                'provider' => 'DataForSEO',
                'endpoint' => $adapter->endpoint(),
                'reality' => $fixturesEnabled ? 'fixture' : $reality['reality'],
                'configured' => $reality['configured'],
                'paid_calls_enabled' => $paidCallsEnabled,
                'fixtures_enabled' => $fixturesEnabled,
                'ready' => ! $fixturesEnabled && $reality['reality'] === 'real',
                'message' => $fixturesEnabled
                    ? 'Fixture mode is enabled. Results are not live market evidence.'
                    : ($reality['message'] ?? null),
            ],
            'statusOptions' => [
                '' => __('operator.forms.status'),
                IntentSignalStatus::New->value => __('operator.sales_intent.signal_statuses.new'),
                IntentSignalStatus::Reviewed->value => __('operator.sales_intent.signal_statuses.reviewed'),
                IntentSignalStatus::ConvertedToProspect->value => __('operator.sales_intent.signal_statuses.converted_to_prospect'),
                IntentSignalStatus::Dismissed->value => __('operator.sales_intent.signal_statuses.dismissed'),
            ],
        ]);
    }
}
