<?php

namespace App\Livewire\Concerns;

use App\Services\Ai\Insights\AiInsightService;
use App\Support\Demo\DemoState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * On-click AI insight buttons (`<x-operator.ai-insight>`). The page decides which subject a click may act on.
 */
trait WithAiInsights
{
    /** The subject this page allows for the insight kind, or null. */
    abstract protected function insightSubject(string $kind, int $subjectId): ?Model;

    public function runInsight(string $kind, int $subjectId): void
    {
        $subject = $this->insightSubject($kind, $subjectId);
        if ($subject === null) {
            DemoState::flash('Bu kayıt için AI çalıştırılamaz.', 'error');

            return;
        }
        try {
            app(AiInsightService::class)->queue($kind, $subject);
            DemoState::flash('AI çalışıyor; birkaç saniye sonra sonuç burada görünür.', 'info');
        } catch (ValidationException $exception) {
            DemoState::flash((string) collect($exception->errors())->flatten()->first(), 'error');
        }
    }

    /** @return array<string, mixed> */
    protected function insightView(string $kind, Model $subject): array
    {
        return app(AiInsightService::class)->view($kind, $subject);
    }
}
