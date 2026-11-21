<?php

namespace App\Livewire\Panel;

use App\Panel\Models\PanelMembership;
use App\Panel\Models\PanelPerson;
use App\Panel\Models\PanelPreset;
use App\Panel\Services\PanelLeads;
use App\Panel\Services\PanelPresets;
use App\Panel\Support\PanelPage;
use App\Panel\Support\PanelSources;
use App\Panel\Support\PanelStages;
use App\Panel\Support\PhoneKey;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Adaylar: every person of the brand as a table or as a board by stage; the user picks the columns they need. */
#[Layout('panel.layouts.app')]
#[Title('Adaylar')]
final class LeadsPage extends Component
{
    use PanelPage;
    use WithPagination;

    public const array COLUMNS = [
        'phone' => 'Telefon', 'stage' => 'Aşama', 'source' => 'Kaynak', 'interest' => 'İlgilendiği', 'assignee' => 'İlgilenen',
        'last_activity' => 'Son hareket', 'city' => 'Şehir', 'age' => 'Yaş', 'gender' => 'Cinsiyet', 'email' => 'E-posta',
        'appointment' => 'Randevu', 'created' => 'Kayıt',
    ];

    public const array DEFAULT_COLUMNS = ['phone', 'stage', 'source', 'interest', 'assignee', 'last_activity'];

    #[Url(as: 'gorunum')]
    public string $view = 'table';

    #[Url(as: 'ara')]
    public string $search = '';

    #[Url(as: 'asama')]
    public string $stage = '';

    #[Url(as: 'kaynak')]
    public string $source = '';

    #[Url(as: 'kisi')]
    public string $assignee = '';

    /** @var list<string> */
    public array $columns = [];

    public bool $adding = false;

    /** @var array<string, mixed> */
    public array $form = ['name' => '', 'phone' => '', 'source' => 'call', 'interest' => '', 'message' => ''];

    public ?int $losing = null;

    public string $reason = '';

    protected function permission(): string
    {
        return 'menu.leads';
    }

