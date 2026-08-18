<?php

namespace App\Livewire\Demo\Sales;

use App\Enums\IntentSignalStatus;
use App\Models\SalesIntentSignal;
use App\Services\Sales\CreateProspectFromIntentSignalService;
use App\Services\Sales\IntentActivityRecorder;
use App\Support\Demo\DemoState;
use App\Support\Options\AgencyServiceOptions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Intent Signal')]
class IntentSignalShow extends Component
{
    public string $signalId = '';

    public function mount(string $signalId): void
    {
        abort_unless(ctype_digit($signalId), 404);
        abort_if(SalesIntentSignal::query()->find($signalId) === null, 404);
        $this->signalId = $signalId;
    }

    public function dismiss(): void
    {
        $signal = SalesIntentSignal::query()->findOrFail($this->signalId);
        if ($signal->status === IntentSignalStatus::ConvertedToProspect) {
            return;
        }

        $signal->status = IntentSignalStatus::Dismissed;
        $signal->save();
        app(IntentActivityRecorder::class)->record(
            'intent_signal.dismissed',
            __('operator.sales_intent.activity.dismissed'),
            $signal->searchProfile,
            $signal->run,
            $signal,
            actor: auth()->user(),
        );
        DemoState::flash(__('operator.sales_intent.dismissed'));
    }

    public function markReviewed(): void
    {
        $signal = SalesIntentSignal::query()->findOrFail($this->signalId);
        if ($signal->status === IntentSignalStatus::New) {
            $signal->status = IntentSignalStatus::Reviewed;
            $signal->save();
            app(IntentActivityRecorder::class)->record(
                'intent_signal.reviewed',
                __('operator.sales_intent.activity.reviewed'),
                $signal->searchProfile,
                $signal->run,
                $signal,
                actor: auth()->user(),
            );
        }
    }

    public function createProspect(): mixed
    {
        $signal = SalesIntentSignal::query()->findOrFail($this->signalId);
        $prospect = app(CreateProspectFromIntentSignalService::class)->create($signal, auth()->user());
        DemoState::flash(__('operator.sales_intent.prospect_created'));

        return $this->redirect(route('operator.prospect', ['prospectId' => $prospect->id]), navigate: true);
    }

    public function render(): View
    {
        $signal = SalesIntentSignal::query()->with(['searchProfile', 'prospect'])->findOrFail($this->signalId);

        return view('livewire.demo.sales.intent-signal-show', [
            'signal' => $signal,
            'serviceLabel' => AgencyServiceOptions::label($signal->service_definition_code),
        ]);
    }
}
