<?php

namespace App\Mcp\Tools;

use App\Services\Operations\AutoDeployStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

/**
 * "Oraya elle yazabilmek" (yakup 2026-11-08): Claude asks the automatic deploy to check now instead of waiting for the
 * quarter hour. Nothing is skipped: the newest watched commit still has to pass the whole test suite, the backup gate
 * and the screen check, and a failure puts the live release back. It never runs a command of its own on the server.
 */
#[Description('Asks the automatic deploy to check the watched branches now (within a minute) instead of at the next quarter hour. The newest pushed commit still has to pass the full test suite, the backup gate and the screen check; a failed deploy goes back to the live release. Use it right after pushing a fix the operator is waiting for; read system-health → auto_deploy afterwards. A check already running finishes first and the request waits for it.')]
class DeployNow extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        $status = AutoDeployStatus::current();
        if ($status === null) {
            return Response::error('Otomatik deploy bu sunucuda kurulu değil.');
        }
        if ($status['state'] === 'paused') {
            return Response::error('Otomatik deploy durdurulmuş (storage/app/auto-deploy.off); operatör açmadan yayın yapılmaz.');
        }
        if (! AutoDeployStatus::requestNow($validated['reason'])) {
            return Response::error('İstek kaydedilemedi (storage/app yazılamıyor).');
        }

        return Response::json([
            'requested' => true,
            'note' => $status['state'] === 'testing' || $status['state'] === 'deploying'
                ? 'Şu an '.$status['sha'].' için kontrol sürüyor; o bitince en geç 1 dakika içinde en yeni commit denenir.'
                : 'En geç 1 dakika içinde en yeni commit test edilip yayınlanır (testler geçerse).',
            'auto_deploy' => $status,
        ]);
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'reason' => $schema->string()->description('Why now, in Turkish (shown in the logs), e.g. "WhatsApp bağlantı düzeltmesi".')->required(),
        ];
    }
}
