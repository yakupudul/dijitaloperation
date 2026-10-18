<?php

namespace App\Mcp\Tools;

use App\Models\Brand;
use App\Models\BrandEpicrisisItem;
use App\Services\Epicrisis\Epicrisis;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Writes a fact the OPERATOR told you in chat into the brand\'s epikriz (the record every AI step reads): verified and locked, it wins over every other source of its field. Only what the operator himself said, never your own guess or something read from data. field: a fact field (phone, warranty, material, license, price, expert, process, differentiator…) or a synthesis kind (positioning, voice, say, dont_say…). scope: the service or branch it is about. question_id: when it answers one of get-brand\'s epicrisis.open_questions. replaces_id: the epikriz line it corrects. Write the value in Turkish, plain.')]
class SaveBrandFact extends Tool
{
    public function handle(Request $request): Response
    {
        $fields = [...array_keys((array) config('moxdop-epicrisis.fields')), ...array_keys((array) config('moxdop-epicrisis.synthesis'))];
        $validated = $request->validate([
            'brand_id' => ['required', 'integer'],
            'field' => ['required_without:question_id', 'nullable', Rule::in($fields)],
            'value' => ['required', 'string', 'min:1', 'max:2000'],
            'scope' => ['nullable', 'string', 'max:160'],
            'question_id' => ['nullable', 'integer'],
            'replaces_id' => ['nullable', 'integer'],
        ]);
        $brand = Brand::query()->find($validated['brand_id']);
        if ($brand === null) {
            return Response::error('Marka bulunamadı.');
        }
        $epicrisis = app(Epicrisis::class);
        if (($validated['question_id'] ?? null) !== null) {
            $question = BrandEpicrisisItem::query()->where('brand_id', $brand->id)->where('layer', BrandEpicrisisItem::QUESTION)->where('status', BrandEpicrisisItem::OPEN)->find($validated['question_id']);
            if ($question === null) {
                return Response::error('Soru #'.$validated['question_id'].' bu markada açık değil.');
            }
            $fact = $epicrisis->answer($question, $validated['value'], null, 'chat');

            return Response::text('Soru #'.$question->id.' cevaplandı; bilgi #'.$fact->id.' doğrulandı ve kilitlendi.');
        }
        $replaces = null;
        if (($validated['replaces_id'] ?? null) !== null) {
            $replaces = BrandEpicrisisItem::query()->where('brand_id', $brand->id)->find($validated['replaces_id']);
            if ($replaces === null) {
                return Response::error('Satır #'.$validated['replaces_id'].' bu markada yok.');
            }
        }
        try {
            $item = $epicrisis->record($brand, (string) $validated['field'], $validated['value'], $validated['scope'] ?? null, 'chat', null, $replaces);
        } catch (InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::text('Epikrize yazıldı: #'.$item->id.' '.$item->line().' (doğrulandı, kilitli).');
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        $fields = [...array_keys((array) config('moxdop-epicrisis.fields')), ...array_keys((array) config('moxdop-epicrisis.synthesis'))];

        return [
            'brand_id' => $schema->integer()->description('The brand.')->required(),
            'field' => $schema->string()->enum($fields)->description('The epikriz field or synthesis kind (not needed with question_id).'),
            'value' => $schema->string()->description('What the operator said, plain Turkish.')->required(),
            'scope' => $schema->string()->description('The service or branch it is about (optional).'),
            'question_id' => $schema->integer()->description('The open question this answers (get-brand epicrisis.open_questions).'),
            'replaces_id' => $schema->integer()->description('The epikriz line this corrects.'),
        ];
    }
}
