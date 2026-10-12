<?php

namespace App\Livewire;

use App\Enums\UserLevel;
use App\Livewire\Concerns\ValidatesOnBlur;
use App\Models\User;
use App\Notifications\AccountCreatedNotification;
use App\Support\AccountRules;
use App\Support\Modules;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Administrators manage every account here: add (including new
 * administrators), edit primary details and level, switch modules on and off
 * per account, and deactivate. A new account gets one email, on creation,
 * with a link to set its own password.
 */
#[Title('System Users')]
class SystemUsers extends Component
{
    use ValidatesOnBlur, WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $levelFilter = '';

    public bool $showForm = false;

    /** null while adding, the account's id while editing */
    #[Locked]
    public ?int $editingId = null;

    public array $form = [];

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->form = $this->blankForm();
    }

    private function authorizeAdmin(): void
    {
        abort_unless(Auth::user()?->canAccess(Modules::USERS), 403);
    }

    private function blankForm(): array
    {
        return [
            'first_name' => '',
            'middle_name' => '',
            'last_name' => '',
            'email' => '',
            'mobile_number' => '',
            'level' => UserLevel::Client->value,
            'modules' => UserLevel::Client->defaultModules(),
            'is_active' => true,
        ];
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedLevelFilter(): void
    {
        $this->resetPage();
    }

    // Picking a level starts the module boxes from that level's defaults
    public function updatedForm(mixed $value, string $key): void
    {
        if ($key === 'level') {
            $level = UserLevel::tryFrom((string) $value) ?? UserLevel::Client;
            $this->form['modules'] = $level->defaultModules();
        }
    }

    public function create(): void
    {
        $this->resetValidation();
        $this->validFields = [];
        $this->editingId = null;
        $this->form = $this->blankForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $user = User::findOrFail($id);

        $this->resetValidation();
        $this->validFields = [];
        $this->editingId = $user->id;
        $this->form = [
            'first_name' => $user->first_name,
            'middle_name' => (string) $user->middle_name,
            'last_name' => $user->last_name,
            'email' => $user->email,
            'mobile_number' => (string) $user->mobile_number,
            'level' => $user->level->value,
            'modules' => $user->isAdministrator() ? Modules::keys() : ($user->modules ?? []),
            'is_active' => $user->is_active,
        ];
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetValidation();
        $this->validFields = [];
    }

    protected function blurValidated(): array
    {
        return ['form.first_name', 'form.middle_name', 'form.last_name', 'form.email', 'form.mobile_number'];
    }

    protected function rules(): array
    {
        return [
            ...AccountRules::names('form.'),
            'form.email' => AccountRules::email($this->editingId),
            'form.mobile_number' => AccountRules::mobile(),
            'form.level' => ['required', Rule::enum(UserLevel::class)],
            'form.modules' => ['array'],
            'form.modules.*' => [Rule::in(Modules::keys())],
            'form.is_active' => ['boolean'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'form.first_name' => 'first name',
            'form.middle_name' => 'middle name',
            'form.last_name' => 'last name',
            'form.email' => 'email',
            'form.mobile_number' => 'mobile number',
            'form.level' => 'level',
        ];
    }

    public function save(): void
    {
        $this->authorizeAdmin();

        $this->form['email'] = Str::lower(trim((string) $this->form['email']));
        $data = $this->validate()['form'];

        $level = UserLevel::from($data['level']);
        $attributes = [
            'first_name' => $data['first_name'],
            'middle_name' => $data['middle_name'] ?: null,
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'mobile_number' => $data['mobile_number'] ?: null,
            'level' => $level,
            'modules' => Modules::normalize($data['modules'] ?? [], $level),
        ];

        $this->editingId === null
            ? $this->createUser($attributes)
            : $this->updateUser($attributes, (bool) ($data['is_active'] ?? true));
    }

    private function createUser(array $attributes): void
    {
        $admin = Auth::user();

        $user = User::create([
            ...$attributes,
            'is_active' => true,
            // No one knows this password - the welcome email's link sets a real one
            'password' => Str::password(40),
            'created_by' => $admin->id,
        ]);

        // The set-password link can only be used from this inbox, so it
        // confirms the address the same way a verification link would
        $user->markEmailAsVerified();

        try {
            $token = Password::broker()->createToken($user);
            $user->notify(new AccountCreatedNotification($token, $admin->name));
            $this->dispatch('toast', message: "{$user->name} was added. A welcome email has been sent to {$user->email}.");
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', message: "{$user->name} was added, but the welcome email could not be sent. They can use \"Forgot password?\" with their email to set a password.", type: 'error');
        }

        $this->showForm = false;
        $this->form = $this->blankForm();
    }

    private function updateUser(array $attributes, bool $isActive): void
    {
        $user = User::findOrFail($this->editingId);
        $isSelf = $user->id === Auth::id();

        // An administrator can't lock themselves out
        if ($isSelf && $attributes['level'] !== UserLevel::Administrator) {
            $this->addError('form.level', 'You cannot remove your own Administrator level.');

            return;
        }
        if ($isSelf && !$isActive) {
            $this->addError('form.is_active', 'You cannot deactivate your own account.');

            return;
        }

        $emailChanged = $attributes['email'] !== $user->email;

        $user->fill([...$attributes, 'is_active' => $isActive]);

        // A changed address must be re-confirmed by its owner at next sign-in
        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        $this->showForm = false;
        $this->dispatch('toast', message: "{$user->name}'s details were saved.");
    }

    /** The checkbox grid: switch one module on or off for one account. */
    public function toggleModule(int $userId, string $module): void
    {
        $this->authorizeAdmin();

        $user = User::findOrFail($userId);

        if ($user->isAdministrator()) {
            $this->dispatch('toast', message: 'Administrators always have every module.', type: 'info');

            return;
        }
        if (in_array($module, Modules::ADMIN_ONLY, true)) {
            $this->dispatch('toast', message: Modules::label($module) . ' is for administrators only. Change the level to Administrator instead.', type: 'info');

            return;
        }
        if (!array_key_exists($module, Modules::ALL)) {
            return;
        }

        $modules = $user->modules ?? [];
        $modules = in_array($module, $modules, true)
            ? array_values(array_diff($modules, [$module]))
            : [...$modules, $module];

        $user->modules = Modules::normalize($modules, $user->level);
        $user->save();

        $state = $user->canAccess($module) ? 'on' : 'off';
        $this->dispatch('toast', message: Modules::label($module) . " turned {$state} for {$user->name}.");
    }

    public function render()
    {
        $users = User::query()
            ->when($this->search !== '', function ($query) {
                $term = '%' . $this->search . '%';
                $query->where(fn ($q) => $q
                    ->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('middle_name', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->when(UserLevel::tryFrom($this->levelFilter), fn ($query, $level) => $query->where('level', $level))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate(10);

        $counts = User::query()->selectRaw('level, COUNT(*) as total')->groupBy('level')->pluck('total', 'level');

        return view('livewire.system-users', [
            'users' => $users,
            'counts' => $counts,
            'pending' => User::whereNull('email_verified_at')->count(),
            'formLevel' => UserLevel::tryFrom($this->form['level'] ?? '') ?? UserLevel::Client,
        ]);
    }
}
