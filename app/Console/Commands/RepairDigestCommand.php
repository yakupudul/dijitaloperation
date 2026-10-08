<?php

namespace App\Console\Commands;

use App\Services\Assistant\PushNotifier;
use App\Services\Repair\RepairDesk;
use Illuminate\Console\Command;

/**
 * moxdop:repair:digest — one morning notification: "Onarım masası: N iş onayını bekliyor".
 */
final class RepairDigestCommand extends Command
{
    protected $signature = 'moxdop:repair:digest';

    protected $description = 'Notify the operator how many prepared fixes wait for approval.';

    public function handle(RepairDesk $desk, PushNotifier $push): int
    {
        $counts = $desk->counts();
        if ($counts['total'] === 0) {
            $this->line('Onay bekleyen iş yok.');

            return self::SUCCESS;
        }
        $kinds = collect($counts['kinds'])->sortDesc()->map(fn (int $n, string $kind): string => $n.' '.mb_strtolower(RepairDesk::KINDS[$kind] ?? $kind))->take(3)->implode(', ');
        $push->send('repair-desk-digest:'.now()->format('Ymd'), 'Onarım masası: '.$counts['total'].' iş onayını bekliyor', $kinds, 'high', route('operator.repair'), 20);
        $this->line($counts['total'].' iş onay bekliyor: '.$kinds);

        return self::SUCCESS;
    }
}
