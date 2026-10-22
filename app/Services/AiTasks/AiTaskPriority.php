<?php

namespace App\Services\AiTasks;

use App\Support\Ai\AiRouteKeys;

/**
 * Order in which Claude works the AI queue (panel audit T4 / D3, 2026-10-21): first the checks the content calendar
 * and the Onarım masası wait on, then conversations with people (WhatsApp, review replies), then bulk work.
 */
final class AiTaskPriority
{
    /** Writing and repair checks: a day's content or desk send waits on them. */
    public const array URGENT = [
        AiRouteKeys::CONTENT_REVIEW,
        AiRouteKeys::REPAIR_REVIEW,
        AiRouteKeys::CONTENT_ARTICLE,
        AiRouteKeys::CONTENT_LOCALIZE,
        AiRouteKeys::SITE_WRITE_ARTICLE,
        AiRouteKeys::SITE_TRANSLATION_FIX,
        AiRouteKeys::SITE_APPLY_CHANGE,
    ];

    /** A person is waiting for an answer. */
    public const array PEOPLE = ['whatsapp.reply', 'whatsapp.brain', AiRouteKeys::GBP_REVIEW_REPLY];

    /** 0 urgent, 1 people, 2 bulk. */
    public static function tier(string $operation): int
    {
        return match (true) {
            in_array($operation, self::URGENT, true) => 0,
            in_array($operation, self::PEOPLE, true) => 1,
            default => 2,
        };
    }

    /** SQL order expression for ai_tasks.operation (urgent first). */
    public static function orderSql(): string
    {
        $quote = fn (array $keys): string => "'".implode("','", array_map(fn (string $key): string => str_replace("'", "''", $key), $keys))."'";

        return 'case when operation in ('.$quote(self::URGENT).') then 0 when operation in ('.$quote(self::PEOPLE).') then 1 else 2 end';
    }
}
