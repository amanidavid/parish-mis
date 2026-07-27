<?php

namespace App\Services\V1\Billing;

use App\Models\Landlord\PropertyInvoice;
use App\Models\Landlord\PropertyInvoiceDeliveryLog;
use App\Models\Tenancy\Tenant;
use App\Services\V1\Occupancy\ContractAlertRecipientResolver;
use App\Support\Tenancy\TenantConnectionManager;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class PropertyInvoiceEmailService
{
    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    private array $recipientCache = [];

    /**
     * @var array<string, array<string, bool>>
     */
    private array $successfulDeliveryCache = [];

    public function __construct(
        private PropertyInvoicePdfService $propertyInvoicePdfService,
        private PropertyInvoiceService $propertyInvoiceService,
        private TenantConnectionManager $tenantConnectionManager,
        private ContractAlertRecipientResolver $recipientResolver,
    ) {
    }

    public function sendPaidInvoice(PropertyInvoice $invoice): void
    {
        $invoice->loadMissing(['workspaceProperty', 'tenant']);

        $propertyName = (string) ($invoice->workspaceProperty?->property_name ?? data_get($invoice->meta, 'property_name', 'Property'));
        $tenantName = (string) data_get($invoice->meta, 'tenant_full_name', $invoice->tenant?->display_name ?: $invoice->tenant?->name ?: 'Workspace');
        $subject = sprintf('Invoice %s for %s', $invoice->invoice_number, $propertyName);
        $message = sprintf(
            "Hello,\n\nYour payment for %s has been recorded successfully.\nInvoice number: %s\nAmount paid: %s %s\nBilling period: %s to %s\nStatus: %s\n\nYour invoice PDF is attached.\n\nPlease do not reply to this email. This mailbox is not monitored.\n\nRegards,\nZABA Team",
            $propertyName,
            $invoice->invoice_number,
            $invoice->currency,
            number_format((int) $invoice->total_amount_cents),
            optional($invoice->period_starts_on)->toDateString(),
            optional($invoice->period_ends_on)->toDateString(),
            ucfirst((string) $invoice->status)
        );

        $recipients = collect($this->resolveReminderRecipients($invoice))
            ->filter(fn (array $recipient) => trim((string) ($recipient['email'] ?? '')) !== '')
            ->values()
            ->all();

        if ($recipients === []) {
            $this->propertyInvoiceService->markInvoiceDelivery(
                $invoice,
                'email',
                'failed',
                '',
                null,
                $subject,
                'Invoice email could not be sent because no matching staff email was found.',
                ['kind' => PropertyInvoiceDeliveryLog::KIND_PAID_EMAIL]
            );

            return;
        }

        try {
            $pdf = $this->propertyInvoicePdfService->output($invoice, $tenantName);
            $filename = $this->propertyInvoicePdfService->filename($invoice);
        } catch (Throwable $exception) {
            Log::error('Property invoice email failed.', [
                'invoice_uuid' => $invoice->uuid,
                'invoice_number' => $invoice->invoice_number,
                'error' => $exception->getMessage(),
            ]);

            $this->propertyInvoiceService->markInvoiceDelivery(
                $invoice,
                'email',
                'failed',
                '',
                null,
                $subject,
                'Invoice email could not be sent right now.',
                ['kind' => PropertyInvoiceDeliveryLog::KIND_PAID_EMAIL]
            );

            return;
        }

        $successfulRecipients = $this->successfulDeliveryRecipients($invoice, PropertyInvoiceDeliveryLog::KIND_PAID_EMAIL);

        foreach ($recipients as $recipient) {
            $recipientEmail = trim((string) ($recipient['email'] ?? ''));
            $recipientName = trim((string) ($recipient['name'] ?? ''));

            if ($recipientEmail !== '' && isset($successfulRecipients[Str::lower($recipientEmail)])) {
                continue;
            }

            if (!$this->isDeliverableEmail($recipientEmail)) {
                $this->propertyInvoiceService->markInvoiceDelivery(
                    $invoice,
                    'email',
                    'failed',
                    $recipientEmail,
                    $recipientName !== '' ? $recipientName : null,
                    $subject,
                    'Invoice email was not sent because the email address is not valid for delivery.',
                    ['kind' => self::KIND_PAID]
                );

                continue;
            }

            try {
                Mail::raw($message, function ($mail) use ($recipientEmail, $recipientName, $subject, $pdf, $filename) {
                    $mail->to($recipientEmail, $recipientName !== '' ? $recipientName : 'Recipient')
                        ->subject($subject)
                        ->attachData($pdf, $filename, [
                            'mime' => 'application/pdf',
                        ]);
                });

                $this->propertyInvoiceService->markInvoiceDelivery(
                    $invoice,
                    'email',
                    'sent',
                    $recipientEmail,
                    $recipientName !== '' ? $recipientName : null,
                    $subject,
                    'Paid invoice email sent.',
                    ['kind' => PropertyInvoiceDeliveryLog::KIND_PAID_EMAIL]
                );

                if ($recipientEmail !== '') {
                    $successfulRecipients[Str::lower($recipientEmail)] = true;
                    $this->successfulDeliveryCache[$this->successfulDeliveryCacheKey($invoice, PropertyInvoiceDeliveryLog::KIND_PAID_EMAIL)] = $successfulRecipients;
                }
            } catch (Throwable $exception) {
                Log::error('Property invoice email failed.', [
                    'invoice_uuid' => $invoice->uuid,
                    'invoice_number' => $invoice->invoice_number,
                    'recipient_email' => $recipientEmail,
                    'error' => $exception->getMessage(),
                ]);

                $this->propertyInvoiceService->markInvoiceDelivery(
                    $invoice,
                    'email',
                    'failed',
                    $recipientEmail,
                    $recipientName !== '' ? $recipientName : null,
                    $subject,
                    'Invoice email could not be sent to this recipient right now.',
                    ['kind' => PropertyInvoiceDeliveryLog::KIND_PAID_EMAIL]
                );
            }
        }
    }

    public function sendReminderInvoice(PropertyInvoice $invoice, string $recipientEmail, ?string $recipientName, string $subject, string $message): array
    {
        $invoice->loadMissing(['workspaceProperty', 'tenant']);

        $recipientEmail = trim($recipientEmail);
        $recipientName = trim((string) $recipientName);

        if ($recipientEmail === '') {
            $this->propertyInvoiceService->markInvoiceDelivery(
                $invoice,
                'email',
                'failed',
                '',
                $recipientName !== '' ? $recipientName : null,
                $subject,
                'Reminder email could not be sent because the email address is missing.',
                ['kind' => PropertyInvoiceDeliveryLog::KIND_REMINDER_EMAIL]
            );

            return [
                'status' => PropertyInvoiceDeliveryLog::STATUS_FAILED,
                'message' => 'Reminder email could not be sent because the email address is missing.',
            ];
        }

        if (!$this->isDeliverableEmail($recipientEmail)) {
            $this->propertyInvoiceService->markInvoiceDelivery(
                $invoice,
                'email',
                'failed',
                $recipientEmail,
                $recipientName !== '' ? $recipientName : null,
                $subject,
                'Reminder email was not sent because the email address is not valid for delivery.',
                ['kind' => PropertyInvoiceDeliveryLog::KIND_REMINDER_EMAIL]
            );

            return [
                'status' => PropertyInvoiceDeliveryLog::STATUS_FAILED,
                'message' => 'Reminder email was not sent because the email address is not valid for delivery.',
            ];
        }

        $tenantName = (string) data_get($invoice->meta, 'tenant_full_name', $invoice->tenant?->display_name ?: $invoice->tenant?->name ?: 'Workspace');
        $emailBody = "Hello,\n\n"
            .$message
            ."\n\nThe invoice PDF is attached."
            ."\n\nPlease do not reply to this email. This mailbox is not monitored."
            ."\n\nRegards,\nZABA Team";

        try {
            $pdf = $this->propertyInvoicePdfService->output($invoice, $tenantName);
            $filename = $this->propertyInvoicePdfService->filename($invoice);

            Mail::raw($emailBody, function ($mail) use ($recipientEmail, $recipientName, $subject, $pdf, $filename) {
                $mail->to($recipientEmail, $recipientName !== '' ? $recipientName : 'Recipient')
                    ->subject($subject)
                    ->attachData($pdf, $filename, [
                        'mime' => 'application/pdf',
                    ]);
            });

            $this->propertyInvoiceService->markInvoiceDelivery(
                $invoice,
                'email',
                'sent',
                $recipientEmail,
                $recipientName !== '' ? $recipientName : null,
                $subject,
                'Reminder invoice email sent.',
                ['kind' => PropertyInvoiceDeliveryLog::KIND_REMINDER_EMAIL]
            );

            return [
                'status' => PropertyInvoiceDeliveryLog::STATUS_SENT,
                'message' => 'Reminder invoice email sent.',
            ];
        } catch (Throwable $exception) {
            Log::error('Reminder invoice email failed.', [
                'invoice_uuid' => $invoice->uuid,
                'invoice_number' => $invoice->invoice_number,
                'recipient_email' => $recipientEmail,
                'error' => $exception->getMessage(),
            ]);

            $this->propertyInvoiceService->markInvoiceDelivery(
                $invoice,
                'email',
                'failed',
                $recipientEmail,
                $recipientName !== '' ? $recipientName : null,
                $subject,
                'Reminder invoice email could not be sent right now.',
                ['kind' => PropertyInvoiceDeliveryLog::KIND_REMINDER_EMAIL]
            );

            return [
                'status' => PropertyInvoiceDeliveryLog::STATUS_FAILED,
                'message' => $exception->getMessage(),
            ];
        }
    }

    public function resolveReminderRecipients(PropertyInvoice $invoice): array
    {
        $tenant = $invoice->tenant;

        if (!$tenant instanceof Tenant || blank($invoice->property_uuid)) {
            return [];
        }

        $cacheKey = $this->recipientCacheKey($tenant->id, (string) $invoice->property_uuid);

        if (array_key_exists($cacheKey, $this->recipientCache)) {
            return $this->recipientCache[$cacheKey];
        }

        $currentTenant = Tenant::current();
        $this->tenantConnectionManager->activateTenant($tenant);

        try {
            $propertyId = DB::connection($this->tenantConnectionManager->connectionName())
                ->table('properties')
                ->where('uuid', $invoice->property_uuid)
                ->value('id');

            if (!$propertyId) {
                return $this->recipientCache[$cacheKey] = [];
            }

            return $this->recipientCache[$cacheKey] = collect($this->recipientResolver->resolveForPropertiesWithPermissions(
                [(int) $propertyId],
                (array) config('property_subscription_alerts.staff_permissions', [])
            )[(int) $propertyId] ?? [])
                ->unique(fn (array $recipient) => (string) ($recipient['recipient_key'] ?? ''))
                ->values()
                ->all();
        } finally {
            $this->tenantConnectionManager->restoreTenant($currentTenant);
        }
    }

    private function isDeliverableEmail(string $email): bool
    {
        $normalized = Str::lower(trim($email));

        if ($normalized === '' || !filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $domain = Str::after($normalized, '@');

        return !in_array($domain, [
            'example.com',
            'example.org',
            'example.net',
            'invalid',
            'localhost',
            'test',
        ], true);
    }

    /**
     * @return array<string, bool>
     */
    private function successfulDeliveryRecipients(PropertyInvoice $invoice, string $kind): array
    {
        $cacheKey = $this->successfulDeliveryCacheKey($invoice, $kind);

        if (array_key_exists($cacheKey, $this->successfulDeliveryCache)) {
            return $this->successfulDeliveryCache[$cacheKey];
        }

        return $this->successfulDeliveryCache[$cacheKey] = DB::connection('base')
            ->table('property_invoice_delivery_logs')
            ->where('property_invoice_id', $invoice->id)
            ->where('channel', PropertyInvoiceDeliveryLog::CHANNEL_EMAIL)
            ->where('status', PropertyInvoiceDeliveryLog::STATUS_SENT)
            ->where('kind', $kind)
            ->pluck('recipient_address')
            ->filter(fn ($address) => trim((string) $address) !== '')
            ->mapWithKeys(fn ($address) => [Str::lower(trim((string) $address)) => true])
            ->all();
    }

    private function recipientCacheKey(int $tenantId, string $propertyUuid): string
    {
        return $tenantId.':'.$propertyUuid;
    }

    private function successfulDeliveryCacheKey(PropertyInvoice $invoice, string $kind): string
    {
        return $invoice->id.':'.$kind;
    }
}
