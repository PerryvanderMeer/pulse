<?php

namespace Laravel\Pulse\Http\Livewire;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Pulse\Contracts\ShouldNotReportUsage;
use Laravel\Pulse\Http\Livewire\Concerns\HasPeriod;
use Livewire\Component;

class SlowRoutes extends Component implements ShouldNotReportUsage
{
    use HasPeriod;

    /**
     * The width of the component.
     *
     * @var string
     */
    public $width;

    /**
     * Handle the mount event.
     *
     * @param  string  $width
     * @return void
     */
    public function mount($width = '1/2')
    {
        $this->width = $width;
    }

    /**
     * Render the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        if (request()->hasHeader('X-Livewire')) {
            $this->loadData();
        }

        [$slowRoutes, $time, $runAt] = $this->slowRoutes();

        return view('pulse::livewire.slow-routes', [
            'time' => $time,
            'runAt' => $runAt,
            'slowRoutes' => $slowRoutes,
            'initialDataLoaded' => $slowRoutes !== null,
        ]);
    }

    /**
     * The slow routes.
     *
     * @return array
     */
    protected function slowRoutes()
    {
        return Cache::get("pulse:slow-routes:{$this->period}") ?? [null, 0, null];
    }

    /**
     * Load the data for the component.
     *
     * @return void
     */
    public function loadData()
    {
        Cache::remember("pulse:slow-routes:{$this->period}", now()->addSeconds(match ($this->period) {
            '6_hours' => 30,
            '24_hours' => 60,
            '7_days' => 600,
            default => 5,
        }), function () {
            $now = now()->toImmutable();

            $start = hrtime(true);

            $slowRoutes = DB::table('pulse_requests')
                ->selectRaw('route, COUNT(*) as count, MAX(duration) AS slowest')
                ->where('date', '>=', $now->subHours(match ($this->period) {
                    '6_hours' => 6,
                    '24_hours' => 24,
                    '7_days' => 168,
                    default => 1,
                })->toDateTimeString())
                ->where('duration', '>=', config('pulse.slow_endpoint_threshold'))
                ->groupBy('route')
                ->orderByDesc('slowest')
                ->get()
                ->map(function ($row) {
                    [$method, $path] = explode(' ', $row->route, 2);
                    $route = Route::getRoutes()->get($method)[$path] ?? null;

                    return [
                        'uri' => $row->route,
                        'action' => $route?->getActionName(),
                        'request_count' => (int) $row->count,
                        'slowest_duration' => (int) $row->slowest,
                    ];
                })
                ->all();

            $time = (int) ((hrtime(true) - $start) / 1000000);

            return [$slowRoutes, $time, $now->toDateTimeString()];
        });

        $this->dispatchBrowserEvent('slow-routes:dataLoaded');
    }
}
