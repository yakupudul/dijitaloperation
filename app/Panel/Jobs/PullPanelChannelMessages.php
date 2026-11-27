<?php

namespace App\Panel\Jobs;

use App\Panel\Models\PanelChannel;
use App\Panel\Services\PanelChannels;
use App\Panel\Support\PanelContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Brings an Instagram account's or Facebook page's conversations into the brand's Gelen kutusu (all of them, or the newest). */
class PullPanelChannelMessages implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 900;

    public int $timeout = 120;

    public int $tries = 2;

    public function __construct(public int $channelId, public bool $all = false)
    {
        $this->timeout = $all ? 600 : 120;
        $this->onConnection('redis')->onQueue($all ? 'heavy' : 'default');
    }

    public function uniqueId(): string
    {
        return 'panel-pull:'.$this->channelId.':'.($this->all ? 'all' : 'new');
    }

    public function handle(PanelChannels $channels, PanelContext $context): void
    {
        $channel = $context->system(fn (): ?PanelChannel => PanelChannel::query()->find($this->channelId));
        if ($channel !== null) {
            $channels->pull($channel, $this->all);
        }
    }
}
