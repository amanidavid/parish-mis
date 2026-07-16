<?php

namespace App\Services\V1\Billing;

use App\Models\Landlord\PropertyInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class PropertyInvoicePdfService
{
    public function stream(PropertyInvoice $invoice, string $tenantName): Response
    {
        return $this->makePdf($invoice, $tenantName)->stream($this->filename($invoice));
    }

    public function download(PropertyInvoice $invoice, string $tenantName): Response
    {
        return $this->makePdf($invoice, $tenantName)->download($this->filename($invoice));
    }

    public function output(PropertyInvoice $invoice, string $tenantName): string
    {
        return $this->makePdf($invoice, $tenantName)->output();
    }

    public function filename(PropertyInvoice $invoice): string
    {
        return $invoice->invoice_number.'.pdf';
    }

    private function makePdf(PropertyInvoice $invoice, string $tenantName)
    {
        return Pdf::loadView('invoices.property-preview', [
            'invoice' => $invoice,
            'tenantName' => $tenantName,
        ])
            ->setPaper('a4')
            ->setWarnings(false)
            ->setOption([
                'defaultFont' => 'DejaVu Sans',
                'dpi' => 120,
                'isHtml5ParserEnabled' => true,
            ]);
    }
}
