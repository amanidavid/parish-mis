<?php

namespace App\Services\V1\Billing;

use App\Jobs\ResendPropertyInvoiceReminder;
use App\Models\Landlord\PropertyInvoice;
use App\Models\Landlord\PropertyInvoiceDeliveryLog;
use App\Services\V1\Messaging\SmsService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PropertyInvoiceReminderResendService
{
    private const MAX_BULK_RESEND_LIMIT = 200;

    /**
     * Create a new instance.
     */
    public function __construct(
        private PropertyInvoiceEmailService $propertyInvoiceEmailService,
        private PropertyInvoiceService $propertyInvoiceService,
        private SmsService $smsService,
    ) {
    }

    /**
     * Resend a reminder for a single invoice.
     */
    public function resendInvoiceReminder(PropertyInvoice $invoice, string $channelSelection, bool $force = true): array
    {
        $invoice->loadMissing(['tenant', 'workspaceProperty']);

        $channels = $this->selectedChannels($channelSelection);
        $channelResults = [];
        $sentCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($channels as $channel) {
            $result = $this->resendChannelForInvoice($invoice, $channel, $force);
            $channelResults[] = $result;
            $sentCount += (int) ($result['sent_count'] ?? 0);
            $failedCount += (int) ($result['failed_count'] ?? 0);
            $skippedCount += (int) ($result['skipped_count'] ?? 0);
        }

        return [
            'invoice_uuid' => $invoice->uuid,
            'invoice_number' => $invoice->invoice_number,
            'channels' => $channelResults,
            'summary' => [
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'skipped_count' => $skippedCount,
            ],
        ];
    }

    /**
     * Bulk resend reminders.
     */
    public function resendBulk(array $filters): array
    {
        $limit = min(max((int) ($filters['limit'] ?? 100), 1), self::MAX_BULK_RESEND_LIMIT);
        $invoiceIds = $this->targetInvoiceIds($filters, $limit);

        if ($invoiceIds === []) {
            return [
                'selection' => [
                    'invoice_count' => 0,
                    'limit' => $limit,
                ],
                'results' => [],
                'summary' => [
                    'processed_invoices' => 0,
                    'sent_count' => 0,
                    'failed_count' => 0,
                    'skipped_count' => 0,
                ],
            ];
        }

        /** @var EloquentCollection<int, PropertyInvoice> $invoices */
        $invoices = PropertyInvoice::query()
            ->with(['tenant', 'workspaceProperty'])
            ->whereIn('id', $invoiceIds)
            ->orderBy('id')
            ->get();

        $invoiceById = $invoices->keyBy('id');
        $results = [];
        $sentCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($invoiceIds as $invoiceId) {
            $invoice = $invoiceById->get($invoiceId);
            if (!$invoice instanceof PropertyInvoice) {
                continue;
            }

            $result = $this->resendInvoiceReminder(
                $invoice,
                (string) ($filters['channel'] ?? PropertyInvoiceDeliveryLog::CHANNEL_EMAIL),
                (bool) ($filters['force'] ?? true)
            );

            $results[] = $result;
            $sentCount += (int) data_get($result, 'summary.sent_count', 0);
            $failedCount += (int) data_get($result, 'summary.failed_count', 0);
            $skippedCount += (int) data_get($result, 'summary.skipped_count', 0);
        }

        return [
            'selection' => [
                'invoice_count' => count($invoiceIds),
                'limit' => $limit,
            ],
            'results' => $results,
            'summary' => [
                'processed_invoices' => count($results),
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'skipped_count' => $skippedCount,
            ],
        ];
    }

    /**
     * Queue a single invoice reminder resend.
     */
    public function queueInvoiceReminder(PropertyInvoice $invoice, string $channelSelection, bool $force = true): array
    {
        ResendPropertyInvoiceReminder::dispatch($invoice->id, $channelSelection, $force)->afterCommit();

        return [
            'queued' => true,
            'invoice_uuid' => $invoice->uuid,
            'invoice_number' => $invoice->invoice_number,
            'channel' => $channelSelection,
            'force' => $force,
        ];
    }

    /**
     * Queue bulk invoice reminder resends.
     */
    public function queueBulk(array $filters): array
    {
        $limit = min(max((int) ($filters['limit'] ?? 100), 1), self::MAX_BULK_RESEND_LIMIT);
        $invoiceIds = $this->targetInvoiceIds($filters, $limit);

        if ($invoiceIds === []) {
            return [
                'selection' => [
                    'invoice_count' => 0,
                    'limit' => $limit,
                ],
                'queued_count' => 0,
                'queued' => [],
            ];
        }

        /** @var EloquentCollection<int, PropertyInvoice> $invoices */
        $invoices = PropertyInvoice::query()
            ->select(['id', 'uuid', 'invoice_number'])
            ->whereIn('id', $invoiceIds)
            ->orderBy('id')
            ->get();

        $queued = [];
        foreach ($invoices as $invoice) {
            ResendPropertyInvoiceReminder::dispatch(
                (int) $invoice->id,
                (string) ($filters['channel'] ?? PropertyInvoiceDeliveryLog::CHANNEL_EMAIL),
                (bool) ($filters['force'] ?? true)
            )->afterCommit();

            $queued[] = [
                'invoice_uuid' => $invoice->uuid,
                'invoice_number' => $invoice->invoice_number,
            ];
        }

        return [
            'selection' => [
                'invoice_count' => count($invoiceIds),
                'limit' => $limit,
            ],
            'queued_count' => count($queued),
            'queued' => $queued,
            'channel' => (string) ($filters['channel'] ?? PropertyInvoiceDeliveryLog::CHANNEL_EMAIL),
            'force' => (bool) ($filters['force'] ?? true),
        ];
    }

    /**
     * Resolve bulk invoice ids.
     *
     * @return array<int, int>
     */
    private function targetInvoiceIds(array $filters, int $limit): array
    {
        $invoiceUuids = collect($filters['invoice_uuids'] ?? [])
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($invoiceUuids !== []) {
            return PropertyInvoice::query()
                ->whereIn('uuid', $invoiceUuids)
                ->orderBy('id')
                ->limit($limit)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $deliveryLogUuids = collect($filters['delivery_log_uuids'] ?? [])
            ->filter()
            ->unique()
            ->values()
            ->all();

        $hasFilterScope = $deliveryLogUuids !== []
            || !blank($filters['search'] ?? null)
            || !blank($filters['delivery_status'] ?? null)
            || !blank($filters['source_channel'] ?? null)
            || !blank($filters['date_from'] ?? null)
            || !blank($filters['date_to'] ?? null)
            || !blank($filters['tenant_uuid'] ?? null);

        if (!$hasFilterScope) {
            throw new InvalidArgumentException(
                'Bulk resend needs invoice UUIDs, delivery log UUIDs, or at least one filter such as channel, status, search, tenant, or date range.'
            );
        }

        $query = DB::connection('base')->table('property_invoice_delivery_logs as delivery_logs')
            ->join('property_invoices', 'property_invoices.id', '=', 'delivery_logs.property_invoice_id')
            ->select('property_invoices.id')
            ->selectRaw('MAX(delivery_logs.last_attempt_at) as latest_attempt_at')
            ->selectRaw('MAX(delivery_logs.id) as latest_log_id')
            ->whereIn('delivery_logs.kind', [
                PropertyInvoiceDeliveryLog::KIND_REMINDER_EMAIL,
                PropertyInvoiceDeliveryLog::KIND_REMINDER_SMS,
            ]);

        if ($deliveryLogUuids !== []) {
            $query->whereIn('delivery_logs.uuid', $deliveryLogUuids);
        }

        if (!blank($filters['delivery_status'] ?? null)) {
            $query->where('delivery_logs.status', $filters['delivery_status']);
        }

        if (!blank($filters['source_channel'] ?? null)) {
            $query->where('delivery_logs.channel', $filters['source_channel']);
        }

        if (!blank($filters['date_from'] ?? null)) {
            $query->where('delivery_logs.last_attempt_at', '>=', Carbon::parse((string) $filters['date_from'])->startOfDay());
        }

        if (!blank($filters['date_to'] ?? null)) {
            $query->where('delivery_logs.last_attempt_at', '<=', Carbon::parse((string) $filters['date_to'])->endOfDay());
        }

        if (!blank($filters['tenant_uuid'] ?? null)) {
            $query
                ->join('tenants', 'tenants.id', '=', 'property_invoices.tenant_id')
                ->where('tenants.uuid', $filters['tenant_uuid']);
        }

        if (!blank($filters['search'] ?? null)) {
            $search = trim((string) $filters['search']);
            $searchBy = (string) ($filters['search_by'] ?? 'invoice_number');

            if ($searchBy === 'recipient_address') {
                $query->where('delivery_logs.recipient_address', 'like', $search.'%');
            } else {
                $query->where('property_invoices.invoice_number', 'like', $search.'%');
            }
        }

        return $query
            ->groupBy('property_invoices.id')
            ->orderByDesc('latest_attempt_at')
            ->orderByDesc('latest_log_id')
            ->limit($limit)
            ->pluck('property_invoices.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Resend a single channel for an invoice.
     */
    private function resendChannelForInvoice(PropertyInvoice $invoice, string $channel, bool $force): array
    {
        $latestLog = $this->latestReminderLog($invoice, $channel);
        [$subject, $message] = $this->subjectAndMessage($invoice, $channel, $latestLog);
        $recipients = $this->recipientsForChannel($invoice, $channel, $latestLog);

        if ($recipients === []) {
            return [
                'channel' => $channel,
                'sent_count' => 0,
                'failed_count' => 0,
                'skipped_count' => 1,
                'items' => [],
                'message' => sprintf('No %s reminder recipient was available for invoice %s.', $channel, $invoice->invoice_number),
            ];
        }

        $items = [];
        $sentCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        foreach ($recipients as $recipient) {
            $address = trim((string) ($recipient['address'] ?? ''));
            $name = $recipient['name'] ?? null;

            if ($address === '') {
                $skippedCount++;
                $items[] = [
                    'channel' => $channel,
                    'recipient_address' => '',
                    'status' => 'skipped',
                    'message' => 'Recipient address is missing.',
                ];

                continue;
            }

            if (!$force && $this->propertyInvoiceService->hasSuccessfulDelivery($invoice, $channel, $address, $this->kindForChannel($channel))) {
                $skippedCount++;
                $items[] = [
                    'channel' => $channel,
                    'recipient_address' => $address,
                    'status' => 'skipped',
                    'message' => 'A successful reminder already exists for this invoice, channel, and recipient.',
                ];

                continue;
            }

            $result = $channel === PropertyInvoiceDeliveryLog::CHANNEL_EMAIL
                ? $this->propertyInvoiceEmailService->sendReminderInvoice($invoice, $address, $name, $subject, $message)
                : $this->sendReminderSms($invoice, $address, $name, $subject, $message);

            if (($result['status'] ?? null) === PropertyInvoiceDeliveryLog::STATUS_SENT) {
                $sentCount++;
            } else {
                $failedCount++;
            }

            $items[] = [
                'channel' => $channel,
                'recipient_address' => $address,
                'status' => $result['status'] ?? PropertyInvoiceDeliveryLog::STATUS_FAILED,
                'message' => $result['message'] ?? null,
            ];
        }

        return [
            'channel' => $channel,
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'skipped_count' => $skippedCount,
            'items' => $items,
            'message' => sprintf(
                '%s reminder resend processed for invoice %s.',
                strtoupper($channel),
                $invoice->invoice_number
            ),
        ];
    }

    /**
     * Send reminder SMS and store the result.
     */
    private function sendReminderSms(
        PropertyInvoice $invoice,
        string $recipientAddress,
        ?string $recipientName,
        string $subject,
        string $message
    ): array {
        try {
            $this->smsService->sendText($recipientAddress, $message, null, [
                'type' => 'property_invoice_reminder',
                'invoice_uuid' => $invoice->uuid,
                'property_uuid' => $invoice->property_uuid,
            ]);

            $this->propertyInvoiceService->markInvoiceDelivery(
                $invoice,
                PropertyInvoiceDeliveryLog::CHANNEL_SMS,
                PropertyInvoiceDeliveryLog::STATUS_SENT,
                $recipientAddress,
                $recipientName,
                $subject,
                $message,
                ['kind' => PropertyInvoiceDeliveryLog::KIND_REMINDER_SMS]
            );

            return [
                'status' => PropertyInvoiceDeliveryLog::STATUS_SENT,
                'message' => 'Reminder invoice SMS sent.',
            ];
        } catch (\Throwable $exception) {
            $this->propertyInvoiceService->markInvoiceDelivery(
                $invoice,
                PropertyInvoiceDeliveryLog::CHANNEL_SMS,
                PropertyInvoiceDeliveryLog::STATUS_FAILED,
                $recipientAddress,
                $recipientName,
                $subject,
                $exception->getMessage(),
                ['kind' => PropertyInvoiceDeliveryLog::KIND_REMINDER_SMS]
            );

            return [
                'status' => PropertyInvoiceDeliveryLog::STATUS_FAILED,
                'message' => $exception->getMessage(),
            ];
        }
    }

    /**
     * Resolve recipients for the selected channel.
     *
     * @return array<int, array{name: ?string, address: string}>
     */
    private function recipientsForChannel(PropertyInvoice $invoice, string $channel, ?PropertyInvoiceDeliveryLog $latestLog): array
    {
        if ($latestLog instanceof PropertyInvoiceDeliveryLog && trim((string) $latestLog->recipient_address) !== '') {
            return [[
                'name' => $latestLog->recipient_name,
                'address' => trim((string) $latestLog->recipient_address),
            ]];
        }

        $resolvedRecipients = collect($this->propertyInvoiceEmailService->resolveReminderRecipients($invoice))
            ->map(function (array $recipient) use ($channel): ?array {
                $address = $channel === PropertyInvoiceDeliveryLog::CHANNEL_EMAIL
                    ? trim((string) ($recipient['email'] ?? ''))
                    : trim((string) ($recipient['phone'] ?? ''));

                if ($address === '') {
                    return null;
                }

                if ($channel === PropertyInvoiceDeliveryLog::CHANNEL_SMS && !$this->smsService->supportsRecipient($address)) {
                    return null;
                }

                return [
                    'name' => $recipient['name'] ?? null,
                    'address' => $address,
                ];
            })
            ->filter()
            ->unique(fn (array $recipient) => mb_strtolower($recipient['address'], 'UTF-8'))
            ->values()
            ->all();

        return $resolvedRecipients;
    }

    /**
     * Resolve the latest reminder log for a channel.
     */
    private function latestReminderLog(PropertyInvoice $invoice, string $channel): ?PropertyInvoiceDeliveryLog
    {
        return PropertyInvoiceDeliveryLog::query()
            ->where('property_invoice_id', $invoice->id)
            ->where('channel', $channel)
            ->where('kind', $this->kindForChannel($channel))
            ->orderByDesc('last_attempt_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Resolve the reminder subject and body.
     *
     * @return array{0: string, 1: string}
     */
    private function subjectAndMessage(
        PropertyInvoice $invoice,
        string $channel,
        ?PropertyInvoiceDeliveryLog $latestLog
    ): array {
        $subject = trim((string) ($latestLog?->subject ?? ''));
        $message = trim((string) ($latestLog?->message ?? ''));

        if ($subject !== '' && $message !== '') {
            return [$subject, $message];
        }

        $propertyName = (string) ($invoice->workspaceProperty?->property_name ?? data_get($invoice->meta, 'property_name', 'Property'));
        $formattedAmount = sprintf('%s %s', $invoice->currency, number_format((int) $invoice->total_amount_cents));
        $dueDate = $invoice->due_date?->toDateString();
        $subject = sprintf('Invoice %s for %s', $invoice->invoice_number, $propertyName);
        $message = $invoice->due_date && $invoice->due_date->isToday()
            ? sprintf(
                'Invoice %s for %s is due today. Amount due: %s.',
                $invoice->invoice_number,
                $propertyName,
                $formattedAmount
            )
            : sprintf(
                'Invoice %s for %s is ready. Amount due: %s. Due date: %s.',
                $invoice->invoice_number,
                $propertyName,
                $formattedAmount,
                $dueDate
            );

        return [$subject, $message];
    }

    /**
     * Determine delivery kind by channel.
     */
    private function kindForChannel(string $channel): string
    {
        return $channel === PropertyInvoiceDeliveryLog::CHANNEL_SMS
            ? PropertyInvoiceDeliveryLog::KIND_REMINDER_SMS
            : PropertyInvoiceDeliveryLog::KIND_REMINDER_EMAIL;
    }

    /**
     * Normalize selected channels.
     *
     * @return array<int, string>
     */
    private function selectedChannels(string $channelSelection): array
    {
        return match ($channelSelection) {
            'both' => [PropertyInvoiceDeliveryLog::CHANNEL_EMAIL, PropertyInvoiceDeliveryLog::CHANNEL_SMS],
            PropertyInvoiceDeliveryLog::CHANNEL_SMS => [PropertyInvoiceDeliveryLog::CHANNEL_SMS],
            default => [PropertyInvoiceDeliveryLog::CHANNEL_EMAIL],
        };
    }
}
