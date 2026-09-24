<?php

namespace App\Livewire\Operator\Settings;

use App\Services\Brain\MethodLibrary;
use App\Services\Brain\RuleEffectiveness;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ayarlar › Yöntem Kütüphanesi: rules (on/off, how often they helped when done) and every threshold / word
 * list the rule engines use, editable with the file default shown. Admin edits; everyone can read.
 */
#[Layout('operator.layouts.app')]
#[Title('Yöntem Kütüphanesi')]
final class MethodLibraryPage extends Component
{
    /** Known advisor rules per channel (SEO rules are listed from what the plans produced). */
    public const array ADVISOR_RULES = [
        'google_ads' => ['negative-keywords', 'ngram-waste', 'service-terms-not-converting', 'keyword-opportunities', 'budget-limited-profitable', 'budget-waste', 'auto-tagging-off', 'no-primary-conversion', 'primary-no-signal', 'conversion-settings', 'ga4-mismatch', 'landing-page-issues', 'landing-keyword-mismatch', 'weak-ad-strength', 'missing-assets', 'google-recommendations', 'low-quality-score', 'quality-score-drop', 'performance-anomaly', 'change-impact'],
        'meta_ads' => ['creative-fatigue', 'audience-saturation', 'learning-limited', 'spend-no-results', 'pixel-health', 'delivery-outliers', 'landing-page-issues', 'change-impact'],
        'google_business_profile' => ['profile-closed', 'profile-gaps', 'keyword-service-gaps', 'site-profile-services', 'profile-actions-drop', 'rating-trend', 'photo-freshness', 'website-utm'],
        'cross' => ['ads-term-no-organic-page', 'paid-brand-search', 'gbp-search-no-site-content', 'nap-phone-mismatch', 'gbp-website-mismatch', 'ads-landing-offsite', 'meta-destination-offsite'],
    ];

    #[Url]
    public string $tab = 'rules';

    /** @var array<string, string> md5(config key) => edited value */
    public array $values = [];

    public string $message = '';

    public string $error = '';

    public function save(string $key, MethodLibrary $library): void
    {
        $this->admin();
        $this->error = '';
        try {
            $library->set($key, $this->values[md5($key)] ?? '', auth()->id());
            $this->message = $key.' kaydedildi; sonraki plandan itibaren geçerli.';
        } catch (InvalidArgumentException $exception) {
            $this->error = $exception->getMessage();
        }
    }

    public function resetKey(string $key, MethodLibrary $library): void
    {
        $this->admin();
        $library->reset($key);
        unset($this->values[md5($key)]);
        $this->message = $key.' varsayılana döndü.';
    }

    public function toggleRule(string $scope, string $ruleId, MethodLibrary $library): void
    {
        $this->admin();
        $enabled = in_array($ruleId, $library->disabledRules($scope), true);
        $library->setRuleEnabled($scope, $ruleId, $enabled, auth()->id());
        $this->message = $ruleId.($enabled ? ' açıldı.' : ' kapatıldı; açık önerileri sonraki planda kapanır.');
    }

    public function render(MethodLibrary $library, RuleEffectiveness $effectiveness): View
    {
        $catalog = $library->catalog();
        foreach ($catalog as $file) {
            foreach ($file['sections'] as $rows) {
                foreach ($rows as $row) {
                    // Keyed by md5: config keys contain dots, which Livewire would read as nested paths.
                    $this->values[md5($row['key'])] ??= is_array($row['value']) ? implode("\n", $row['value']) : (string) $row['value'];
                }
            }
        }
        $seoRules = DB::table('seo_tasks')->distinct()->orderBy('rule_id')->pluck('rule_id')->merge($library->disabledRules('seo'))->unique()->values()->all();

        return view('livewire.operator.settings.method-library', [
            'catalog' => $catalog,
            'advisorRules' => self::ADVISOR_RULES,
            'seoRules' => $seoRules,
            'disabledAdvisor' => $library->disabledRules('advisor'),
            'disabledSeo' => $library->disabledRules('seo'),
            'stats' => $effectiveness->all(),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }

    private function admin(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
