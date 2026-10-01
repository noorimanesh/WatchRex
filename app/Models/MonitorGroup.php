<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Domain\DomainInspector;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A (nestable) group of monitors: a website, its mail stack, a server, a customer… */
#[Fillable(['team_id', 'parent_id', 'server_id', 'name', 'kind', 'domain', 'color', 'description', 'sort'])]
class MonitorGroup extends Model
{
    use BelongsToTenant;

    public const KINDS = [
        'website' => ['Website', '🌐'],
        'mail' => ['Mail service', '📬'],
        'server' => ['Server', '🖥️'],
        'service' => ['Service / API', '⚙️'],
        'general' => ['General', '📁'],
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(MonitorGroup::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(MonitorGroup::class, 'parent_id')->orderBy('sort')->orderBy('name');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class, 'group_monitor');
    }

    public function statusPages(): BelongsToMany
    {
        return $this->belongsToMany(StatusPage::class, 'monitor_group_status_page');
    }

    /** Domain intelligence record matching this group's domain (same owner). */
    public function domainRecord(): ?Domain
    {
        if (! $this->domain) {
            return null;
        }

        return Domain::where('user_id', $this->user_id)
            ->whereIn('name', array_unique([$this->domain, DomainInspector::registrable($this->domain)]))
            ->orderByRaw('CASE WHEN name = ? THEN 0 ELSE 1 END', [$this->domain])
            ->first();
    }

    public function icon(): string
    {
        return self::KINDS[$this->kind][1] ?? '📁';
    }

    public function kindLabel(): string
    {
        return __(self::KINDS[$this->kind][0] ?? 'General');
    }

    /** IDs of this group and all its descendants (cycle-safe). */
    public function descendantIds(): array
    {
        $ids = [$this->id];
        $frontier = [$this->id];
        while ($frontier) {
            $next = static::whereIn('parent_id', $frontier)->whereNotIn('id', $ids)->pluck('id')->all();
            $ids = array_merge($ids, $next);
            $frontier = $next;
        }

        return $ids;
    }

    /** Monitors of this group and of every nested sub-group. */
    public function allMonitorIds(): array
    {
        return \DB::table('group_monitor')->whereIn('monitor_group_id', $this->descendantIds())->distinct()->pluck('monitor_id')->all();
    }

    /** Finds a group by attributes for an owner, or creates it (user_id is never mass-assigned). */
    public static function findOrCreateFor(int $userId, array $match, array $values = []): self
    {
        $group = static::where('user_id', $userId)->where($match)->first();
        if (! $group) {
            $group = new static(array_merge($match, $values));
            $group->user_id = $userId;
            $group->save();
        }

        return $group;
    }

    public static function createsCycle(int $groupId, ?int $parentId): bool
    {
        $seen = [];
        while ($parentId) {
            if ($parentId === $groupId || isset($seen[$parentId])) {
                return true;
            }
            $seen[$parentId] = true;
            $parentId = static::whereKey($parentId)->value('parent_id');
        }

        return false;
    }
}
