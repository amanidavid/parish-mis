<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\V1\BillingProfileIndexRequest;
use App\Http\Requests\Api\Admin\V1\BillingRuleIndexRequest;
use App\Http\Requests\Api\Admin\V1\StoreBillingProfileRequest;
use App\Http\Requests\Api\Admin\V1\StoreBillingRuleRequest;
use App\Http\Requests\Api\Admin\V1\UpdateBillingProfileRequest;
use App\Http\Requests\Api\Admin\V1\UpdateBillingRuleRequest;
use App\Http\Resources\Admin\V1\BillingProfileResource;
use App\Http\Resources\Admin\V1\BillingRuleResource;
use App\Models\Landlord\BillingProfile;
use App\Models\Landlord\BillingRule;
use App\Support\ApiMessages;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingProfileController extends Controller
{
    /**
     * Create a new instance.
     */
    public function __construct()
    {
    }

    /** List billing profiles for admin management screens with filterable pricing metadata. */
    /**
     * Handle the index request.
     */
    public function index(BillingProfileIndexRequest $request)
    {
        $filters = $request->validated();
        $query = BillingProfile::query()
            ->select([
                'id',
                'uuid',
                'name',
                'description',
                'billing_interval',
                'trial_days',
                'grace_days',
                'currency',
                'is_default',
                'status',
                'created_at',
                'updated_at',
            ])
            ->withCount([
                'rules' => static fn ($rulesQuery) => $rulesQuery->reorder(),
            ]);

        if (!empty($filters['search'] ?? null)) {
            $query->where('name', 'like', $filters['search'].'%');
        }

        if (!empty($filters['name'] ?? null)) {
            $query->where('name', 'like', $filters['name'].'%');
        }

        if (!empty($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['billing_interval'] ?? null)) {
            $query->where('billing_interval', $filters['billing_interval']);
        }

        $profiles = $query->orderByDesc('is_default')->orderBy('name')->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::resource(BillingProfileResource::collection($profiles), 'Billing profiles retrieved successfully.');
    }

    /** Create a reusable billing profile that can later be assigned to workspaces. */
    /**
     * Handle the store request.
     */
    public function store(StoreBillingProfileRequest $request)
    {
        $data = $request->validated();

        if (BillingProfile::query()->where('name', $data['name'])->exists()) {
            return ApiResponse::error('Billing profile could not be created.', ['name' => ['A billing profile with the same name already exists.']], 422);
        }

        $profile = DB::connection('base')->transaction(function () use ($data) {
            if (($data['is_default'] ?? false) === true) {
                BillingProfile::query()->where('is_default', true)->update(['is_default' => false]);
            }

            return BillingProfile::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'billing_interval' => $data['billing_interval'],
                'trial_days' => $data['trial_days'] ?? 14,
                'grace_days' => $data['grace_days'] ?? 0,
                'currency' => strtoupper($data['currency'] ?? 'TZS'),
                'is_default' => $data['is_default'] ?? false,
                'status' => $data['status'] ?? 'active',
            ]);
        });

        return ApiResponse::resource(new BillingProfileResource($profile->loadCount('rules')), 'Billing profile created successfully.', 201);
    }

    /** Return one billing profile together with its rule count for detail screens. */
    /**
     * Handle the show request.
     */
    public function show(BillingProfile $billingProfile)
    {
        return ApiResponse::resource(
            new BillingProfileResource($billingProfile->loadCount('rules')),
            'Billing profile details retrieved successfully.'
        );
    }

    /** Update pricing metadata on an existing billing profile without touching its rule history. */
    /**
     * Handle the update request.
     */
    public function update(UpdateBillingProfileRequest $request, BillingProfile $billingProfile)
    {
        $data = $request->validated();
        $name = isset($data['name']) ? trim($data['name']) : $billingProfile->name;

        if (BillingProfile::query()->where('name', $name)->whereKeyNot($billingProfile->id)->exists()) {
            return ApiResponse::error('Billing profile could not be updated.', ['name' => ['A billing profile with the same name already exists.']], 422);
        }

        DB::connection('base')->transaction(function () use ($billingProfile, $data, $name) {
            if (($data['is_default'] ?? false) === true) {
                BillingProfile::query()->where('is_default', true)->whereKeyNot($billingProfile->id)->update(['is_default' => false]);
            }

            $billingProfile->fill([
                'name' => $name,
                'description' => array_key_exists('description', $data) ? $data['description'] : $billingProfile->description,
                'billing_interval' => $data['billing_interval'] ?? $billingProfile->billing_interval,
                'trial_days' => $data['trial_days'] ?? $billingProfile->trial_days,
                'grace_days' => $data['grace_days'] ?? $billingProfile->grace_days,
                'currency' => isset($data['currency']) ? strtoupper($data['currency']) : $billingProfile->currency,
                'is_default' => $data['is_default'] ?? $billingProfile->is_default,
                'status' => $data['status'] ?? $billingProfile->status,
            ])->save();
        });

        return ApiResponse::resource(
            new BillingProfileResource($billingProfile->fresh()->loadCount('rules')),
            'Billing profile updated successfully.'
        );
    }

    /** List billing rules across profiles for admin dropdowns and rule-selection workflows. */
    /**
     * Handle index rules.
     */
    public function indexRules(BillingRuleIndexRequest $request)
    {
        $filters = $request->validated();
        $rules = $this->newBillingRuleQuery($filters)
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();

        return ApiResponse::resource(BillingRuleResource::collection($rules), 'Billing rules retrieved successfully.');
    }

    /** List the paginated pricing rules that belong to one billing profile. */
    /**
     * Handle the rules request.
     */
    public function rules(BillingRuleIndexRequest $request, BillingProfile $billingProfile)
    {
        $filters = $request->validated();
        $rules = $this->newBillingRuleQuery($filters, $billingProfile)
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();

        return ApiResponse::resource(BillingRuleResource::collection($rules), 'Billing rules retrieved successfully.');
    }

    /** Add a new unit-range pricing rule to a billing profile after overlap validation. */
    /**
     * Store rule directly under the billing-rules collection route.
     */
    public function storeRuleDirect(StoreBillingRuleRequest $request)
    {
        $data = $request->validated();
        $billingProfile = $this->resolveBillingProfileForRule($data);

        if (!empty($data['billing_profile_uuid']) && !$billingProfile) {
            return ApiResponse::error(
                'Billing rule could not be created.',
                ['billing_profile_uuid' => ['The selected billing profile could not be found.']],
                422
            );
        }

        if (!$billingProfile) {
            return ApiResponse::error(
                'Billing rule could not be created.',
                ['billing_profile_uuid' => ['No active default billing profile is configured. Create or activate a default billing profile first.']],
                422
            );
        }

        return $this->persistBillingRule($data, $billingProfile);
    }

    /**
     * Store rule.
     */
    public function storeRule(StoreBillingRuleRequest $request, BillingProfile $billingProfile)
    {
        $data = $request->validated();

        return $this->persistBillingRule($data, $billingProfile);
    }

    /** Update a billing rule while protecting the profile from overlapping active ranges. */
    /**
     * Update rule.
     */
    public function updateRule(UpdateBillingRuleRequest $request, BillingRule $billingRule)
    {
        $data = $request->validated();

        $billingRule->fill([
            'unit_price_cents' => $data['unit_price_cents'] ?? $billingRule->unit_price_cents,
            'currency' => isset($data['currency']) ? strtoupper($data['currency']) : $billingRule->currency,
            'effective_from' => $data['effective_from'] ?? $billingRule->effective_from?->toDateString(),
            'effective_to' => array_key_exists('effective_to', $data) ? $data['effective_to'] : $billingRule->effective_to?->toDateString(),
            'status' => $data['status'] ?? $billingRule->status,
        ])->save();

        return ApiResponse::resource(new BillingRuleResource($billingRule->fresh()->load('profile:id,uuid,name,billing_interval,currency,status')), 'Billing rule updated successfully.');
    }

    /**
     * Delete one billing rule.
     */
    public function destroyRule(BillingRule $billingRule)
    {
        DB::connection('base')->transaction(fn () => $billingRule->delete());

        return ApiResponse::success(ApiMessages::deleted('billing rule'));
    }

    /**
     * New billing rule query.
     */
    private function newBillingRuleQuery(array $filters, ?BillingProfile $billingProfile = null): EloquentBuilder
    {
        $query = BillingRule::query()
            ->select([
                'id',
                'uuid',
                'billing_profile_id',
                'unit_price_cents',
                'currency',
                'effective_from',
                'effective_to',
                'status',
                'created_at',
                'updated_at',
            ])
            ->with([
                'profile:id,uuid,name,billing_interval,currency,status',
            ]);

        if ($billingProfile) {
            $query->where('billing_profile_id', $billingProfile->id);
        }

        $this->applyBillingRuleFilters($query, $filters);
        $this->applyBillingRuleSort($query, $filters['sort'] ?? null);

        return $query;
    }

    /**
     * Apply billing rule filters.
     */
    private function applyBillingRuleFilters(EloquentBuilder $query, array $filters): void
    {
        if (!empty($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['effective_on'] ?? null)) {
            $query->whereDate('effective_from', '<=', $filters['effective_on'])
                ->where(function (EloquentBuilder $innerQuery) use ($filters) {
                    $innerQuery->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $filters['effective_on']);
                });
        }
    }

    /**
     * Apply billing rule sort.
     */
    private function applyBillingRuleSort(EloquentBuilder $query, ?string $sort): void
    {
        $sort = trim((string) $sort);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $key = ltrim($sort, '-');

        match ($key) {
            'unit_price_cents' => $query->orderBy('unit_price_cents', $direction)->orderByDesc('effective_from'),
            'effective_from' => $query->orderBy('effective_from', $direction)->orderByDesc('created_at'),
            'created_at' => $query->orderBy('created_at', $direction),
            default => $query->orderByDesc('effective_from')->orderByDesc('created_at'),
        };
    }

    /**
     * Persist a billing rule against the resolved profile.
     */
    private function persistBillingRule(array $data, BillingProfile $billingProfile)
    {
        $rule = BillingRule::query()->create([
            'uuid' => (string) Str::uuid(),
            'billing_profile_id' => $billingProfile->id,
            'unit_price_cents' => $data['unit_price_cents'],
            'currency' => strtoupper($data['currency'] ?? $billingProfile->currency),
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        return ApiResponse::resource(
            new BillingRuleResource($rule->load('profile:id,uuid,name,billing_interval,currency,status')),
            'Billing rule created successfully.',
            201
        );
    }

    /**
     * Resolve the billing profile used by a direct billing-rule create request.
     */
    private function resolveBillingProfileForRule(array $data): ?BillingProfile
    {
        $billingProfileUuid = $data['billing_profile_uuid'] ?? null;

        if ($billingProfileUuid) {
            return BillingProfile::query()
                ->where('uuid', $billingProfileUuid)
                ->first();
        }

        return BillingProfile::query()
            ->where('status', 'active')
            ->where('is_default', true)
            ->orderByDesc('id')
            ->first();
    }
}
