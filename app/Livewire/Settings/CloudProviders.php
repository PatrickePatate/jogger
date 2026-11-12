<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Domain\Access\Audit;
use App\Domain\Cloud\CloudManager;
use App\Domain\Cloud\Enums\CloudHostState;
use App\Domain\Cloud\Exceptions\CloudException;
use App\Domain\Cloud\Models\CloudProvider;
use App\Domain\Cloud\Models\HostTemplate;
use App\Domain\Cloud\TemplateRules;
use App\Domain\Hosts\Models\Host;
use App\Domain\Pools\Models\RunnerPool;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Cloud accounts and the server templates Jogger creates from them. Administrators only; credentials go in and never come back out.
 */
#[Layout('components.layouts.app')]
#[Title('Cloud hosts')]
final class CloudProviders extends Component
{
    /** Form fields per driver. The token is the only credential so far. */
    private const CREDENTIAL_FIELDS = [
        'hetzner' => ['token' => 'API token'],
        'fake' => ['token' => 'Token (not checked)'],
    ];

    private const DRIVER_LABELS = [
        'hetzner' => 'Hetzner Cloud',
        'fake' => 'Fake (development only)',
    ];

    public ?string $providerId = null;

    public string $providerName = '';

    public string $providerDriver = 'hetzner';

    /** @var array<string, string> */
    public array $credentials = [];

    public ?string $templateId = null;

    public string $name = '';

    public string $cloud_provider_id = '';

    public string $server_type = '';

    public string $location = '';

    public string $image = '';

    /** Comma-separated in the form; an array everywhere else. */
    public string $labels = 'self-hosted, linux, x64';

    public string $max_runners_per_host = '1';

    public string $warm_hosts = '0';

    public string $max_hosts = '1';

    public string $idle_seconds = '300';

    public string $max_lifetime_seconds = '86400';

    public bool $ipv4 = true;

    public bool $hourly_billing = true;

    public string $hourly_price = '';

    /** @var array<array-key, string> */
    public array $pool_ids = [];

    public function mount(): void
    {
        $this->authorizeAdmin();
        $this->resetTemplate();
    }

    public function editProvider(string $id): void
    {
        $this->authorizeAdmin();
        $provider = CloudProvider::query()->findOrFail($id);

        $this->resetProvider();
        $this->providerId = $provider->id;
        $this->providerName = $provider->name;
        $this->providerDriver = $provider->driver;
    }

    public function cancelProvider(): void
    {
        $this->resetProvider();
    }

    public function saveProvider(CloudManager $manager): void
    {
        $this->authorizeAdmin();

        try {
            $editing = $this->providerId === null ? null : CloudProvider::query()->findOrFail($this->providerId);
            $driver = $editing !== null ? $editing->driver : $this->providerDriver;
            $fields = self::CREDENTIAL_FIELDS[$driver] ?? [];

            $rules = [
                'providerName' => ['required', 'string', 'max:100', Rule::unique('cloud_providers', 'name')->ignore($editing?->getKey())],
            ];
            if ($editing === null) {
                // The driver of an existing provider cannot change.
                $rules['providerDriver'] = ['required', Rule::in($this->driverNames())];
            }
            foreach (array_keys($fields) as $key) {
                // On edit an empty token keeps the stored one.
                $rules["credentials.{$key}"] = [$editing === null ? 'required' : 'nullable', 'string', 'max:500'];
            }
            $names = ['providerName' => 'name', 'providerDriver' => 'driver'] + array_combine(
                array_map(fn (string $key) => "credentials.{$key}", array_keys($fields)),
                array_values($fields),
            );
            $this->validate($rules, [], $names);

            $credentials = array_filter(
                array_intersect_key($this->credentials, $fields),
                fn (string $value) => $value !== '',
            );
            $tokenChanged = $credentials !== [];

            // The provider has the final say: nothing is saved unless it accepts the token.
            if ($tokenChanged) {
                try {
                    $manager->driver($driver)->validateCredentials($credentials);
                } catch (CloudException $e) {
                    throw ValidationException::withMessages(['credentials.token' => $e->getMessage()]);
                }
            }

            if ($editing === null) {
                $provider = CloudProvider::query()->create([
                    'name' => trim($this->providerName),
                    'driver' => $driver,
                    'credentials' => $credentials,
                    'status' => 'active',
                ]);
                Audit::record('cloud.provider_created', $provider, ['name' => $provider->name, 'driver' => $driver]);
                $message = "Added {$provider->name}.";
            } else {
                $editing->name = trim($this->providerName);
                if ($tokenChanged) {
                    $editing->credentials = $credentials;
                }
                $editing->save();
                Audit::record('cloud.provider_updated', $editing, ['name' => $editing->name, 'driver' => $driver, 'token_changed' => $tokenChanged]);
                $message = "Saved {$editing->name}.";
            }
        } finally {
            // The token leaves the component's state whatever the outcome, so it is not sent back to the browser.
            $this->reset('credentials');
        }

        $this->resetProvider();
        $this->dispatch('toast', type: 'success', message: $message);
    }

