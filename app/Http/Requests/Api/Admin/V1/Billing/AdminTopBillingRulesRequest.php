<?php

namespace App\Http\Requests\Api\Admin\V1\Billing;

class AdminTopBillingRulesRequest extends AdminAnalyticsTrendRequest
{
    public function rules(): array
    {
        // Extends the shared trend filters with a small capped limit for composition charts.
        return array_merge(parent::rules(), [
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);
    }
}
