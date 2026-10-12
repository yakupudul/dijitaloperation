<?php

namespace App\Mcp\Tools;

use App\Models\Strategy;
use App\Services\Strategy\StrategyBook;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Saves a strategy you wrote for the operator into Stratejiler (the panel menu where he reads every earlier strategy). Without strategy_id it creates one (title and body required); with strategy_id it changes the given scope fields and, when body differs, adds a new version (earlier versions stay). body is the whole strategy in Turkish Markdown (## headings become its table of contents). evidence lists the data it is based on ({label, detail}: which accounts, period, numbers). Saving never writes to an ad account.')]
class SaveStrategy extends Tool
{
    public function handle(Request $request, StrategyBook $book): Response
    {
        $id = $request->get('strategy_id');
        $strategy = $id !== null ? Strategy::query()->find((int) $id) : null;
        if ($id !== null && $strategy === null) {
            return Response::error('Strateji #'.$id.' bulunamadı.');
        }
        $validated = $request->validate(StrategyBook::rules($strategy === null));
        $strategy = $book->save($validated, 'Claude', $strategy);

        return Response::text('Strateji #'.$strategy->id.' kaydedildi (v'.$strategy->current_version.'): '.route('operator.strategy', ['strategyId' => $strategy->id]));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'strategy_id' => $schema->integer()->description('An existing strategy to update; omit to create.'),
            'title' => $schema->string()->description('Short Turkish name, e.g. "OPC Klinik · All-on-4/6 yurt dışı Google Ads".'),
            'brand_id' => $schema->integer()->description('The brand; omit for a system-wide strategy.'),
            'channel' => $schema->string()->enum(array_keys(Strategy::CHANNELS))->description('google_ads | meta | gbp | site | multi'),
            'service' => $schema->string()->description('The service it is for, e.g. "All-on-4 / All-on-6".'),
            'markets' => $schema->array()->items($schema->string())->description('Target countries / cities in Turkish, e.g. ["Birleşik Krallık", "Almanya"].'),
            'languages' => $schema->array()->items($schema->string())->description('Ad languages, e.g. ["İngilizce"].'),
            'budget_amount' => $schema->number()->description('Budget amount.'),
            'budget_currency' => $schema->string()->description('ISO currency, default TRY.'),
            'budget_period' => $schema->string()->enum(array_keys(Strategy::BUDGET_PERIODS))->description('daily | monthly | total'),
            'status' => $schema->string()->enum(array_keys(Strategy::STATUSES))->description('draft (default) | approved | active | archived. Set approved/active only when the operator said so.'),
            'summary' => $schema->string()->description('Two or three Turkish sentences: the goal and the main decision.'),
            'request' => $schema->string()->description("The operator's request in his words."),
            'source_ref' => $schema->string()->description('Where it was made: project thread link or file path.'),
            'body' => $schema->string()->description('The whole strategy as Turkish Markdown.'),
            'change_note' => $schema->string()->description('What changed in this version.'),
            'evidence' => $schema->array()->items($schema->object([
                'label' => $schema->string()->required(),
                'detail' => $schema->string(),
            ]))->description('The data it is based on.'),
        ];
    }
}