    public function deleteProvider(string $id): void
    {
        $this->authorizeAdmin();
        $provider = CloudProvider::query()->findOrFail($id);

        $open = $this->openHosts(fn ($query) => $query->whereIn('host_template_id', $provider->templates()->select('id')));
        if ($open > 0) {
            $this->dispatch('toast', type: 'error', message: "{$provider->name} still has {$open} open ".Str::plural('server', $open).'. Wait until they are deleted, then delete the provider.');

            return;
        }

        $templates = $provider->templates()->count();
        $name = $provider->name;
        $driver = $provider->driver;
        $provider->delete();
        Audit::record('cloud.provider_deleted', $provider, ['name' => $name, 'driver' => $driver, 'templates' => $templates]);

        if ($this->providerId === $id) {
            $this->resetProvider();
        }
        $this->dispatch('toast', type: 'success', message: "Deleted {$name} and its {$templates} ".Str::plural('template', $templates).'.');
    }

    public function editTemplate(string $id): void
    {
        $this->authorizeAdmin();
        $template = HostTemplate::query()->with('pools:id')->findOrFail($id);

        $this->resetTemplate();
        $this->templateId = $template->id;
        $this->fill([
            'name' => $template->name,
            'cloud_provider_id' => $template->cloud_provider_id,
            'server_type' => $template->server_type,
            'location' => $template->location,
            'image' => $template->image,
            'labels' => implode(', ', $template->labels),
            'max_runners_per_host' => (string) $template->max_runners_per_host,
            'warm_hosts' => (string) $template->warm_hosts,
            'max_hosts' => (string) $template->max_hosts,
            'idle_seconds' => (string) $template->idle_seconds,
            'max_lifetime_seconds' => (string) $template->max_lifetime_seconds,
            'ipv4' => $template->ipv4,
            'hourly_billing' => $template->hourly_billing,
            'hourly_price' => (string) ($template->hourly_price ?? ''),
            'pool_ids' => $template->pools->pluck('id')->map(fn ($id) => (string) $id)->all(),
        ]);
    }

    public function cancelTemplate(): void
    {
        $this->resetTemplate();
    }

    public function saveTemplate(): void
    {
        $this->authorizeAdmin();
        $template = $this->templateId === null ? null : HostTemplate::query()->findOrFail($this->templateId);
        // The select shows its first option as chosen even when nothing was picked (for example a provider added
        // after the page loaded), so an empty value means that first provider.
        if ($this->cloud_provider_id === '') {
            $this->cloud_provider_id = (string) CloudProvider::query()->orderBy('name')->value('id');
        }
        $data = $this->validatedTemplate($template);

        // Moving a template to another account would orphan the servers it already created.
        if ($template !== null && $data['cloud_provider_id'] !== $template->cloud_provider_id && $this->openHosts(fn ($query) => $query->where('host_template_id', $template->id)) > 0) {
            throw ValidationException::withMessages(['cloud_provider_id' => 'Its servers are still open at the current provider. Wait until they are deleted.']);
        }

        $provider = CloudProvider::query()->findOrFail($data['cloud_provider_id']);
        $attributes = collect($data)->except('pool_ids')->all();

        DB::transaction(function () use (&$template, $attributes, $data, $provider) {
            if ($template === null) {
                $template = HostTemplate::query()->create($attributes + ['status' => 'active']);
                $action = 'cloud.template_created';
            } else {
                $template->update($attributes);
                $action = 'cloud.template_updated';
            }
            $template->pools()->sync($data['pool_ids']);
            Audit::record($action, $template, [
                'name' => $template->name,
                'provider' => $provider->name,
                'server_type' => $template->server_type,
                'location' => $template->location,
                'max_hosts' => $template->max_hosts,
                'warm_hosts' => $template->warm_hosts,
                'pools' => count($data['pool_ids']),
            ]);
        });

        $message = "Saved {$template->name}.";
        $this->resetTemplate();
        $this->dispatch('toast', type: 'success', message: $message);
    }

