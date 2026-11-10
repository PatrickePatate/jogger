<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Domain\Access\Models\AuditLog as Entry;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** Who did what, newest first. Administrators only. */
#[Layout('components.layouts.app')]
#[Title('Audit log')]
final class AuditLog extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $actor = '';

    #[Url(except: '')]
    public string $action = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function export(): StreamedResponse
    {
        Gate::authorize('admin');
        $query = $this->query();

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['time', 'actor', 'action', 'target_type', 'target_id', 'ip', 'metadata']);
            $query->with('user:id,email')->chunk(500, function ($entries) use ($out) {
                foreach ($entries as $e) {
                    fputcsv($out, [$e->created_at?->toIso8601String(), $e->user->email ?? '', $e->action, $e->target_type, $e->target_id, $e->ip, json_encode($e->metadata)]);
                }
            });
            fclose($out);
        }, 'jogger-audit-log.csv', ['Content-Type' => 'text/csv']);
    }

    public function render(): View
    {
        Gate::authorize('admin');

        return view('livewire.admin.audit-log', [
            'entries' => $this->query()->with('user:id,name,email')->cursorPaginate(50),
            'actors' => User::query()->withTrashed()->whereIn('id', Entry::query()->select('user_id')->distinct())->orderBy('email')->pluck('email', 'id'),
            'actions' => Entry::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    /**
     * @return Builder<Entry>
     */
    private function query(): Builder
    {
        return Entry::query()
            ->when($this->actor !== '', fn (Builder $q) => $q->where('user_id', $this->actor))
            ->when($this->action !== '', fn (Builder $q) => $q->where('action', $this->action))
            ->when(self::date($this->from), fn (Builder $q, Carbon $d) => $q->where('created_at', '>=', $d->startOfDay()))
            ->when(self::date($this->to), fn (Builder $q, Carbon $d) => $q->where('created_at', '<=', $d->endOfDay()))
            ->orderByDesc('id');
    }

    private static function date(string $value): ?Carbon
    {
        try {
            return $value === '' ? null : Carbon::createFromFormat('Y-m-d', $value);
        } catch (Throwable) {
            return null;
        }
    }
}
