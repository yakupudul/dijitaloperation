<?php

namespace App\Services\Ownership;

use App\Models\OwnershipTransfer;

/**
 * What already owns the thing the operator is trying to connect or move, and where it would go.
 * `sameCustomer` = a move inside one customer (another asset / brand of the same customer); still confirmed by a human.
 */
final readonly class OwnershipConflict
{
    public function __construct(
        public string $subjectType,
        public int $subjectId,
        public string $subjectLabel,
        public ?int $currentCustomerId,
        public ?string $currentCustomerName,
        public ?int $currentBrandId,
        public ?string $currentBrandName,
        public ?int $currentAssetId,
        public ?string $currentAssetName,
        public ?int $targetCustomerId,
        public ?string $targetCustomerName,
        public ?int $targetBrandId,
        public ?string $targetBrandName,
        public ?int $targetAssetId,
        public ?string $targetAssetName,
        public bool $sameCustomer,
        public ?int $currentBindingId = null,
    ) {}

    public function isResource(): bool
    {
        return $this->subjectType === OwnershipTransfer::SUBJECT_RESOURCE;
    }

    /** "Bu hesap şu an **Adadent** müşterisinin **Adadent Web Sitesi** varlığına bağlı." */
    public function message(): string
    {
        if ($this->isResource()) {
            $owner = $this->currentCustomerName !== null
                ? sprintf('**%s** müşterisinin **%s** varlığına bağlı', $this->currentCustomerName, (string) $this->currentAssetName)
                : sprintf('markaya bağlı olmayan **%s** varlığına bağlı', (string) $this->currentAssetName);

            return sprintf('Bu hesap (%s) şu an %s.', $this->subjectLabel, $owner);
        }

        return sprintf(
            'Bu varlık (%s) şu an **%s** müşterisinin **%s** markasına ait.',
            $this->subjectLabel,
            (string) $this->currentCustomerName,
            (string) $this->currentBrandName,
        );
    }

    /** Plain-text message (no markdown), e.g. for validation errors. */
    public function plainMessage(): string
    {
        return str_replace('**', '', $this->message());
    }

    /** Error for flows that were not confirmed: names the owner and asks for the confirmation. */
    public function errorMessage(): string
    {
        return $this->plainMessage().' Devretmek için onaylayın.';
    }

    /** Message for automatic flows (Otomatik kur, Toplu ekle): skipped, never moved. */
    public function skippedMessage(): string
    {
        return $this->plainMessage().($this->sameCustomer
            ? ' Aynı müşterinin başka varlığında olduğu için atlandı; taşımak için Veri kaynaklarından devredebilirsiniz.'
            : ' Başka müşteriye ait olduğu için atlandı; gerekiyorsa Veri kaynaklarından devredebilirsiniz.');
    }

    public function fromLabel(): string
    {
        return $this->path($this->currentCustomerName, $this->currentBrandName, $this->isResource() ? $this->currentAssetName : null) ?: 'markasız';
    }

    public function toLabel(): string
    {
        return $this->path($this->targetCustomerName, $this->targetBrandName, $this->isResource() ? $this->targetAssetName : null) ?: '—';
    }

    /**
     * What happens after the transfer, in plain Turkish.
     *
     * @return list<string>
     */
    public function consequences(): array
    {
        if ($this->isResource()) {
            return [
                sprintf('%s bu hesabın verisini artık almaz; hesap %s varlığına geçer.', $this->currentAssetName ?? 'Eski varlık', $this->targetAssetName ?? 'yeni'),
                'Daha önce toplanmış veriler eski varlıkta kalır; yeni varlık bundan sonraki çekimleri alır.',
                'Eski varlıktaki açık işler, görevler ve öneriler eski varlıkta kalır.',
                'Eski müşterinin raporları geçmiş aylarını korur.',
            ];
        }

        return [
            sprintf('Varlık, bağlı hesapları ve toplanmış verisiyle birlikte %s markasına geçer.', $this->targetBrandName ?? 'yeni'),
            sprintf('%s müşterisi bu varlığı artık görmez; yeni çekimler %s adına yapılır.', $this->currentCustomerName ?? 'Eski müşteri', $this->targetCustomerName ?? 'yeni müşteri'),
            'Daha önce oluşturulmuş raporlar ve kapanmış işler değişmez.',
        ];
    }

    /** @return array<string, mixed> Livewire-safe array (public properties must be plain data). */
    public function toArray(): array
    {
        return [
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'subject_label' => $this->subjectLabel,
            'current_customer_id' => $this->currentCustomerId,
            'current_customer_name' => $this->currentCustomerName,
            'current_brand_id' => $this->currentBrandId,
            'current_brand_name' => $this->currentBrandName,
            'current_asset_id' => $this->currentAssetId,
            'current_asset_name' => $this->currentAssetName,
            'target_customer_id' => $this->targetCustomerId,
            'target_customer_name' => $this->targetCustomerName,
            'target_brand_id' => $this->targetBrandId,
            'target_brand_name' => $this->targetBrandName,
            'target_asset_id' => $this->targetAssetId,
            'target_asset_name' => $this->targetAssetName,
            'same_customer' => $this->sameCustomer,
            'current_binding_id' => $this->currentBindingId,
            'message' => $this->message(),
            'plain_message' => $this->plainMessage(),
            'from_label' => $this->fromLabel(),
            'to_label' => $this->toLabel(),
            'consequences' => $this->consequences(),
        ];
    }

    /** @return array<string, string|null> names kept on the transfer record */
    public function snapshot(): array
    {
        return [
            'subject' => $this->subjectLabel,
            'from_customer' => $this->currentCustomerName,
            'from_brand' => $this->currentBrandName,
            'from_asset' => $this->isResource() ? $this->currentAssetName : null,
            'to_customer' => $this->targetCustomerName,
            'to_brand' => $this->targetBrandName,
            'to_asset' => $this->isResource() ? $this->targetAssetName : null,
        ];
    }

    private function path(?string ...$parts): string
    {
        return collect($parts)->filter(fn (?string $part): bool => $part !== null && $part !== '')->implode(' › ');
    }
}
