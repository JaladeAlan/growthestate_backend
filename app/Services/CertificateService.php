<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Purchase;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CertificateService
{
    public function issue(Purchase $purchase): Certificate
    {
        $user = $purchase->user;
        $land = $purchase->land;

        if ($purchase->units <= 0) {
            Certificate::where('purchase_id', $purchase->id)
                ->update(['status' => 'revoked', 'revoked_at' => now()]);

            return Certificate::where('purchase_id', $purchase->id)->firstOrFail();
        }

        $existing = Certificate::where('purchase_id', $purchase->id)->first();

        if ($existing) {
            $existing->update([
                'units'             => $purchase->units,
                'total_invested'    => $purchase->total_amount_paid_kobo / 100,
                'status'            => 'active',
                'digital_signature' => $this->generateSignature(
                    $existing->cert_number,
                    $purchase->reference,
                    $user->name
                ),
                'last_updated_at'   => now(),
            ]);

            $this->safeGeneratePdf($existing->fresh());

            return $existing->fresh();
        }

        $certNumber = $this->generateCertNumber($land->id);

        $certificate = Certificate::create([
            'user_id'            => $user->id,
            'land_id'            => $land->id,
            'purchase_id'        => $purchase->id,
            'cert_number'        => $certNumber,
            'digital_signature'  => $this->generateSignature(
                $certNumber,
                $purchase->reference,
                $user->name
            ),
            'owner_name'         => $user->name,
            'units'              => $purchase->units,
            'total_invested'     => $purchase->total_amount_paid_kobo / 100,
            'purchase_reference' => $purchase->reference,
            'property_title'     => $land->title,
            'property_location'  => $land->location,
            'plot_identifier'    => $land->plot_identifier,
            'tenure'             => $land->tenure,
            'lga'                => $land->lga,
            'state'              => $land->state,
            'status'             => 'active',
            'issued_at'          => now(),
            'last_updated_at'    => now(),
        ]);

        $this->safeGeneratePdf($certificate->fresh());

        return $certificate->fresh();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Verify a certificate by cert_number (public, no auth).
    // Returns the Certificate model when active + signature matches, else null.
    // ─────────────────────────────────────────────────────────────────────────
    public function verify(string $certNumber): ?Certificate
    {
        $cert = Certificate::where('cert_number', $certNumber)
            ->where('status', 'active')
            ->first();

        if (! $cert) return null;

        $expected = $this->generateSignature(
            $cert->cert_number,
            $cert->purchase_reference,
            $cert->owner_name
        );

        return hash_equals($expected, $cert->digital_signature) ? $cert : null;
    }

    public function regenerateSignature(Certificate $certificate): void
    {
        $certificate->update([
            'digital_signature' => $this->generateSignature(
                $certificate->cert_number,
                $certificate->purchase_reference,
                $certificate->owner_name
            ),
        ]);

        Log::info('Certificate signature regenerated', [
            'cert_id'     => $certificate->id,
            'cert_number' => $certificate->cert_number,
        ]);
    }

    public function regeneratePdf(Certificate $certificate): string
    {
        $path = $this->generatePdf($certificate);
        $certificate->update(['pdf_path' => $path]);
        return $path;
    }

    public function renderPdfBytes(Certificate $certificate): string
    {
        $dompdf = $this->makeDompdf();
        $dompdf->loadHtml($this->buildHtml($certificate));
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE
    // ─────────────────────────────────────────────────────────────────────────

    private function safeGeneratePdf(Certificate $certificate): void
    {
        try {
            $path = $this->generatePdf($certificate);
            $certificate->update(['pdf_path' => $path]);
        } catch (\Throwable $e) {
            Log::error('Certificate PDF generation failed', [
                'cert_id' => $certificate->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function makeDompdf(): Dompdf
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        return new Dompdf($options);
    }

    private function generateCertNumber(int $landId): string
    {
        $year = now()->year;
        $land = 'L' . str_pad($landId, 4, '0', STR_PAD_LEFT);
        $seq  = str_pad(
            Certificate::where('land_id', $landId)->count() + 1,
            5, '0', STR_PAD_LEFT
        );
        return "CERT-{$year}-{$land}-{$seq}";
    }

    private function generateSignature(string $certNumber, string $reference, string $owner): string
    {
        $raw = "{$certNumber}|{$reference}|{$owner}";
        return strtoupper(hash_hmac('sha256', $raw, config('app.key')));
    }

    private function generatePdf(Certificate $certificate): string
    {
        $dir = storage_path('app/private/certificates');
        if (! is_dir($dir)) mkdir($dir, 0755, true);

        $filename = Str::slug($certificate->cert_number) . '.pdf';
        $fullPath = "{$dir}/{$filename}";

        file_put_contents($fullPath, $this->renderPdfBytes($certificate));

        return "private/certificates/{$filename}";
    }

    private function buildHtml(Certificate $c): string
    {
        $issueDate = $c->issued_at
            ? \Carbon\Carbon::parse($c->issued_at)->format('d F Y')
            : '—';

        $lastUpdated = (
            $c->last_updated_at &&
            \Carbon\Carbon::parse($c->last_updated_at)->ne(\Carbon\Carbon::parse($c->issued_at))
        )
            ? \Carbon\Carbon::parse($c->last_updated_at)->format('d F Y')
            : null;

        $total          = '&#8358;' . number_format((float) $c->total_invested, 2);
        $units          = number_format((int) $c->units);
        $plotIdentifier = htmlspecialchars($c->plot_identifier      ?? '—', ENT_QUOTES, 'UTF-8');
        $tenure         = htmlspecialchars(ucfirst(strtolower($c->tenure ?? '—')), ENT_QUOTES, 'UTF-8');
        $lga            = htmlspecialchars($c->lga                  ?? '—', ENT_QUOTES, 'UTF-8');
        $state          = htmlspecialchars($c->state                ?? '—', ENT_QUOTES, 'UTF-8');
        $title          = htmlspecialchars($c->property_title,    ENT_QUOTES, 'UTF-8');
        $location       = htmlspecialchars($c->property_location, ENT_QUOTES, 'UTF-8');
        $owner          = htmlspecialchars($c->owner_name,        ENT_QUOTES, 'UTF-8');
        $certNumber     = htmlspecialchars($c->cert_number,        ENT_QUOTES, 'UTF-8');
        $purchaseRef    = htmlspecialchars($c->purchase_reference,  ENT_QUOTES, 'UTF-8');
        $signature      = htmlspecialchars($c->digital_signature,   ENT_QUOTES, 'UTF-8');
        $verifyUrl      = htmlspecialchars(
            config('app.frontend_url') . '/verify',
            ENT_QUOTES, 'UTF-8'
        );

        $updatedRow = $lastUpdated
            ? '<tr>
                <td class="label">Last Updated</td>
                <td class="value">' . htmlspecialchars($lastUpdated, ENT_QUOTES, 'UTF-8') . '</td>
               </tr>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>

@page {
    size: A4 portrait;
    margin: 22px 22px 22px 22px;
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    font-family: "DejaVu Sans", sans-serif;
    background: #0D1F1A;
    color: #FFFFFF;
    width: 750px;
    font-size: 8px;
}

.frame {
    width: 750px;
    border: 1.8px solid #C8873A;
    padding: 3px;
}
.frame-inner {
    width: 100%;
    border: 0.5px solid rgba(200,135,58,0.30);
    padding: 0;
}

.header {
    background: #091510;
    text-align: center;
    padding: 22px 28px 18px;
    border-bottom: 1px solid rgba(200,135,58,0.22);
}
.header-bar {
    height: 3px;
    background: #C8873A;
    margin-bottom: 18px;
}
.brand {
    font-size: 8px;
    font-weight: bold;
    letter-spacing: 0.38em;
    color: #C8873A;
    text-transform: uppercase;
    margin-bottom: 8px;
}
.cert-title {
    font-size: 20px;
    font-weight: bold;
    color: #FFFFFF;
    letter-spacing: 0.04em;
    margin-bottom: 5px;
}
.cert-subtitle {
    font-size: 7px;
    letter-spacing: 0.20em;
    color: rgba(200,135,58,0.65);
    text-transform: uppercase;
}

.body {
    padding: 18px 22px 16px;
}

.divider {
    height: 0.5px;
    background: rgba(200,135,58,0.28);
    margin: 14px 0;
}

.declaration {
    text-align: center;
    padding: 6px 0;
}
.declaration .intro {
    font-size: 8.5px;
    color: rgba(255,255,255,0.38);
    font-style: italic;
    margin-bottom: 5px;
}
.declaration .owner-name {
    font-size: 20px;
    font-weight: bold;
    color: #E8A850;
    margin-bottom: 5px;
}
.declaration .verb {
    font-size: 8.5px;
    color: rgba(255,255,255,0.38);
    font-style: italic;
    margin-bottom: 6px;
}
.declaration .unit-count {
    font-size: 40px;
    font-weight: bold;
    color: #FFFFFF;
    line-height: 1;
    margin-bottom: 2px;
}
.declaration .unit-label {
    font-size: 7.5px;
    font-weight: bold;
    letter-spacing: 0.28em;
    color: rgba(255,255,255,0.30);
    text-transform: uppercase;
    margin-bottom: 7px;
}
.declaration .in-label {
    font-size: 8.5px;
    color: rgba(255,255,255,0.38);
    font-style: italic;
    margin-bottom: 5px;
}
.declaration .property-name {
    font-size: 12px;
    font-weight: bold;
    color: #C8873A;
    margin-bottom: 3px;
}
.declaration .property-location {
    font-size: 7.5px;
    color: rgba(255,255,255,0.32);
}

.details-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}
.details-table td {
    padding: 5px 0;
    border-bottom: 0.5px solid rgba(255,255,255,0.05);
    vertical-align: top;
    word-break: break-word;
    overflow-wrap: break-word;
}
.details-table tr:last-child td {
    border-bottom: none;
}
.details-table .label {
    font-size: 6.5px;
    font-weight: bold;
    letter-spacing: 0.16em;
    color: rgba(200,135,58,0.65);
    text-transform: uppercase;
    width: 36%;
    padding-right: 10px;
}
.details-table .value {
    font-size: 8px;
    color: rgba(255,255,255,0.75);
    text-align: right;
}
.details-table .value-highlight {
    font-size: 8.5px;
    font-weight: bold;
    color: #E8A850;
    text-align: right;
}

.signature-block {
    background: rgba(255,255,255,0.02);
    border: 0.5px solid rgba(255,255,255,0.07);
    border-radius: 3px;
    padding: 9px 11px;
    margin-bottom: 12px;
}
.signature-label {
    font-size: 6.5px;
    font-weight: bold;
    letter-spacing: 0.18em;
    color: rgba(200,135,58,0.6);
    text-transform: uppercase;
    margin-bottom: 5px;
}
.signature-value {
    font-size: 6px;
    color: rgba(255,255,255,0.25);
    word-break: break-all;
    overflow-wrap: break-word;
    line-height: 1.7;
    font-family: "DejaVu Sans Mono", monospace;
    width: 100%;
}

.verify-block {
    text-align: center;
    padding: 12px 0 10px;
    border-top: 0.5px solid rgba(200,135,58,0.20);
    margin-top: 4px;
}
.verify-code-label {
    font-size: 6.5px;
    font-weight: bold;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    color: rgba(200,135,58,0.55);
    margin-bottom: 4px;
}
.verify-instruction {
    font-size: 7px;
    color: rgba(255,255,255,0.22);
    margin-bottom: 6px;
    line-height: 1.6;
}
.verify-code {
    font-size: 11px;
    font-weight: bold;
    font-family: "DejaVu Sans Mono", monospace;
    color: #E8A850;
    letter-spacing: 0.05em;
    margin-bottom: 4px;
    word-break: break-all;
}
.verify-url {
    font-size: 7px;
    color: rgba(255,255,255,0.18);
}

.footer {
    text-align: center;
    padding-top: 12px;
    margin-top: 10px;
    border-top: 0.5px solid rgba(255,255,255,0.06);
}
.footer .brand-footer {
    font-size: 6.5px;
    font-weight: bold;
    letter-spacing: 0.16em;
    color: rgba(200,135,58,0.40);
    text-transform: uppercase;
    margin-bottom: 4px;
}
.footer p {
    font-size: 6px;
    color: rgba(255,255,255,0.16);
    line-height: 2;
}

</style>
</head>
<body>

<div class="frame">
<div class="frame-inner">

    <div class="header">
        <div class="header-bar"></div>
        <div class="brand">SproutVest</div>
        <div class="cert-title">Certificate of Investment</div>
        <div class="cert-subtitle">Fractional Land Investment &nbsp;&middot;&nbsp; Verified Digital Certificate</div>
    </div>

    <div class="body">

        <div class="declaration">
            <div class="intro">This is to certify that</div>
            <div class="owner-name">{$owner}</div>
            <div class="verb">is the registered holder of</div>
            <div class="unit-count">{$units}</div>
            <div class="unit-label">Units</div>
            <div class="in-label">in</div>
            <div class="property-name">{$title}</div>
            <div class="property-location">{$location}</div>
        </div>

        <div class="divider"></div>

        <table class="details-table">
            <tr>
                <td class="label">Certificate No.</td>
                <td class="value">{$certNumber}</td>
            </tr>
            <tr>
                <td class="label">Land Reference</td>
                <td class="value">{$plotIdentifier}</td>
            </tr>
            <tr>
                <td class="label">Tenure</td>
                <td class="value">{$tenure}</td>
            </tr>
            <tr>
                <td class="label">Purchase Reference</td>
                <td class="value">{$purchaseRef}</td>
            </tr>
            <tr>
                <td class="label">Total Invested</td>
                <td class="value-highlight">{$total}</td>
            </tr>
            <tr>
                <td class="label">Issue Date</td>
                <td class="value">{$issueDate}</td>
            </tr>
            {$updatedRow}
            <tr>
                <td class="label">LGA</td>
                <td class="value">{$lga}</td>
            </tr>
            <tr>
                <td class="label">State</td>
                <td class="value">{$state}</td>
            </tr>
        </table>

        <div class="divider"></div>

        <div class="signature-block">
            <div class="signature-label">Digital Signature (SHA-256 HMAC)</div>
            <div class="signature-value">{$signature}</div>
        </div>

        <div class="verify-block">
            <div class="verify-code-label">To Verify This Certificate</div>
            <div class="verify-instruction">Visit the address below and enter the certificate number exactly as printed.</div>
            <div class="verify-code">{$certNumber}</div>
            <div class="verify-url">{$verifyUrl}</div>
        </div>

        <div class="footer">
            <div class="brand-footer">SproutVest GSE Ltd</div>
            <p>This certificate is digitally issued and verifiable at the address above.</p>
            <p>info@sproutvest.com</p>
        </div>

    </div>
</div>
</div>

</body>
</html>
HTML;
    }
}