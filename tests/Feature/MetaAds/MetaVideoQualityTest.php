<?php

namespace Tests\Feature\MetaAds;

use App\Services\MetaAds\MetaAdsProfessionalWorkspaceReadService;
use ReflectionMethod;
use Tests\TestCase;

/** Video creative quality: hook rate, thruplay rate, completion and the weak-hook flag. */
final class MetaVideoQualityTest extends TestCase
{
    public function test_hook_thruplay_completion_and_weak_hook_flag(): void
    {
        $service = app(MetaAdsProfessionalWorkspaceReadService::class);
        $method = new ReflectionMethod($service, 'videoQuality');

        $strong = $method->invoke($service, [
            'video_play_actions' => 4000, 'video_thruplay_watched_actions' => 1200, 'video_p100_watched_actions' => 800,
        ], 10000);
        $this->assertSame(40.0, $strong['hook_rate']);
        $this->assertSame(30.0, $strong['thruplay_rate']);
        $this->assertSame(20.0, $strong['completion_rate']);
        $this->assertFalse($strong['weak_hook']);

        // 900 plays over 10000 impressions = 9% hook, above the 200-play reliability floor → weak.
        $weak = $method->invoke($service, ['video_play_actions' => 900, 'video_thruplay_watched_actions' => 100], 10000);
        $this->assertSame(9.0, $weak['hook_rate']);
        $this->assertTrue($weak['weak_hook']);

        // Too few plays to judge: not flagged.
        $tiny = $method->invoke($service, ['video_play_actions' => 50], 10000);
        $this->assertFalse($tiny['weak_hook']);

        // No video data → null.
        $this->assertNull($method->invoke($service, [], 10000));
    }
}
