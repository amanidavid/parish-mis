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
use App\Services\V1\BillingProfileService;
use App\Support\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BillingProfileController extends Controller
{
    public function __construct(private BillingProfileService $billingProfileService)
    {
    }

    public function index(BillingProfileIndexRequest $request)
    {
        $filters = $request->validated();
        $query = BillingProfile::query()->withCount('rules');

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

    public function show(BillingProfile $billingProfile)
    {
        return ApiResponse::resource(
            new BillingProfileResource($billingProfile->loadCount('rules')),
            'Billing profile details retrieved successfully.'
        );
    }

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

    public function rules(BillingRuleIndexRequest $request, BillingProfile $billingProfile)
    {
        $filters = $request->validated();
        $query = $billingProfile->rules()->getQuery();

        if (!empty($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['effective_on'] ?? null)) {
            $query->where('effective_from', '<=', $filters['effective_on'])
                ->where(function ($innerQuery) use ($filters) {
                    $innerQuery->whereNull('effective_to')
                        ->orWhere('effective_to', '>=', $filters['effective_on']);
                });
        }

        $rules = $query->orderBy('sort_order')->orderBy('range_start')->paginate((int) ($filters['per_page'] ?? 15));

        return ApiResponse::resource(BillingRuleResource::collection($rules), 'Billing rules retrieved successfully.');
    }

    public function storeRule(StoreBillingRuleRequest $request, BillingProfile $billingProfile)
    {
        $data = $request->validated();

        if (($data['status'] ?? 'active') === 'active' && $this->billingProfileService->hasOverlappingRule($billingProfile, $data)) {
            return ApiResponse::error(
                'Billing rule could not be created.',
                ['rule' => ['An overlapping active billing rule already exists for this range and period.']],
                422
            );
        }

        $rule = BillingRule::query()->create([
            'uuid' => (string) Str::uuid(),
            'billing_profile_id' => $billingProfile->id,
            'range_start' => $data['range_start'],
            'range_end' => $data['range_end'] ?? null,
            'price_cents' => $data['price_cents'],
            'currency' => strtoupper($data['currency'] ?? $billingProfile->currency),
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'status' => $data['status'] ?? 'active',
        ]);

        return ApiResponse::resource(new BillingRuleResource($rule), 'Billing rule created successfully.', 201);
    }

    public function updateRule(UpdateBillingRuleRequest $request, BillingRule $billingRule)
    {
        $data = $request->validated();
        $payload = [
            'range_start' => $data['range_start'] ?? $billingRule->range_start,
            'range_end' => array_key_exists('range_end', $data) ? $data['range_end'] : $billingRule->range_end,
            'effective_from' => $data['effective_from'] ?? $billingRule->effective_from?->toDateString(),
            'effective_to' => array_key_exists('effective_to', $data) ? $data['effective_to'] : $billingRule->effective_to?->toDateString(),
            'status' => $data['status'] ?? $billingRule->status,
        ];

        if ($payload['status'] === 'active' && $this->billingProfileService->hasOverlappingRule($billingRule->profile, $payload, $billingRule->id)) {
            return ApiResponse::error(
                'Billing rule could not be updated.',
                ['rule' => ['An overlapping active billing rule already exists for this range and period.']],
                422
            );
        }

        $billingRule->fill([
            'range_start' => $payload['range_start'],
            'range_end' => $payload['range_end'],
            'price_cents' => $data['price_cents'] ?? $billingRule->price_cents,
            'currency' => isset($data['currency']) ? strtoupper($data['currency']) : $billingRule->currency,
            'effective_from' => $payload['effective_from'],
            'effective_to' => $payload['effective_to'],
            'sort_order' => $data['sort_order'] ?? $billingRule->sort_order,
            'status' => $payload['status'],
        ])->save();

        return ApiResponse::resource(new BillingRuleResource($billingRule->fresh()), 'Billing rule updated successfully.');
    }
}
