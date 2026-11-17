<?php

namespace App\Mcp\Tools;

use App\Models\Brand;
use App\Services\Measurement\MeasurementSetup;
use App\Support\EmergencyStop;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Name('measurement-setup')]
#[Description(<<<'TEXT'
Ölçüm kurulumu (yakup 2026-11-13: every current and future brand site gets the same measurement; Google Ads writes are allowed for conversion setup ONLY, never campaigns, budgets, bids, keywords or ads). The standard: GA4 property + web stream + key events phone_click, whatsapp_click, generate_lead (14-month retention, Ads link); Google Ads WEBPAGE conversion actions "MoxDOP · Telefon / WhatsApp / Form" (secondary when a working primary action of that category exists); a GTM web container with the Google tag, tel: and WhatsApp link-click triggers, a form listener (CF7, Elementor, WPForms, Fluent, Gravity), GA4 event + Ads conversion tags, published; the site added to Search Console.
- action=status (brand_id): website, bound GA4 / Search Console / Ads, GTM / G- / AW- ids on the home page, Ads conversion actions, GA4 streams and key events, missing permissions and the connect link.
- action=accounts: GA4 accounts with properties, GTM accounts with containers (gtm_account = only that account's containers; containers=false = GTM account list only). Tag Manager allows ~15 queries a minute: GTM calls wait their turn and lists are kept 30 minutes. A call stops after ~40 s with paused=true (apply) or gtm_error (accounts) instead of timing out; wait a minute and send the SAME call again, it goes on where it stopped (create-only, publishes the leftover changes). Give gtm_account / container_id to apply to save calls.
- action=apply (brand_id; ga4_account "accounts/N" when the brand has no GA4 property, or property_id; gtm_account when a new container is needed, or container_id; skip_ads; events = only some of phone_click / whatsapp_click / generate_lead; key_events=false when GA4 already has its own key events for them; allow_existing; publish default true): creates only what is missing (by name), never deletes or edits a working conversion; it STOPS and reports when the property or container already has its own conversion events or tags, unless allow_existing=true (then judge double counting first).
- action=verify (brand_id): Search Console verification by the Tag Manager (then Analytics) method, once the container is on the site.
- action=fix_conversion_ids (gtm_account, container_id; dry_run; publish default true): repairs Google Ads conversion tags (awct) whose Conversion ID was typed as "AW-123…" (Google Ads never counts those hits): only that field becomes the bare number, then the container is versioned and published. dry_run=true only lists the broken tags. Nothing else in a tag is changed.
- action=primary_for_goal (brand_id, names, primary; dry_run): makes the named Google Ads conversion actions primary or secondary, only that setting, only after the operator approved it (todos ask). Run with dry_run=true first. Refused when no enabled primary action would be left. It never creates, deletes or renames an action. Google's own actions (Yerel işlemler, Akıllı kampanya) change at goal level (via_goal in the answer): every enabled action of that goal must be in names.
Putting the container on a WordPress site is a site-tool step (GTM4WP plugin from WordPress.org + its option, or the theme). A brandless site gets nothing. Health sites: no tracking of health data in event parameters.
TEXT)]
class MeasurementSetupTool extends Tool
{
    public function __construct(private readonly MeasurementSetup $setup) {}

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'action' => ['required', 'in:status,accounts,apply,verify,fix_conversion_ids,primary_for_goal'],
            'brand_id' => ['nullable', 'integer'],
            'ga4_account' => ['nullable', 'string', 'max:40'],
            'property_id' => ['nullable', 'string', 'max:40'],
            'gtm_account' => ['nullable', 'string', 'max:40'],
            'container_id' => ['nullable', 'string', 'max:40'],
            'skip_ads' => ['nullable', 'boolean'],
            'allow_existing' => ['nullable', 'boolean'],
            'publish' => ['nullable', 'boolean'],
            'events' => ['nullable', 'array'],
            'key_events' => ['nullable', 'boolean'],
            'containers' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
            'events.*' => ['string', 'in:phone_click,whatsapp_click,generate_lead'],
            'names' => ['nullable', 'array', 'max:20'],
            'names.*' => ['string', 'max:200'],
            'primary' => ['nullable', 'boolean'],
        ]);
        $action = (string) $validated['action'];
        if (in_array($action, ['apply', 'verify', 'fix_conversion_ids', 'primary_for_goal'], true) && EmergencyStop::active()) {
            return Response::error(EmergencyStop::REASON);
        }
        try {
            if ($action === 'accounts') {
                return Response::json($this->setup->accounts(array_filter($validated, fn ($v): bool => $v !== null)));
            }
            if ($action === 'fix_conversion_ids') {
                $result = $this->setup->fixConversionIds(array_filter($validated, fn ($v): bool => $v !== null));
                Log::info('Ölçüm onarımı: dönüşüm kimliği', ['container_id' => $validated['container_id'] ?? null, 'fixed' => $result['fixed'] ?? null, 'dry_run' => $result['dry_run'] ?? null]);

                return Response::json($result);
            }
            $brand = Brand::query()->find((int) ($validated['brand_id'] ?? 0));
            if (! $brand instanceof Brand) {
                return Response::error('brand_id gerekli (list-brands).');
            }
            $result = match ($action) {
                'status' => $this->setup->status($brand),
                'apply' => $this->setup->apply($brand, array_filter($validated, fn ($v): bool => $v !== null)),
                'verify' => $this->setup->verify($brand),
                'primary_for_goal' => $this->setup->primaryForGoal($brand, array_filter($validated, fn ($v): bool => $v !== null)),
            };
            if ($action !== 'status') {
                Log::info('Ölçüm kurulumu', ['brand_id' => $brand->id, 'action' => $action, 'changed' => $result['changed'] ?? null, 'created' => $result['created'] ?? null, 'verified' => $result['verified'] ?? null]);
            }

            return Response::json($result);
        } catch (Throwable $e) {
            return Response::error($e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['status', 'accounts', 'apply', 'verify', 'fix_conversion_ids', 'primary_for_goal'])->description('status | accounts | apply | verify | fix_conversion_ids | primary_for_goal')->required(),
            'brand_id' => $schema->integer()->description('Brand id from list-brands (status, apply, verify).'),
            'ga4_account' => $schema->string()->description('apply: GA4 account "accounts/N" for a new property.'),
            'property_id' => $schema->string()->description('apply: an existing GA4 property to use.'),
            'gtm_account' => $schema->string()->description('apply: GTM account id for a new container (also limits the container search to it). accounts: only this account\'s containers.'),
            'containers' => $schema->boolean()->description('accounts: false = GTM accounts without their containers (default true).'),
            'container_id' => $schema->string()->description('apply: an existing GTM container id to use. fix_conversion_ids: the container to repair.'),
            'dry_run' => $schema->boolean()->description('fix_conversion_ids: only list the broken tags. primary_for_goal: only report what would change.'),
            'names' => $schema->array()->items($schema->string())->description('primary_for_goal: exact names of the conversion actions (from status → ads_conversions).'),
            'primary' => $schema->boolean()->description('primary_for_goal: true = count as primary (bidding uses it), false = secondary (only reported).'),
            'skip_ads' => $schema->boolean()->description('apply: leave Google Ads out.'),
            'allow_existing' => $schema->boolean()->description('apply: add the standard although the property / container already measures conversions.'),
            'publish' => $schema->boolean()->description('apply: publish the GTM version (default true).'),
            'key_events' => $schema->boolean()->description('apply: false = do not mark our events as GA4 key events (the property already counts them; avoids double counting in GA4).'),
            'events' => $schema->array()->items($schema->string()->enum(['phone_click', 'whatsapp_click', 'generate_lead']))->description('apply: only these parts of the standard (e.g. a site that already counts WhatsApp gets phone_click + generate_lead).'),
        ];
    }
}
