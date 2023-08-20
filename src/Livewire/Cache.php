<?php

namespace Laravel\Pulse\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache as CacheFacade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Concerns\HasPeriod;
use Laravel\Pulse\Livewire\Concerns\ShouldNotReportUsage;
use Livewire\Component;
use stdClass;

class Cache extends Component
{
    use HasPeriod, ShouldNotReportUsage;

    /**
     * Render the component.
     */
    public function render(): Renderable
    {
        [$allCacheInteractions, $allTime, $allRunAt] = $this->allCacheInteractions();

        [$monitoredCacheInteractions, $monitoredTime, $monitoredRunAt] = $this->monitoredCacheInteractions();

        $this->dispatch('cache:dataLoaded');

        return View::make('pulse::livewire.cache', [
            'allTime' => $allTime,
            'allRunAt' => $allRunAt,
            'monitoredTime' => $monitoredTime,
            'monitoredRunAt' => $monitoredRunAt,
            'allCacheInteractions' => $allCacheInteractions,
            'monitoredCacheInteractions' => $monitoredCacheInteractions,
        ]);
    }

    /**
     * Render the placeholder.
     */
    public function placeholder(): Renderable
    {
        return View::make('pulse::components.placeholder', ['class' => 'col-span-3']);
    }

    /**
     * All the cache interactions.
     */
    protected function allCacheInteractions(): array
    {
        return CacheFacade::remember("illuminate:pulse:cache-all:{$this->period}", $this->periodCacheDuration(), function () {
            $now = new CarbonImmutable;

            $start = hrtime(true);

            $cacheInteractions = DB::table('pulse_cache_hits')
                ->selectRaw('COUNT(*) AS count, SUM(CASE WHEN `hit` = TRUE THEN 1 ELSE 0 END) as hits')
                ->where('date', '>=', $now->subHours($this->periodAsHours())->toDateTimeString())
                ->first() ?? (object) ['hits' => 0];

            $cacheInteractions->hits = (int) $cacheInteractions->hits;

            $time = (int) ((hrtime(true) - $start) / 1000000);

            return [$cacheInteractions, $time, $now->toDateTimeString()];
        });
    }

    /**
     * The monitored cache interactions.
     */
    protected function monitoredCacheInteractions(): array
    {
        return CacheFacade::remember("illuminate:pulse:cache-monitored:{$this->period}:{$this->monitoredKeysCacheHash()}", $this->periodCacheDuration(), function () {
            $now = new CarbonImmutable;

            if ($this->monitoredKeys()->isEmpty()) {
                return [[], 0, $now->toDateTimeString()];
            }

            $start = hrtime(true);

            $interactions = $this->monitoredKeys()->mapWithKeys(fn (string $name, string $regex) => [
                $name => (object) [
                    'regex' => $regex,
                    'key' => $name,
                    'uniqueKeys' => 0,
                    'hits' => 0,
                    'count' => 0,
                ],
            ]);

            DB::table('pulse_cache_hits')
                ->selectRaw('`key`, COUNT(*) AS count, SUM(CASE WHEN `hit` = TRUE THEN 1 ELSE 0 END) as hits')
                ->where('date', '>=', $now->subHours($this->periodAsHours())->toDateTimeString())
                // TODO: ensure PHP and MySQL regex is compatible
                // TODO modifiers? is redis / memcached / etc case sensitive?
                ->where(fn (Builder $query) => $this->monitoredKeys()->keys()->each(fn (string $key) => $query->orWhere('key', 'RLIKE', $key)))
                ->orderBy('key')
                ->groupBy('key')
                ->each(function (stdClass $result) use ($interactions) {
                    $name = $this->monitoredKeys()->firstWhere(fn (string $name, string $regex) => preg_match('/'.$regex.'/', $result->key) > 0);

                    if ($name === null) {
                        return;
                    }

                    $interaction = $interactions[$name];

                    $interaction->uniqueKeys++;
                    $interaction->hits += $result->hits;
                    $interaction->count += $result->count;
                });

            $monitoringIndex = $this->monitoredKeys()->values()->flip();

            $interactions = $interactions
                ->sortBy(fn (stdClass $interaction) => $monitoringIndex[$interaction->key])
                ->all();

            $time = (int) ((hrtime(true) - $start) / 1000000);

            return [$interactions, $time, $now->toDateTimeString()];
        });
    }

    /** The monitored keys.
     */
    protected function monitoredKeys(): Collection
    {
        return collect(config('pulse.cache_keys'))
            ->mapWithKeys(fn (string $value, int|string $key) => is_string($key)
                ? [$key => $value]
                : [$value => $value]);
    }

    /**
     * The monitored keys cache hash.
     */
    protected function monitoredKeysCacheHash(): string
    {
        return $this->monitoredKeys()->pipe(fn (Collection $items) => md5($items->toJson()));
    }
}