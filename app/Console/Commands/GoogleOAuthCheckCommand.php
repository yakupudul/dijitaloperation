<?php

namespace App\Console\Commands;

use App\Models\CoreIntegration;
use App\Services\Integrations\Google\GoogleOAuthConfigurationHealth;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Console\Command;

class GoogleOAuthCheckCommand extends Command
{
    protected $signature = 'moxdop:google-oauth:check';

    protected $description = 'Validate Google OAuth application configuration (never prints secrets)';

    public function handle(GoogleOAuthConfigurationHealth $health): int
    {
        $integration = CoreIntegration::query()
            ->where('provider', ProviderRegistry::GOOGLE)
            ->first();

        $report = $health->check($integration);

        $this->info('Google OAuth configuration check');
        $this->line('Overall: '.($report['ok'] ? 'Configured' : 'Missing / incomplete'));

        foreach ($report['checks'] as $check) {
            $this->line(sprintf(
                '- [%s] %s — %s',
                strtoupper($check['status']),
                $check['key'],
                $check['message'],
            ));
        }

        if ($report['redirect_uri'] !== null) {
            $this->line('Redirect URI: '.$report['redirect_uri']);
        }

        $this->line('Ads developer token: '.($report['ads_developer_token_configured'] ? 'Configured' : 'Missing'));
        $this->line('GBP scope enabled: '.($report['gbp_scope_enabled'] ? 'yes' : 'no'));

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
