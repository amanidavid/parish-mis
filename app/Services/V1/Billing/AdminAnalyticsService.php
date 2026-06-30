<?php

namespace App\Services\V1\Billing;

use Illuminate\Support\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminAnalyticsService
{
    private const CACHE_TTL_SECONDS = 300;

    /**
     * Handle revenue trend.
     */
    public function revenueTrend(array $filters = []): array
    {
        return $this->remember(__FUNCTION__, $filters, function () use ($filters) {
            $window = $this->resolveWindow($filters);
            $rows = DB::connection('base')
                ->table('property_subscription_payments')
                ->where('payment_date', '>=', $window['start_date']->toDateString())
                ->where('payment_date', '<=', $window['end_date']->toDateString())
                ->selectRaw($this->dateBucketExpression('payment_date', $window['bucket_by']).' as bucket_key')
                ->selectRaw('COALESCE(SUM(total_amount_cents), 0) as total_collected_amount_cents')
                ->groupBy('bucket_key')
                ->orderBy('bucket_key')
                ->get()
                ->keyBy('bucket_key');

            $series = $this->mapBuckets($window['buckets'], $rows, function (array $bucket, ?object $row): array {
                return [
                    'key' => $bucket['key'],
                    'label' => $bucket['label'],
                    'bucket_start' => $bucket['start']->toDateString(),
                    'bucket_end' => $bucket['end']->toDateString(),
                    'total_collected_amount_cents' => (int) ($row->total_collected_amount_cents ?? 0),
                ];
            });

            return [
                'filters' => $this->formatWindowFilters($window),
                'summary' => [
                    'total_collected_amount_cents' => collect($series)->sum('total_collected_amount_cents'),
                ],
                'series' => $series,
                'generated_at' => now()->format('Y-m-d H:i:s'),
            ];
        });
    }

    /**
     * Handle subscription status trend.
     */
    public function subscriptionStatusTrend(array $filters = []): array
    {
        return $this->remember(__FUNCTION__, $filters, function () use ($filters) {
            $window = $this->resolveWindow($filters);
            $buckets = $window['buckets'];
            $bucketRowsQuery = $this->bucketRowsQuery($buckets);
            $totalProperties = $this->totalNonDeletedProperties();

            $firstCoverageSubquery = DB::connection('base')
                ->table('property_subscription_payments')
                ->join('workspace_properties', 'workspace_properties.id', '=', 'property_subscription_payments.workspace_property_id')
                ->whereNull('workspace_properties.property_deleted_at')
                ->where('property_subscription_payments.coverage_starts_on', '<=', $window['end_date']->toDateString())
                ->groupBy('property_subscription_payments.workspace_property_id')
                ->select('property_subscription_payments.workspace_property_id')
                ->selectRaw('MIN(property_subscription_payments.coverage_starts_on) as first_coverage_starts_on');

            $historicallySubscribedCounts = DB::connection('base')
                ->query()
                ->fromSub($bucketRowsQuery, 'buckets')
                ->leftJoinSub($firstCoverageSubquery, 'first_coverage', function ($join) {
                    $join->whereColumn('first_coverage.first_coverage_starts_on', '<=', 'buckets.bucket_end');
                })
                ->groupBy('buckets.bucket_key')
                ->orderBy('buckets.bucket_key')
                ->select('buckets.bucket_key')
                ->selectRaw('COUNT(first_coverage.workspace_property_id) as historically_subscribed_properties')
                ->get()
                ->keyBy('bucket_key');

            $activeCounts = DB::connection('base')
                ->query()
                ->fromSub($bucketRowsQuery, 'buckets')
                ->join('property_subscription_payments', function ($join) use ($window) {
                    $join
                        ->whereColumn('property_subscription_payments.coverage_starts_on', '<=', 'buckets.bucket_end')
                        ->whereColumn('property_subscription_payments.coverage_ends_on', '>=', 'buckets.bucket_end')
                        ->where('property_subscription_payments.coverage_starts_on', '<=', $window['end_date']->toDateString())
                        ->where('property_subscription_payments.coverage_ends_on', '>=', $window['start_date']->toDateString());
                })
                ->join('workspace_properties', 'workspace_properties.id', '=', 'property_subscription_payments.workspace_property_id')
                ->whereNull('workspace_properties.property_deleted_at')
                ->groupBy('buckets.bucket_key')
                ->orderBy('buckets.bucket_key')
                ->select('buckets.bucket_key')
                ->selectRaw('COUNT(DISTINCT property_subscription_payments.workspace_property_id) as active_subscribed_properties')
                ->get()
                ->keyBy('bucket_key');

            $series = collect($buckets)
                ->map(function (array $bucket) use ($historicallySubscribedCounts, $activeCounts, $totalProperties): array {
                    $historicallySubscribed = (int) ($historicallySubscribedCounts->get($bucket['key'])->historically_subscribed_properties ?? 0);
                    $active = (int) ($activeCounts->get($bucket['key'])->active_subscribed_properties ?? 0);

                    return [
                        'key' => $bucket['key'],
                        'label' => $bucket['label'],
                        'bucket_start' => $bucket['start']->toDateString(),
                        'bucket_end' => $bucket['end']->toDateString(),
                        'active_subscribed_properties' => $active,
                        'expired_properties' => max($historicallySubscribed - $active, 0),
                        'unsubscribed_properties' => max($totalProperties - $historicallySubscribed, 0),
                    ];
                })
                ->values()
                ->all();

            $latest = end($series) ?: [
                'active_subscribed_properties' => 0,
                'expired_properties' => 0,
                'unsubscribed_properties' => 0,
            ];

            return [
                'filters' => $this->formatWindowFilters($window),
                'summary' => [
                    'total_properties' => (int) $totalProperties,
                    'active_subscribed_properties' => (int) $latest['active_subscribed_properties'],
                    'expired_properties' => (int) $latest['expired_properties'],
                    'unsubscribed_properties' => (int) $latest['unsubscribed_properties'],
                ],
                'series' => $series,
                'generated_at' => now()->format('Y-m-d H:i:s'),
            ];
        });
    }

    /**
     * Handle property growth trend.
     */
    public function propertyGrowthTrend(array $filters = []): array
    {
        return $this->remember(__FUNCTION__, $filters, function () use ($filters) {
            $window = $this->resolveWindow($filters);
            $bucketExpressionForPropertyCreated = $this->timestampBucketExpression('property_created_at', $window['bucket_by']);
            $bucketExpressionForCreatedAt = $this->timestampBucketExpression('created_at', $window['bucket_by']);

            // Keep the two-branch query so each branch can still use its own date index instead of grouping over COALESCE().
            $propertyCreatedRows = DB::connection('base')
                ->table('workspace_properties')
                ->whereNotNull('property_created_at')
                ->where('property_created_at', '>=', $window['start_date']->toDateTimeString())
                ->where('property_created_at', '<=', $window['end_date']->toDateTimeString())
                ->selectRaw($bucketExpressionForPropertyCreated.' as bucket_key')
                ->selectRaw('COUNT(id) as new_properties')
                ->groupBy('bucket_key');

            $fallbackRows = DB::connection('base')
                ->table('workspace_properties')
                ->whereNull('property_created_at')
                ->where('created_at', '>=', $window['start_date']->toDateTimeString())
                ->where('created_at', '<=', $window['end_date']->toDateTimeString())
                ->selectRaw($bucketExpressionForCreatedAt.' as bucket_key')
                ->selectRaw('COUNT(id) as new_properties')
                ->groupBy('bucket_key');

            $rows = DB::connection('base')
                ->query()
                ->fromSub($propertyCreatedRows->unionAll($fallbackRows), 'property_growth_rows')
                ->select('bucket_key')
                ->selectRaw('SUM(new_properties) as new_properties')
                ->groupBy('bucket_key')
                ->orderBy('bucket_key')
                ->get()
                ->keyBy('bucket_key');

            $cumulative = 0;
            $series = $this->mapBuckets($window['buckets'], $rows, function (array $bucket, ?object $row) use (&$cumulative, $filters): array {
                $newProperties = (int) ($row->new_properties ?? 0);
                $cumulative += $newProperties;

                $data = [
                    'key' => $bucket['key'],
                    'label' => $bucket['label'],
                    'bucket_start' => $bucket['start']->toDateString(),
                    'bucket_end' => $bucket['end']->toDateString(),
                    'new_properties' => $newProperties,
                ];

                if ((bool) ($filters['include_cumulative'] ?? true)) {
                    $data['cumulative_total_properties'] = $cumulative;
                }

                return $data;
            });

            return [
                'filters' => $this->formatWindowFilters($window),
                'summary' => [
                    'new_properties' => collect($series)->sum('new_properties'),
                    'ending_cumulative_total_properties' => (int) ($cumulative ?? 0),
                ],
                'series' => $series,
                'generated_at' => now()->format('Y-m-d H:i:s'),
            ];
        });
    }

    /**
     * Handle subscription status split.
     */
    public function subscriptionStatusSplit(): array
    {
        return $this->remember(__FUNCTION__, [], function () {
            $today = Carbon::today()->toDateString();
            $baseQuery = DB::connection('base')
                ->table('workspace_properties')
                ->leftJoin('property_subscriptions', 'property_subscriptions.workspace_property_id', '=', 'workspace_properties.id')
                ->whereNull('workspace_properties.property_deleted_at');

            $totalProperties = (clone $baseQuery)->count('workspace_properties.id');
            $activeProperties = (clone $baseQuery)
                ->where('property_subscriptions.status', 'active')
                ->where(function ($query) use ($today) {
                    $query
                        ->whereNull('property_subscriptions.current_period_ends_on')
                        ->orWhere('property_subscriptions.current_period_ends_on', '>=', $today);
                })
                ->count('workspace_properties.id');

            $expiredProperties = (clone $baseQuery)
                ->where(function ($query) use ($today) {
                    $query
                        ->where('property_subscriptions.status', 'expired')
                        ->orWhere(function ($innerQuery) use ($today) {
                            $innerQuery
                                ->where('property_subscriptions.status', 'active')
                                ->whereNotNull('property_subscriptions.current_period_ends_on')
                                ->where('property_subscriptions.current_period_ends_on', '<', $today);
                        });
                })
                ->count('workspace_properties.id');

            $unsubscribedProperties = (clone $baseQuery)
                ->where(function ($query) {
                    $query
                        ->whereNull('property_subscriptions.id')
                        ->orWhere('property_subscriptions.status', 'unsubscribed');
                })
                ->count('workspace_properties.id');

            return [
                'summary' => [
                    'total_properties' => (int) $totalProperties,
                    'active_subscribed_properties' => (int) $activeProperties,
                    'expired_properties' => (int) $expiredProperties,
                    'unsubscribed_properties' => (int) $unsubscribedProperties,
                ],
                'series' => [
                    [
                        'status' => 'active',
                        'label' => 'Active',
                        'properties_count' => (int) $activeProperties,
                    ],
                    [
                        'status' => 'expired',
                        'label' => 'Expired',
                        'properties_count' => (int) $expiredProperties,
                    ],
                    [
                        'status' => 'unsubscribed',
                        'label' => 'Unsubscribed',
                        'properties_count' => (int) $unsubscribedProperties,
                    ],
                ],
                'generated_at' => now()->format('Y-m-d H:i:s'),
            ];
        });
    }

    /**
     * Handle top billing rules.
     */
    public function topBillingRules(array $filters = []): array
    {
        return $this->remember(__FUNCTION__, $filters, function () use ($filters) {
            $window = $this->resolveWindow($filters);
            $limit = (int) ($filters['limit'] ?? 5);

            // We rank default unit-price rules by collected amount because that is the strongest business signal for this chart.
            $rows = DB::connection('base')
                ->table('property_subscription_payments')
                ->join('billing_rules', 'billing_rules.id', '=', 'property_subscription_payments.billing_rule_id')
                ->whereNotNull('property_subscription_payments.billing_rule_id')
                ->where('property_subscription_payments.payment_date', '>=', $window['start_date']->toDateString())
                ->where('property_subscription_payments.payment_date', '<=', $window['end_date']->toDateString())
                ->groupBy(
                    'billing_rules.id',
                    'billing_rules.uuid',
                    'billing_rules.unit_price_cents',
                    'billing_rules.currency'
                )
                ->select([
                    'billing_rules.uuid as billing_rule_uuid',
                    'billing_rules.unit_price_cents',
                    'billing_rules.currency',
                ])
                ->selectRaw('COUNT(property_subscription_payments.id) as payments_count')
                ->selectRaw('COUNT(DISTINCT property_subscription_payments.workspace_property_id) as properties_count')
                ->selectRaw('COUNT(DISTINCT property_subscription_payments.tenant_id) as workspaces_count')
                ->selectRaw('COALESCE(SUM(property_subscription_payments.total_amount_cents), 0) as total_collected_amount_cents')
                ->orderByDesc('total_collected_amount_cents')
                ->orderByDesc('billing_rules.unit_price_cents')
                ->limit($limit)
                ->get();

            return [
                'filters' => array_merge($this->formatWindowFilters($window), [
                    'limit' => $limit,
                ]),
                'summary' => [
                    'billing_rules_count' => $rows->count(),
                    'total_collected_amount_cents' => (int) $rows->sum('total_collected_amount_cents'),
                ],
                'series' => $rows->map(function (object $row): array {
                    return [
                        'billing_rule_uuid' => $row->billing_rule_uuid,
                        'label' => 'Default unit price - '.number_format((int) $row->unit_price_cents).' / unit',
                        'unit_price_cents' => (int) $row->unit_price_cents,
                        'currency' => $row->currency,
                        'properties_count' => (int) $row->properties_count,
                        'workspaces_count' => (int) $row->workspaces_count,
                        'payments_count' => (int) $row->payments_count,
                        'total_collected_amount_cents' => (int) $row->total_collected_amount_cents,
                    ];
                })->values()->all(),
                'generated_at' => now()->format('Y-m-d H:i:s'),
            ];
        });
    }

    /**
     * Remember.
     */
    private function remember(string $method, array $filters, callable $callback): array
    {
        $cacheKey = 'admin.analytics.'.strtolower($method).'.'.md5(json_encode($filters));

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            $callback
        );
    }

    /**
     * Resolve window.
     */
    private function resolveWindow(array $filters): array
    {
        $period = (string) ($filters['period'] ?? 'month');
        $anchorDate = !empty($filters['anchor_date'] ?? null)
            ? Carbon::parse($filters['anchor_date'])->startOfDay()
            : now()->startOfDay();

        $bucketBy = 'day';

        switch ($period) {
            case 'week':
                $startDate = $anchorDate->copy()->startOfWeek();
                $endDate = $anchorDate->copy()->endOfWeek();
                $bucketBy = 'day';
                break;

            case 'year':
                $year = (int) ($filters['year'] ?? $anchorDate->year);
                $startDate = Carbon::create($year, 1, 1)->startOfDay();
                $endDate = Carbon::create($year, 12, 31)->endOfDay();
                $bucketBy = 'month';
                break;

            case 'custom':
                $startDate = Carbon::parse($filters['start_date'])->startOfDay();
                $endDate = Carbon::parse($filters['end_date'])->endOfDay();
                $bucketBy = (string) ($filters['bucket_by'] ?? $this->resolveCustomBucketBy($startDate, $endDate));
                break;

            case 'month':
            default:
                $year = (int) ($filters['year'] ?? $anchorDate->year);
                $month = (int) ($filters['month'] ?? $anchorDate->month);
                $startDate = Carbon::create($year, $month, 1)->startOfDay();
                $endDate = $startDate->copy()->endOfMonth()->endOfDay();
                $bucketBy = 'week';
                break;
        }

        if ($period !== 'custom' && !empty($filters['bucket_by'] ?? null)) {
            $bucketBy = (string) $filters['bucket_by'];
        }

        return [
            'period' => $period,
            'bucket_by' => $bucketBy,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'buckets' => $this->buildBuckets($startDate, $endDate, $bucketBy),
        ];
    }

    /**
     * Build buckets.
     */
    private function buildBuckets(Carbon $startDate, Carbon $endDate, string $bucketBy): array
    {
        $buckets = [];

        if ($bucketBy === 'day') {
            foreach (CarbonPeriod::create($startDate->copy(), '1 day', $endDate->copy()) as $date) {
                $bucketDate = Carbon::parse($date);
                $buckets[] = [
                    'key' => $bucketDate->toDateString(),
                    'label' => $bucketDate->format('D'),
                    'start' => $bucketDate->copy()->startOfDay(),
                    'end' => $bucketDate->copy()->endOfDay(),
                ];
            }

            return $buckets;
        }

        if ($bucketBy === 'month') {
            $cursor = $startDate->copy()->startOfMonth();

            while ($cursor->lte($endDate)) {
                $bucketStart = $cursor->copy()->startOfMonth();
                $bucketEnd = $cursor->copy()->endOfMonth()->endOfDay()->min($endDate->copy()->endOfDay());
                $buckets[] = [
                    'key' => $bucketStart->toDateString(),
                    'label' => $bucketStart->format('M'),
                    'start' => $bucketStart,
                    'end' => $bucketEnd,
                ];
                $cursor->addMonth();
            }

            return $buckets;
        }

        // Weekly buckets are anchored to week starts so chart labels stay stable across the month view.
        $cursor = $startDate->copy()->startOfWeek();
        $weekNumber = 1;

        while ($cursor->lte($endDate)) {
            $bucketStart = $cursor->copy()->max($startDate->copy()->startOfDay());
            $bucketEnd = $cursor->copy()->endOfWeek()->endOfDay()->min($endDate->copy()->endOfDay());
            $buckets[] = [
                'key' => $cursor->toDateString(),
                'label' => 'Week '.$weekNumber,
                'start' => $bucketStart,
                'end' => $bucketEnd,
            ];
            $cursor->addWeek();
            $weekNumber++;
        }

        return $buckets;
    }

    /**
     * Map buckets.
     */
    private function mapBuckets(array $buckets, Collection $rows, callable $callback): array
    {
        return collect($buckets)
            ->map(fn (array $bucket) => $callback($bucket, $rows->get($bucket['key'])))
            ->values()
            ->all();
    }

    /**
     * Format window filters.
     */
    private function formatWindowFilters(array $window): array
    {
        return [
            'period' => $window['period'],
            'bucket_by' => $window['bucket_by'],
            'start_date' => $window['start_date']->toDateString(),
            'end_date' => $window['end_date']->toDateString(),
        ];
    }

    /**
     * Resolve custom bucket by.
     */
    private function resolveCustomBucketBy(Carbon $startDate, Carbon $endDate): string
    {
        $days = $startDate->diffInDays($endDate) + 1;

        return match (true) {
            $days <= 31 => 'day',
            $days <= 120 => 'week',
            default => 'month',
        };
    }

    /**
     * Date bucket expression.
     */
    private function dateBucketExpression(string $column, string $bucketBy): string
    {
        $driver = DB::connection('base')->getDriverName();

        // DATE columns do not need an extra DATE() wrapper for day buckets.
        if ($driver === 'pgsql') {
            return match ($bucketBy) {
                'month' => "TO_CHAR(DATE_TRUNC('month', {$column}), 'YYYY-MM-DD')",
                'week' => "TO_CHAR(DATE_TRUNC('week', {$column}), 'YYYY-MM-DD')",
                default => "TO_CHAR({$column}, 'YYYY-MM-DD')",
            };
        }

        return match ($bucketBy) {
            'month' => "DATE_FORMAT({$column}, '%Y-%m-01')",
            'week' => "DATE_SUB({$column}, INTERVAL WEEKDAY({$column}) DAY)",
            default => $column,
        };
    }

    /**
     * Timestamp bucket expression.
     */
    private function timestampBucketExpression(string $column, string $bucketBy): string
    {
        $driver = DB::connection('base')->getDriverName();

        if ($driver === 'pgsql') {
            return match ($bucketBy) {
                'month' => "TO_CHAR(DATE_TRUNC('month', {$column}), 'YYYY-MM-DD')",
                'week' => "TO_CHAR(DATE_TRUNC('week', {$column}), 'YYYY-MM-DD')",
                default => "TO_CHAR(DATE({$column}), 'YYYY-MM-DD')",
            };
        }

        return match ($bucketBy) {
            'month' => "DATE_FORMAT({$column}, '%Y-%m-01')",
            'week' => "DATE_SUB(DATE({$column}), INTERVAL WEEKDAY({$column}) DAY)",
            default => "DATE({$column})",
        };
    }

    /**
     * Total non deleted properties.
     */
    private function totalNonDeletedProperties(): int
    {
        return DB::connection('base')
            ->table('workspace_properties')
            ->whereNull('property_deleted_at')
            ->count();
    }

    /**
     * Bucket rows query.
     */
    private function bucketRowsQuery(array $buckets)
    {
        $connection = DB::connection('base');
        $grammar = $connection->getQueryGrammar();
        $driver = $connection->getDriverName();
        $rows = [];

        foreach ($buckets as $bucket) {
            $bucketKey = $grammar->quoteString($bucket['key']);
            $bucketLabel = $grammar->quoteString($bucket['label']);
            $bucketStart = $grammar->quoteString($bucket['start']->toDateString());
            $bucketEnd = $grammar->quoteString($bucket['end']->toDateString());

            if ($driver === 'pgsql') {
                $bucketKey .= '::date';
                $bucketStart .= '::date';
                $bucketEnd .= '::date';
            }

            $rows[] = sprintf(
                'SELECT %s AS bucket_key, %s AS bucket_label, %s AS bucket_start, %s AS bucket_end',
                $bucketKey,
                $bucketLabel,
                $bucketStart,
                $bucketEnd
            );
        }

        return $connection->table(DB::raw('('.implode(' UNION ALL ', $rows).') as analytics_buckets'));
    }
}
