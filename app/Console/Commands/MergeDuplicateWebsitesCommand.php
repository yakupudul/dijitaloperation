<?php

namespace App\Console\Commands;

use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Ownership\WebsiteDuplicateMerger;
use App\Support\Roles;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * moxdop:websites:merge-duplicates — lists duplicate website assets (same host) with a dry-run preview (default).
 * --apply merges same-customer groups into the suggested keeper; cross-customer groups are only listed, they need an
 * Admin's confirmation in Entegrasyonlar › Kopya web siteleri.
 */
final class MergeDuplicateWebsitesCommand extends Command
{
    protected $signature = 'moxdop:websites:merge-duplicates
        {--dry-run : Only show what would be merged (default)}
        {--apply : Merge same-customer groups into the suggested keeper}
        {--by= : Admin user id or e-mail recorded as the merger (default: first active Admin)}';

    protected $description = 'Find and merge duplicate website assets that share a domain.';

    public function handle(WebsiteDuplicateMerger $merger): int
    {
        $apply = (bool) $this->option('apply') && ! (bool) $this->option('dry-run');
        $groups = $merger->findGroups();
        if ($groups === []) {
            $this->info('Kopya web sitesi yok.');

            return self::SUCCESS;
        }

        $admin = null;
        if ($apply) {
            $admin = $this->admin();
            if (! $admin instanceof User) {
                $this->error('Birleştirmeyi kaydedecek aktif bir Admin bulunamadı (--by).');

                return self::FAILURE;
            }
        }

        $merged = 0;
        $needsConfirmation = 0;
        foreach ($groups as $group) {
            $keeperId = (int) $group['suggested_keeper_id'];
            $this->line(sprintf('<info>%s</info> — %d kayıt, tutulacak: #%d%s', $group['host'], count($group['assets']), $keeperId, $group['cross_customer'] ? ' <comment>(farklı müşteriler)</comment>' : ''));
            foreach ($group['assets'] as $row) {
                $this->line(sprintf(
                    '  %s #%d %s — %s; %d bağlantı, %d sayfa, %d veri, %d SEO görevi, %d site düzeltmesi%s',
                    $row['id'] === $keeperId ? '*' : '-',
                    $row['id'],
                    $row['name'],
                    trim(($row['customer'] ?? 'markasız').' › '.($row['brand'] ?? '—')),
                    $row['counts']['bindings'],
                    $row['counts']['pages'],
                    $row['counts']['facts'],
                    $row['connector'] !== null ? ', WordPress bağlayıcı: '.$row['connector'] : '',
                ));
            }

            if ($group['cross_customer']) {
                $needsConfirmation++;
                $this->warn('  Farklı müşteriler: yetki devri gerekir; Entegrasyonlar › Kopya web siteleri ekranından Admin onayıyla birleştirin.');

                continue;
            }

            $keeper = DigitalAsset::query()->findOrFail($keeperId);
            foreach ($group['assets'] as $row) {
                if ($row['id'] === $keeperId) {
                    continue;
                }
                $duplicate = DigitalAsset::query()->findOrFail($row['id']);
                try {
                    if (! $apply) {
                        $plan = $merger->plan($keeper, $duplicate);
                        $this->line(sprintf('  #%d → #%d: %d satır taşınır, %d çakışma (tutulanınki kalır).', $duplicate->id, $keeper->id, $plan['move_total'], $plan['collision_total']));

                        continue;
                    }
                    $merge = $merger->merge($keeper, $duplicate, $admin);
                    $merged++;
                    $this->line(sprintf('  #%d → #%d birleştirildi: %d satır taşındı, %d çakışan satır bırakıldı.', $duplicate->id, $keeper->id, $merge->movedTotal(), $merge->droppedTotal()));
                } catch (ValidationException $exception) {
                    $this->error(sprintf('  #%d → #%d: %s', $duplicate->id, $keeper->id, (string) collect($exception->errors())->flatten()->first()));
                }
            }
        }

        $this->newLine();
        $this->line($apply
            ? sprintf('%d kopya birleştirildi; %d grup Admin onayı bekliyor.', $merged, $needsConfirmation)
            : sprintf('Deneme (dry run): hiçbir şey değişmedi. %d grup, %d tanesi Admin onayı gerektiriyor. Uygulamak için --apply.', count($groups), $needsConfirmation));

        return self::SUCCESS;
    }

    private function admin(): ?User
    {
        $by = trim((string) $this->option('by'));
        $query = User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', Roles::ADMIN));
        if ($by !== '') {
            $query->where(fn ($q) => ctype_digit($by) ? $q->whereKey((int) $by) : $q->where('email', $by));
        }

        return $query->orderBy('id')->first();
    }
}