    public function deleteTemplate(string $id): void
    {
        $this->authorizeAdmin();
        $template = HostTemplate::query()->findOrFail($id);

        $open = $this->openHosts(fn ($query) => $query->where('host_template_id', $template->id));
        if ($open > 0) {
            $this->dispatch('toast', type: 'error', message: "{$template->name} still has {$open} open ".Str::plural('server', $open).'. Wait until they are deleted, then delete the template.');

            return;
        }

        $name = $template->name;
        $template->delete();
        Audit::record('cloud.template_deleted', $template, ['name' => $name]);

        if ($this->templateId === $id) {
            $this->resetTemplate();
        }
        $this->dispatch('toast', type: 'success', message: "Deleted {$name}.");
    }

    public function render(): View
    {
        $this->authorizeAdmin();
        $open = array_map(fn (CloudHostState $state) => $state->value, CloudHostState::open());

        return view('livewire.settings.cloud-providers', [
            'providers' => CloudProvider::query()->withCount('templates')->orderBy('name')->get(),
            'templates' => HostTemplate::query()
                ->with(['provider:id,name', 'pools:id,name'])
                ->withCount(['hosts as open_hosts' => fn ($query) => $query->whereIn('cloud_state', $open)])
                ->orderBy('name')->get(),
            'pools' => RunnerPool::query()->orderBy('name')->get(['id', 'name']),
            'drivers' => $this->drivers(),
            'credentialFields' => self::CREDENTIAL_FIELDS,
            'editingProvider' => $this->providerId !== null,
            'editingTemplate' => $this->templateId !== null,
        ]);
    }

    /** @return array<string, string> driver => label, without the fake driver outside local and testing */
    private function drivers(): array
    {
        $labels = [];
        foreach ($this->driverNames() as $name) {
            $labels[$name] = self::DRIVER_LABELS[$name] ?? ucfirst($name);
        }

        return $labels;
    }

    /** @return list<string> */
    private function driverNames(): array
    {
        return array_values(array_filter(
            app(CloudManager::class)->drivers(),
            fn (string $name) => $name !== 'fake' || app()->environment(['local', 'testing']),
        ));
    }

    /**
     * Hosts of the given scope that still hold, or are about to hold, a server.
     *
     * @param  callable(Builder<Host>): mixed  $scope
     */
    private function openHosts(callable $scope): int
    {
        $query = Host::query()->whereIn('cloud_state', array_map(fn (CloudHostState $state) => $state->value, CloudHostState::open()));
        $scope($query);

        return $query->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedTemplate(?HostTemplate $template): array
    {
        $list = fn (string $text) => array_values(array_filter(preg_split('/[\s,]+/', $text) ?: [], fn ($l) => $l !== ''));
        $data = [
            'name' => trim($this->name),
            'cloud_provider_id' => $this->cloud_provider_id,
            'server_type' => trim($this->server_type),
            'location' => trim($this->location),
            'image' => trim($this->image),
            'labels' => $list($this->labels),
            'max_runners_per_host' => $this->max_runners_per_host,
            'warm_hosts' => $this->warm_hosts,
            'max_hosts' => $this->max_hosts,
            'idle_seconds' => $this->idle_seconds,
            'max_lifetime_seconds' => $this->max_lifetime_seconds,
            'ipv4' => $this->ipv4,
            'hourly_billing' => $this->hourly_billing,
            'hourly_price' => $this->hourly_price === '' ? null : $this->hourly_price,
            'pool_ids' => array_values($this->pool_ids),
        ];

        $validator = Validator::make($data, TemplateRules::rules($template));
        $validator->after(fn (\Illuminate\Validation\Validator $v) => TemplateRules::after($v, $data, $template));

        try {
            return $validator->validate();
        } catch (ValidationException $e) {
            // Element errors (labels.0) are shown on their field.
            $messages = [];
            foreach ($e->errors() as $key => $list) {
                $messages[explode('.', $key)[0]] ??= $list;
            }
            throw ValidationException::withMessages($messages);
        }
    }

    private function resetProvider(): void
    {
        $this->reset('providerId', 'providerName', 'credentials');
        $this->providerDriver = 'hetzner';
        $this->resetErrorBag();
    }

    private function resetTemplate(): void
    {
        $this->reset('templateId', 'hourly_price', 'pool_ids');
        $this->fill([
            'name' => '',
            'cloud_provider_id' => (string) CloudProvider::query()->orderBy('name')->value('id'),
            'server_type' => '',
            'location' => '',
            'image' => '',
            'labels' => 'self-hosted, linux, x64',
            'max_runners_per_host' => '1',
            'warm_hosts' => '0',
            'max_hosts' => '1',
            'idle_seconds' => '300',
            'max_lifetime_seconds' => '86400',
            'ipv4' => true,
            'hourly_billing' => true,
        ]);
    }

    private function authorizeAdmin(): void
    {
        Gate::authorize('admin');
    }
}
