<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Purchase;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class CertificateService
{
    public function issue(Purchase $purchase): Certificate
    {
        $user = $purchase->user;
        $land = $purchase->land;

        // Revoke if no units left
        if ($purchase->units <= 0) {
            Certificate::where('purchase_id', $purchase->id)
                ->update([
                    'status' => 'revoked',
                    'revoked_at' => now()
                ]);

            return Certificate::where('purchase_id', $purchase->id)->firstOrFail();
        }

        $existing = Certificate::where('purchase_id', $purchase->id)->first();

        if ($existing) {
            $existing->update([
                'units' => $purchase->units,
                'total_invested' => $purchase->total_amount_paid_kobo / 100,
                'status' => 'active',
                'digital_signature' => $this->generateSignature(
                    $existing->cert_number,
                    $purchase->reference,
                    $user->name
                ),
            ]);

            $this->safeGeneratePdf($existing);

            return $existing->fresh();
        }

        // First time
        $certNumber = $this->generateCertNumber($land->id);

        $certificate = Certificate::create([
            'user_id' => $user->id,
            'land_id' => $land->id,
            'purchase_id' => $purchase->id,
            'cert_number' => $certNumber,
            'digital_signature' => $this->generateSignature(
                $certNumber,
                $purchase->reference,
                $user->name
            ),
            'owner_name' => $user->name,
            'units' => $purchase->units,
            'total_invested' => $purchase->total_amount_paid_kobo / 100,
            'purchase_reference' => $purchase->reference,
            'property_title' => $land->title,
            'property_location' => $land->location,
            'plot_identifier' => $land->plot_identifier,
            'tenure' => $land->tenure,
            'lga' => $land->lga,
            'state' => $land->state,
            'status' => 'active',
            'issued_at' => now(),
        ]);

        $this->safeGeneratePdf($certificate);

        return $certificate->fresh();
    }

    public function verify(string $certNumber): ?Certificate
    {
        $cert = Certificate::where('cert_number', $certNumber)
            ->where('status', 'active')
            ->first();

        if (!$cert) return null;

        $expected = $this->generateSignature(
            $cert->cert_number,
            $cert->purchase_reference,
            $cert->owner_name
        );

        return hash_equals($expected, $cert->digital_signature) ? $cert : null;
    }

    public function regeneratePdf(Certificate $certificate): string
    {
        return $this->generatePdf($certificate);
    }

    public function renderPdfBytes(Certificate $certificate): string
    {
        $dompdf = $this->makeDompdf();
        $dompdf->loadHtml($this->buildHtml($certificate));
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }

    // ───────────────────────── PRIVATE ─────────────────────────

    private function safeGeneratePdf(Certificate $certificate): void
    {
        try {
            $path = $this->generatePdf($certificate);
            $certificate->update(['pdf_path' => $path]);
        } catch (\Throwable $e) {
            Log::error('PDF generation failed', [
                'cert_id' => $certificate->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function makeDompdf(): Dompdf
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        return new Dompdf($options);
    }

    private function generateCertNumber(int $landId): string
    {
        return "CERT-" . now()->year . "-L" . str_pad($landId, 4, '0', STR_PAD_LEFT)
            . "-" . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
    }

    private function generateSignature(string $certNumber, string $reference, string $owner): string
    {
        $raw = "{$certNumber}|{$reference}|{$owner}";
        return strtoupper(hash_hmac('sha256', $raw, config('app.key')));
    }

    private function generatePdf(Certificate $certificate): string
    {
        $dir = storage_path('app/private/certificates');
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $filename = Str::slug($certificate->cert_number) . '.pdf';
        $fullPath = "{$dir}/{$filename}";

        file_put_contents($fullPath, $this->renderPdfBytes($certificate));

        return "private/certificates/{$filename}";
    }

    private function buildHtml(Certificate $c): string
    {
        $verifyUrl = config('app.url') . '/verify/' . $c->cert_number;

      try {
          $qr = base64_encode(
              QrCode::format('svg')->size(120)->generate($verifyUrl)
          );
          $qrImage = "data:image/svg+xml;base64,{$qr}";
      } catch (\Throwable $e) {
          $qrImage = ''; // fallback: no QR instead of crashing
      }

      $qrImage = "data:image/svg+xml;base64,{$qr}";
        $total = '₦' . number_format($c->total_invested, 2);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
@page { size:A4 landscape; margin:0; }
body { font-family: DejaVu Sans; background:#0D1F1A; color:#fff; }
.page { padding:40px; position:relative; }
h1 { font-size:22px; color:#E8A850; }
.small { font-size:10px; color:#ccc; }
.big { font-size:36px; }
.qr { position:absolute; right:40px; top:40px; }
.watermark {
 position:absolute; top:50%; left:50%;
 transform:translate(-50%,-50%);
 font-size:70px; color:rgba(255,255,255,0.03);
}
</style>
</head>
<body>
<div class="page">
<div class="watermark">{$c->owner_name}</div>

<div class="qr">
<img src="{$qrImage}" width="90" height="90" />
<div class="small">Scan to verify</div>
</div>

<h1>Certificate of Investment</h1>

<p class="small">This certifies that</p>
<p class="big">{$c->owner_name}</p>

<p class="small">owns</p>
<p class="big">{$c->units} Units</p>

<p>{$c->property_title}</p>
<p class="small">{$c->property_location}</p>

<hr>

<p>Certificate: {$c->cert_number}</p>
<p>Total Invested: {$total}</p>
<p>Date: {$c->issued_at}</p>

<hr>

<p class="small">Signature:</p>
<p class="small">{$c->digital_signature}</p>

</div>
</body>
</html>
HTML;
    }
}