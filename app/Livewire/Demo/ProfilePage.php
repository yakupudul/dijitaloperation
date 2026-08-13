<?php

namespace App\Livewire\Demo;

use App\Models\User;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('operator.layouts.app')]
#[Title('Profile')]
class ProfilePage extends Component
{
    use WithFileUploads;

    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $locale = 'en';

    public string $timezone = '';

    public mixed $avatar = null;

    public bool $removeAvatar = false;

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $this->name = (string) $user->name;
        $this->email = (string) $user->email;
        $this->locale = in_array((string) $user->locale, ['en', 'tr'], true) ? (string) $user->locale : 'en';
        $this->timezone = (string) ($user->timezone ?? '');
    }

    public function save(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'locale' => ['required', Rule::in(['en', 'tr'])],
            'timezone' => ['nullable', 'string', 'max:64'],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:2048'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ];

        $validated = $this->validate($rules);

        $user->name = $validated['name'];
        $user->email = $validated['email'];
        $user->locale = $validated['locale'];
        $user->timezone = $validated['timezone'] !== '' ? $validated['timezone'] : null;

        if (! empty($validated['password'])) {
            $user->password = $validated['password'];
        }

        if ($this->removeAvatar && $user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->avatar_path = null;
        }

        if ($this->avatar !== null) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            $path = $this->avatar->store('avatars', 'public');
            $user->avatar_path = $path;
        }

        $user->save();

        $this->avatar = null;
        $this->removeAvatar = false;
        $this->password = '';
        $this->password_confirmation = '';

        app()->setLocale($user->locale);

        DemoState::flash(__('operator.profile.saved'));
    }

    public function markAvatarForRemoval(): void
    {
        $this->removeAvatar = true;
        $this->avatar = null;
    }

    public function render(): View
    {
        /** @var User $user */
        $user = auth()->user();

        return view('livewire.demo.profile', [
            'user' => $user,
            'avatarUrl' => $user->avatar_path && ! $this->removeAvatar
                ? Storage::disk('public')->url($user->avatar_path)
                : null,
            'flash' => DemoState::pullFlash(),
            'timezones' => timezone_identifiers_list(),
        ]);
    }
}