    public function mount(): void
    {
        $saved = (array) $this->membership()->preference('lead_columns', self::DEFAULT_COLUMNS);
        $this->columns = array_values(array_intersect(array_keys(self::COLUMNS), $saved)) ?: self::DEFAULT_COLUMNS;
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['search', 'stage', 'source', 'assignee'], true)) {
            $this->resetPage();
        }
    }

    /** The user's own column choice, kept for their next visit. */
    public function updatedColumns(): void
    {
        $this->columns = array_values(array_intersect(array_keys(self::COLUMNS), $this->columns));
        $membership = PanelMembership::query()->findOrFail($this->membership()->id);
        $membership->forceFill(['preferences' => ['lead_columns' => $this->columns] + (array) $membership->preferences])->save();
    }

    /** Puts a topic on the brand's list so nobody types it again, and picks it on the open form. */
    public function addInterest(string $label, PanelPresets $presets): void
    {
        abort_unless($this->may('leads.create'), 403);
        if (trim($label) === '') {
            return;
        }
        $this->form['interest'] = $presets->add(PanelPreset::INTEREST, $label, null, (int) $this->panelUser()->id)->label;
        $this->notify('“'.$this->form['interest'].'” listeye eklendi.');
    }

    /** Writes a saved note template into the note of the open form. */
    public function useTemplate(string $id): void
    {
        $template = ctype_digit($id) ? PanelPreset::query()->where('kind', PanelPreset::NOTE)->find((int) $id) : null;
        if ($template !== null) {
            $this->form['message'] = trim(trim((string) $this->form['message'])."\n".(string) ($template->body ?: $template->label));
        }
    }

    public function add(PanelLeads $leads, bool $again = false): void
    {
        abort_unless($this->may('leads.create'), 403);
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:190'],
            'form.phone' => ['required', 'string', 'max:40', fn (string $attribute, mixed $value, \Closure $fail) => PhoneKey::of((string) $value) === null ? $fail('Telefon numarası eksik görünüyor.') : null],
            'form.source' => ['required', Rule::in(array_keys(PanelSources::labels()))],
            'form.interest' => ['nullable', 'string', 'max:190'],
            'form.message' => ['nullable', 'string', 'max:2000'],
        ], [], ['form.name' => 'ad', 'form.phone' => 'telefon', 'form.source' => 'kaynak', 'form.interest' => 'ilgilendiği', 'form.message' => 'not'])['form'];
        $known = PanelPerson::query()->where('phone_key', PhoneKey::of($data['phone']))->exists();
        $person = $leads->record((int) $this->panel()->brandId(), $data + ['assigned_user_id' => $this->may('leads.all') ? null : $this->panelUser()->id], $data['source'], (int) $this->panelUser()->id);
        $this->adding = $again;
        $this->form = ['name' => '', 'phone' => '', 'source' => $again ? $data['source'] : 'call', 'interest' => '', 'message' => ''];
        $this->notify($known ? 'Bu numara zaten kayıtlıydı; yeni talep '.$person->name.' kaydına eklendi.' : $person->name.' adaylara eklendi.');
    }

    public function move(int $personId, string $stage, PanelLeads $leads): void
    {
        abort_unless($this->may('leads.stage'), 403);
        abort_unless(array_key_exists($stage, PanelStages::labels()), 422);
        $person = $leads->visible()->whereKey($personId)->firstOrFail();
        if ($stage === PanelStages::LOST) {
            $this->losing = $personId;
            $this->reason = '';

            return;
        }
        $leads->setStage($person, $stage, null, (int) $this->panelUser()->id);
        $this->notify($person->name.': '.PanelStages::label($stage));
    }

    public function lose(PanelLeads $leads): void
    {
        abort_unless($this->may('leads.stage'), 403);
        $this->validate(['reason' => ['required', Rule::in(array_keys(PanelStages::lostReasons()))]], ['reason.required' => 'Bir neden seçin.'], ['reason' => 'neden']);
        $person = $leads->visible()->whereKey((int) $this->losing)->firstOrFail();
        $leads->setStage($person, PanelStages::LOST, $this->reason, (int) $this->panelUser()->id);
        $this->losing = null;
        $this->notify($person->name.': Olumsuz');
    }

    private function filtered(PanelLeads $leads): Builder
    {
        $query = $leads->visible()->with('assignee:id,name');
        $term = trim($this->search);
        if ($term !== '') {
            $like = '%'.mb_strtolower($term).'%';
            $key = $this->may('leads.phone') ? preg_replace('/\D+/', '', $term) : '';
            $query->where(function (Builder $q) use ($like, $key): void {
                $q->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(coalesce(interest, \'\')) like ?', [$like]);
                if (strlen((string) $key) >= 3) {
                    $q->orWhere('phone_key', 'like', '%'.ltrim((string) $key, '0').'%');
                }
            });
        }
        if (array_key_exists($this->stage, PanelStages::labels())) {
            $query->where('stage', $this->stage);
        }
        if (array_key_exists($this->source, PanelSources::labels())) {
            $query->where('source', $this->source);
        }
        if ($this->assignee === 'none') {
            $query->whereNull('assigned_user_id');
        } elseif (ctype_digit($this->assignee)) {
            $query->where('assigned_user_id', (int) $this->assignee);
        }

        return $query;
    }

    public function render(PanelLeads $leads, PanelPresets $presets): View
    {
        $board = $this->view === 'board';
        $counts = $leads->visible()->selectRaw('stage, count(*) as n')->groupBy('stage')->pluck('n', 'stage');
        $lanes = [];
        if ($board) {
            foreach ([...PanelStages::open(), PanelStages::CUSTOMER, PanelStages::LOST] as $stage) {
                $lanes[$stage] = (clone $this->filtered($leads))->where('stage', $stage)->orderByDesc('last_activity_at')->limit(40)->get();
            }
        }

        return view('livewire.panel.leads', [
            'people' => $board ? null : $this->filtered($leads)->orderByDesc('last_activity_at')->orderByDesc('id')->paginate(25),
            'lanes' => $lanes,
            'counts' => $counts,
            'total' => (int) $counts->sum(),
            'team' => $this->may('leads.all') ? PanelMembership::query()->with('user:id,name')->where('brand_id', $this->panel()->brandId())->where('is_active', true)->get()->pluck('user.name', 'panel_user_id') : collect(),
            'showPhone' => $this->may('leads.phone'),
            'canAdd' => $this->may('leads.create'),
            'canImport' => $this->may('import.leads'),
            'canMove' => $this->may('leads.stage'),
            'isManager' => $this->panel()->isManager(),
            'interests' => $this->adding ? $presets->interests() : collect(),
            'templates' => $this->adding ? $presets->of(PanelPreset::NOTE) : collect(),
        ]);
    }
}
