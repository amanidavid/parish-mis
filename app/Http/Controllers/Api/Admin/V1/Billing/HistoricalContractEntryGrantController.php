<?php

namespace App\Http\Controllers\Api\Admin\V1\Billing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\V1\Billing\HistoricalContractEntryGrantIndexRequest;
use App\Http\Requests\Api\Admin\V1\Billing\RevokeHistoricalContractEntryGrantRequest;
use App\Http\Requests\Api\Admin\V1\Billing\StoreHistoricalContractEntryGrantRequest;
use App\Http\Resources\Admin\V1\Billing\HistoricalContractEntryGrantResource;
use App\Models\Landlord\BaseUser;
use App\Models\Tenancy\Tenant;
use App\Services\V1\Billing\HistoricalContractEntryGrantService;
use App\Support\ApiResponse;
use InvalidArgumentException;

class HistoricalContractEntryGrantController extends Controller
{
    public function __construct(
        private HistoricalContractEntryGrantService $historicalContractEntryGrantService,
    ) {
    }

    public function index(HistoricalContractEntryGrantIndexRequest $request, Tenant $tenant)
    {
        return ApiResponse::resource(
            HistoricalContractEntryGrantResource::collection(
                $this->historicalContractEntryGrantService->paginateForTenant($tenant, $request->validated())
            ),
            'Historical contract entry grants retrieved successfully.'
        );
    }

    public function store(StoreHistoricalContractEntryGrantRequest $request, Tenant $tenant)
    {
        try {
            $grant = $this->historicalContractEntryGrantService->create(
                $tenant,
                $request->validated(),
                request()->user() instanceof BaseUser ? request()->user() : null
            );
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(
                'Historical contract entry grant could not be created.',
                ['grant' => [$exception->getMessage()]],
                422
            );
        }

        return ApiResponse::resource(
            new HistoricalContractEntryGrantResource($grant),
            'Historical contract entry grant created successfully.',
            201
        );
    }

    public function revoke(RevokeHistoricalContractEntryGrantRequest $request, Tenant $tenant, string $grantUuid)
    {
        try {
            $grant = $this->historicalContractEntryGrantService->revoke(
                $tenant,
                $grantUuid,
                $request->validated()['reason'],
                request()->user() instanceof BaseUser ? request()->user() : null
            );
        } catch (InvalidArgumentException $exception) {
            return ApiResponse::error(
                'Historical contract entry grant could not be revoked.',
                ['grant' => [$exception->getMessage()]],
                422
            );
        }

        return ApiResponse::resource(
            new HistoricalContractEntryGrantResource($grant),
            'Historical contract entry grant revoked successfully.'
        );
    }
}
