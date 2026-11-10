<x-layouts.app title="UI kit">
    <div class="space-y-8">
        <x-ui.card title="Buttons" description="Flat, 10 px radius, ink for action; red only when destructive.">
            <div class="space-y-5">
                <div class="flex flex-wrap items-center gap-3">
                    <x-ui.button>Primary</x-ui.button>
                    <x-ui.button variant="secondary">Secondary</x-ui.button>
                    <x-ui.button variant="outline">Outline</x-ui.button>
                    <x-ui.button variant="ghost">Ghost</x-ui.button>
                    <x-ui.button variant="destructive">Destructive</x-ui.button>
                    <x-ui.button variant="danger">Danger</x-ui.button>
                    <x-ui.button variant="link">Link</x-ui.button>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <x-ui.button size="xs">Extra small</x-ui.button>
                    <x-ui.button size="sm">Small</x-ui.button>
                    <x-ui.button>Default</x-ui.button>
                    <x-ui.button size="lg">Large</x-ui.button>
                    <x-ui.button variant="outline" icon-only aria-label="Add"><x-icon name="plus" :size="16" /></x-ui.button>
                    <x-ui.button variant="outline" size="sm" icon-only aria-label="Next"><x-icon name="chevron-right" :size="16" /></x-ui.button>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <x-ui.button icon="plus">New pool</x-ui.button>
                    <x-ui.button variant="outline" icon-end="chevron-right">Continue</x-ui.button>
                    <x-ui.button variant="destructive" icon="x-circle">Terminate runner</x-ui.button>
                    <x-ui.button loading>Provisioning</x-ui.button>
                    <x-ui.button variant="outline" loading>Saving</x-ui.button>
                    <x-ui.button pill size="lg">Get started</x-ui.button>
                    <x-ui.button href="#" variant="outline">As a link</x-ui.button>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <x-ui.button disabled>Disabled</x-ui.button>
                    <x-ui.button variant="secondary" disabled>Disabled</x-ui.button>
                    <x-ui.button variant="outline" disabled>Disabled</x-ui.button>
                    <x-ui.button variant="destructive" disabled>Disabled</x-ui.button>
                    <x-ui.button variant="danger" disabled>Disabled</x-ui.button>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card title="Activity heatmap" description="One square per day; darker means more jobs.">
            @php($demo = collect(range(0, 120))->mapWithKeys(fn ($i) => [now()->subDays($i)->toDateString() => ($i * 7) % 11 === 0 ? 0 : ($i * 13) % 23])->all())
            <x-ui.activity-heatmap :days="$demo" />
        </x-ui.card>

        <x-ui.card title="Badges">
            <div class="flex flex-wrap gap-2">
                <x-ui.badge tone="success" icon="check-circle">Completed</x-ui.badge>
                <x-ui.badge tone="info" dot>Running</x-ui.badge>
                <x-ui.badge tone="warning" icon="clock">Queued</x-ui.badge>
                <x-ui.badge tone="error" icon="x-circle">Failed</x-ui.badge>
                <x-ui.badge icon="pause-circle">Cancelled</x-ui.badge>
            </div>
        </x-ui.card>

        <div class="grid gap-4 sm:grid-cols-3">
            <x-ui.stat label="Queue depth" value="12" detail="p95 wait 8 s" />
            <x-ui.stat label="Running jobs" value="5" tone="info" />
            <x-ui.stat label="Success rate (24 h)" value="98.2%" tone="success" />
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <x-ui.stepper title="Job lifecycle" :steps="[
                ['label' => 'Queued', 'detail' => '3 s', 'state' => 'done'],
                ['label' => 'Runner provisioned', 'detail' => 'jogger-9f2c on host-a · 8 s', 'state' => 'done'],
                ['label' => 'Running', 'detail' => 'step 4 of 9', 'state' => 'active'],
                ['label' => 'Completed', 'state' => 'pending'],
            ]" />
            <x-ui.stepper title="Failed provisioning" :steps="[
                ['label' => 'Queued', 'detail' => '2 s', 'state' => 'done'],
                ['label' => 'Runner provisioning', 'detail' => 'Timed out after 3 min', 'state' => 'failed'],
                ['label' => 'Running', 'state' => 'skipped'],
                ['label' => 'Completed', 'state' => 'pending'],
            ]" />
            <x-ui.card title="Status pills">
                <div class="space-y-3">
                    <div class="flex flex-wrap gap-2">
                        <x-job-status-pill status="queued" />
                        <x-job-status-pill status="in_progress" />
                        <x-job-status-pill status="completed" conclusion="success" />
                        <x-job-status-pill status="completed" conclusion="failure" />
                        <x-job-status-pill status="completed" conclusion="cancelled" />
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-runner-state-pill state="provisioning" /><x-runner-state-pill state="idle" />
                        <x-runner-state-pill state="busy" /><x-runner-state-pill state="failed" />
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-host-status-pill status="online" /><x-host-status-pill status="offline" />
                        <x-host-status-pill status="draining" /><x-host-status-pill status="mystery" />
                    </div>
                    <x-pool-meter :busy="3" :idle="2" :provisioning="1" :max="10" />
                </div>
            </x-ui.card>
        </div>

        <x-ui.card title="Form controls">
            <form class="grid gap-4 sm:grid-cols-2" onsubmit="return false">
                <x-ui.input label="Pool name" name="pool" placeholder="linux-large" hint="Unique per installation." />
                <x-ui.input label="Labels" name="labels" error="Labels may only contain letters, digits, . _ -" value="bad label!" />
                <x-ui.select label="Scope" name="scope"><option>Repository</option><option>Organization</option></x-ui.select>
                <x-ui.textarea label="Notes" name="notes" rows="2" />
                <x-ui.checkbox label="Persist tool cache" hint="Keeps /opt/hostedtoolcache between runners." />
                <x-ui.toggle label="Pool enabled" checked />
            </form>
        </x-ui.card>

        <x-ui.card title="Overlays">
            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button variant="outline" x-on:click="$dispatch('open-modal', 'demo')">Open modal</x-ui.button>
                <x-ui.button variant="outline" onclick="toast({ type: 'success', message: 'Saved pool linux-large' })">Success toast</x-ui.button>
                <x-ui.button variant="outline" onclick="toast({ type: 'error', message: 'Provisioning timed out after 3 min.' })">Error toast</x-ui.button>
                <x-ui.button variant="outline" icon="terminal" id="send-ping">Send ping</x-ui.button>
                <x-ui.dropdown align="left">
                    <x-slot:trigger><x-ui.button variant="outline">Actions <x-icon name="chevron-down" :size="14" /></x-ui.button></x-slot:trigger>
                    <x-ui.dropdown-item icon="pause-circle">Pause pool</x-ui.dropdown-item>
                    <x-ui.dropdown-item icon="x-circle" danger>Delete pool</x-ui.dropdown-item>
                </x-ui.dropdown>
                <x-ui.tooltip text="Median time from queued to running"><x-ui.badge tone="info">8 s</x-ui.badge></x-ui.tooltip>
            </div>
            <x-ui.modal name="demo" title="Terminate runner?">
                <p class="text-text-secondary">The runner and its container will be removed. A running job will fail.</p>
                <x-slot:footer>
                    <x-ui.button variant="outline" x-on:click="$dispatch('close-modal', 'demo')">Cancel</x-ui.button>
                    <x-ui.button variant="danger" x-on:click="$dispatch('close-modal', 'demo')">Terminate</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        </x-ui.card>

        <x-ui.card title="Tabs">
            <x-ui.tabs :tabs="['logs' => 'Logs', 'runner' => 'Runner', 'raw' => 'Raw']">
                <x-ui.tab-panel name="logs">Log output appears here.</x-ui.tab-panel>
                <x-ui.tab-panel name="runner">Runner details appear here.</x-ui.tab-panel>
                <x-ui.tab-panel name="raw">Raw payload appears here.</x-ui.tab-panel>
            </x-ui.tabs>
        </x-ui.card>

        <x-ui.table>
            <x-slot:head><th>Job</th><th>Repository</th><th>Duration</th></x-slot:head>
            <tr><td data-label="Job">build</td><td data-label="Repository" class="font-mono">acme/api</td><td data-label="Duration" class="tabular">1m 12s</td></tr>
            <tr><td data-label="Job">test</td><td data-label="Repository" class="font-mono">acme/web</td><td data-label="Duration" class="tabular">3m 40s</td></tr>
        </x-ui.table>

        <x-ui.empty-state icon="server" title="No hosts yet" description="Enroll a Docker host to start running jobs.">
            <x-ui.button icon="plus">Add host</x-ui.button>
        </x-ui.empty-state>
    </div>

    @push('scripts')
        <script>
            // Realtime smoke test: a ping sent here shows as a toast in every open tab of this page.
            window.addEventListener('DOMContentLoaded', () => {
                window.Echo.channel('dev.ping').listen('.Pinged', (e) => toast({ type: 'info', message: e.message }));
                document.getElementById('send-ping').addEventListener('click', () => {
                    fetch(@js(route('dev.ping')), {
                        method: 'POST',
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    });
                });
            });
        </script>
    @endpush
</x-layouts.app>
