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
          $fontCacheDir = storage_path('app/dompdf-fonts');
        if (!is_dir($fontCacheDir)) {
            mkdir($fontCacheDir, 0755, true);
        }

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isCssFloatEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('fontDir', $fontCacheDir);
        $options->set('fontCache', $fontCacheDir);     

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($this->buildHtml($certificate), 'UTF-8');
        $dompdf->render();

        return $dompdf->output();
    }


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

    private function generatePdf(Certificate $certificate): string
    {
        $dir = storage_path('app/private/certificates');
        if (! is_dir($dir)) mkdir($dir, 0755, true);

        $filename = Str::slug($certificate->cert_number) . '.pdf';
        $fullPath = "{$dir}/{$filename}";

        file_put_contents($fullPath, $this->renderPdfBytes($certificate));

        return "private/certificates/{$filename}";
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

    private function stampDataUri(): string
    {
        $path = public_path('images/stamp.png');
        if (! file_exists($path)) return '';
        $mime = mime_content_type($path) ?: 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($path));
    }

    private function buildHtml(Certificate $c): string
    {
        $issueDate = $c->issued_at
            ? \Carbon\Carbon::parse($c->issued_at)->format('d F Y') : '—';
        $issueDateShort = $c->issued_at
            ? \Carbon\Carbon::parse($c->issued_at)->format('d M Y') : '—';

        $lastUpdated = (
            $c->last_updated_at &&
            \Carbon\Carbon::parse($c->last_updated_at)->ne(\Carbon\Carbon::parse($c->issued_at))
        ) ? \Carbon\Carbon::parse($c->last_updated_at)->format('d F Y') : null;

        $total          = '&#8358;' . number_format((float) $c->total_invested, 2);
        $units          = number_format((int) $c->units);
        $plotIdentifier = htmlspecialchars($c->plot_identifier       ?? '—', ENT_QUOTES, 'UTF-8');
        $tenure         = htmlspecialchars(ucfirst(strtolower($c->tenure ?? '—')), ENT_QUOTES, 'UTF-8');
        $lga            = htmlspecialchars($c->lga                   ?? '—', ENT_QUOTES, 'UTF-8');
        $state          = htmlspecialchars($c->state                 ?? '—', ENT_QUOTES, 'UTF-8');
        $title          = htmlspecialchars($c->property_title,        ENT_QUOTES, 'UTF-8');
        $location       = htmlspecialchars($c->property_location,     ENT_QUOTES, 'UTF-8');
        $owner          = htmlspecialchars($c->owner_name,            ENT_QUOTES, 'UTF-8');
        $ownerUpper     = htmlspecialchars(strtoupper($c->owner_name),ENT_QUOTES, 'UTF-8');
        $certNumber     = htmlspecialchars($c->cert_number,           ENT_QUOTES, 'UTF-8');
        $purchaseRef    = htmlspecialchars($c->purchase_reference,    ENT_QUOTES, 'UTF-8');
        $signature      = htmlspecialchars($c->digital_signature,     ENT_QUOTES, 'UTF-8');
        $verifyUrl      = htmlspecialchars(config('app.frontend_url') . '/verify', ENT_QUOTES, 'UTF-8');
        $propertySize   = htmlspecialchars($c->land->size              ?? '—', ENT_QUOTES, 'UTF-8');
        $surveyRef      = htmlspecialchars($c->land->survey_reference  ?? '—', ENT_QUOTES, 'UTF-8');
        $titleStatus    = htmlspecialchars($c->land->title_status      ?? 'Certificate of Occupancy', ENT_QUOTES, 'UTF-8');

        $updatedRow = $lastUpdated
            ? '<tr><td class="dl">Last Updated</td><td class="dv">' . htmlspecialchars($lastUpdated, ENT_QUOTES, 'UTF-8') . '</td></tr>'
            : '';

        $stampDataUri = $this->stampDataUri();
        $stampImg     = $stampDataUri
            ? '<img src="' . $stampDataUri . '" width="180" height="180" style="border-radius:50%; opacity:0.95;" alt="Stamp" />'
            : '';
        
        $greatvibesPath = public_path('fonts/GreatVibes-Regular.ttf');
        $greatvibesFace = '';
        if (file_exists($greatvibesPath)) {
            $greatvibesB64 = base64_encode(file_get_contents($greatvibesPath));
            $greatvibesFace = "@font-face {
                font-family: 'GreatVibes';
                src: url('data:font/ttf;base64,{$greatvibesB64}') format('truetype');
                font-weight: normal;
                font-style: normal;
            }";
        }

        $nameLength = mb_strlen($c->owner_name);

    if ($nameLength <= 12) {
        $ownerFontSize = '32pt';  
    } elseif ($nameLength <= 18) {
        $ownerFontSize = '28pt';  
    } elseif ($nameLength <= 24) {
        $ownerFontSize = '24pt';  
    } elseif ($nameLength <= 32) {
        $ownerFontSize = '20pt';  
    } else {
        $ownerFontSize = '18pt';  
    }
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>

* { margin:0; padding:0; box-sizing:border-box; }
{$greatvibesFace}
@page { size: A4 portrait; margin: 0mm; }

body {
    font-family: "DejaVu Sans", Arial, sans-serif;
    font-size: 8pt;
    background: #ffffff;
    color: #1a1a1a;
}

/* ── Shared detail table ─────────────────────────── */
.dtbl            { width:100%; border-collapse:collapse; padding-bottom: 10pt;}
.dtbl tr         { border-bottom:0.75pt solid #ececec; }
.dtbl tr:last-child { border-bottom:none; }
.dl {    font-size: 8pt;
    font-weight: bold;
    letter-spacing: 0.08em;
    color: #1D4A35;
    text-transform: uppercase;
    padding: 5pt 5pt 5pt 5pt;
    width: 46%;
    white-space: nowrap;
}
.dv       { font-size:7.5pt; color:#333; text-align:right; padding:5pt 0; word-break:break-word; }
.dv-gold  { font-size:9pt;   font-weight:bold; color:#B8862A; text-align:right; padding:5pt 0; }
.dv-small { font-size:6.5pt; color:#555; text-align:right; padding:5pt 0; word-break:break-word; }

/* ── Page 1 typography ─────────────────────────── */
.p1-brand    { font-size:7pt; letter-spacing:0.30em; color:#1D4A35; font-weight:bold; text-transform:uppercase; margin-bottom:5pt; }
.p1-title    { font-size:25pt; font-weight:bold; color:#1D4A35; font-style:italic; line-height:1.05; margin-bottom:4pt; }
.p1-subtitle { font-size:7pt; letter-spacing:0.16em; color:#B8862A; text-transform:uppercase; font-weight:bold; }
.owner-name-wrap {
    width:100%;
    text-align:center;
    margin: 0 auto;
}

.owner-name {
    display:inline-block;
    text-align:center;
    font-family: 'GreatVibes', cursive;
    color:#1D4A35;
    line-height:1.2;
    margin-bottom:8pt;
    letter-spacing:0.5pt;
    max-width: 90%;
}
.certify-lbl { font-size:10pt; color:#888; font-style:italic; margin-bottom:10pt; }
.holder-lbl  { font-size:10pt; color:#888; font-style:italic; margin-bottom:10pt; }
.unit-num    { font-size:34pt; font-weight:bold; color:#1D4A35; line-height:1; display:block; }
.unit-lbl    { font-size:9pt; font-weight:bold; letter-spacing:0.18em; color:#B8862A; text-transform:uppercase; display:block; margin-top:4pt; }
.in-lbl      { font-size:10pt; color:#888; font-style:italic; margin-bottom:5pt; margin-top:10pt; }
.prop-title  { font-size:19pt; font-weight:bold; color:#1D4A35; margin-bottom:3pt; line-height:1.15; }
.prop-loc    { font-size:10pt; color:#aaa; }
.sig-lbl     { font-size:6pt; font-weight:bold; letter-spacing:0.10em; color:#1D4A35; text-transform:uppercase; margin-bottom:3pt; }
.sig-val     {
    font-size: 5.5pt;
    color: #888;
    word-break: break-all;
    line-height: 1.9;
    font-family: "Courier New", Courier, monospace;
    background-color: #f4f7f5;
    border: 0.75pt solid #ccd8d0;
    padding: 4pt 5pt;
}
/* Signature */
.p2-sig-name-style {
    font-family: 'GreatVibes', cursive;
    font-size: 22pt;
    color: #E8A850;
    margin-bottom: 3pt;
    line-height: 1.1;
}
.vfy-hdr    { font-size:7pt; font-weight:bold; letter-spacing:0.18em; text-transform:uppercase; color:#1D4A35; margin-bottom:3pt; }
.vfy-sub    { font-size:7pt; color:#888; margin-bottom:6pt; }
.vfy-num    { font-size:11pt; font-weight:bold; font-family:"Courier New",Courier,monospace; color:#1D4A35; letter-spacing:0.04em; }
.vfy-url    { font-size:7pt; color:#B8862A; display:block; margin-top:2pt; }
.ftr-brand  { font-size:8.5pt; font-weight:bold; color:#1D4A35; letter-spacing:0.07em; }
.ftr-tag    { font-size:6pt; color:#bbb; margin-top:2pt; }
.ftr-email  { font-size:7pt; color:#888; }

/* ── Page 2 typography ─────────────────────────── */
.p2-co   { font-size:6.5pt; font-weight:bold; letter-spacing:0.16em; color:#C8873A; text-transform:uppercase; margin-bottom:4pt; }
.p2-deed { font-size:12pt; font-weight:bold; color:#fff; letter-spacing:0.04em; text-transform:uppercase; margin-bottom:3pt; }
.p2-sub  { font-size:6.5pt; color:#777; font-style:italic; }
.p2-badge { background-color:#C8873A; color:#0D1F1A; font-size:6.5pt; font-weight:bold; padding:4pt 7pt; line-height:1.6; letter-spacing:0.04em; text-align:center; display:inline-block; }
.p2-sh { border-bottom:0.75pt solid #2e4a38; padding-bottom:4pt; margin-bottom:6pt; font-size:7pt; font-weight:bold; letter-spacing:0.09em; color:#C8873A; text-transform:uppercase; }
.p2-sn { color:#7a6040; margin-right:2pt; }
.p2-sb   { font-size:8pt; color:#aaa; line-height:2; }
.p2-sb p { margin-bottom:3pt; }
.p2-sb ul { padding-left:9pt; margin:0; }
.p2-sb li { margin-bottom:2pt; list-style-type:disc; }
.p2-role { font-size:6pt; font-weight:bold; letter-spacing:0.08em; color:#8a7040; text-transform:uppercase; margin-top:5pt; margin-bottom:2pt; }
.p2-pnm  { font-size:9.5pt; font-weight:bold; color:#fff; margin-bottom:2pt; }
.p2-pdsc { font-size:6.5pt; color:#666; line-height:1.55; }
.p2-sc   { width:100%; border-collapse:collapse; }
.p2-sc td { padding:3.5pt 4pt; border-bottom:0.75pt solid #1a2e22; font-size:7.2pt; vertical-align:top; }
.p2-sk   { color:#8a7040; font-weight:bold; width:42%; text-transform:uppercase; font-size:6.5pt; letter-spacing:0.04em; padding-right:4pt; }
.p2-sv   { color:#ccc; }
.p2-sc tr:last-child td { border-bottom:none; }
.p2-sig-name { font-size:24pt; font-weight:bold; font-style:italic; color:#E8A850; line-height:1.1; margin-bottom:2pt; font-family:"DejaVu Sans", serif; }
.p2-sig-rule { border-top:0.75pt solid #3a5040; padding-top:4pt; margin-top:4pt; }
.p2-sig-nm   { font-size:6.5pt; font-weight:bold; color:#ddd; margin-bottom:2pt; }
.p2-sig-role { font-size:6pt; color:#555; line-height:1.55; }
.p2-fn    { font-size:6.5pt; color:#444; line-height:1.75; }
.p2-fchk  { color:#5a8060; margin-right:3pt; }
.p2-cnlbl { font-size:5.5pt; letter-spacing:0.12em; color:#8a7040; text-transform:uppercase; margin-bottom:2pt; }
.p2-cn    { font-size:8.5pt; font-weight:bold; font-family:"Courier New",Courier,monospace; color:#E8A850; letter-spacing:0.04em; }

</style>
</head>
<body>

<!-- ═══════════════════════════ PAGE 1 ════════════════════════-->
<table style="width:210mm; border-collapse:collapse; page-break-after:always; table-layout:fixed;">
<tbody>
<tr>
<td style="padding:3pt; border:2pt solid #B8862A; vertical-align:top;">

  <table style="width:100%; border-collapse:collapse; border:0.75pt solid #d4aa60; table-layout:fixed;">
  <tbody>

    <!-- ROW 1: top gold bar — 5pt -->
    <tr style="height:5pt;">
      <td colspan="2" style="background-color:#B8862A; height:5pt; font-size:0; line-height:0; padding:0;"></td>
    </tr>

    <!-- ROW 2: header — 82pt -->
    <tr style="height:82pt;">
      <td colspan="2" style="height:82pt; text-align:center; padding:13pt 16pt 11pt; border-bottom:0.75pt solid #d4aa60; vertical-align:middle; overflow:hidden;">
        <div class="p1-brand">REU.NG &nbsp;&bull;&nbsp; SPROUTVEST GSE LTD</div>
        <div class="p1-title">Certificate of Investment</div>
        <div class="p1-subtitle">FRACTIONAL LAND INVESTMENT &nbsp;&bull;&nbsp; VERIFIED DIGITAL CERTIFICATE</div>
      </td>
    </tr>

    <!-- ROW 3: main body — 480pt -->
    <tr style="height:480pt;">

      <!-- Left col: 37% — owner + units badge + property -->
        <td style="width:37%; height:480pt; padding:20pt 14pt; border-right:0.75pt solid #d4aa60; vertical-align:middle; text-align:center;">

            <div class="certify-lbl">This is to certify that</div>
            
            <div class="owner-name-wrap">
                <div class="owner-name" style="font-size: {$ownerFontSize};">
                {$owner}
                </div>
            </div>
            
            <div class="holder-lbl" style="margin-bottom:18pt;">is the registered holder of</div>

            <table style="border-collapse:collapse; margin:18pt auto; border:2.5pt solid #B8862A; width:115pt; height:115pt; text-align:center;">
            <tr><td style="vertical-align:middle;">
                <span class="unit-num" style="font-size:34pt;">{$units}</span>
                <span class="unit-lbl" style="font-size:9pt;">Units</span>
            </td></tr>
            </table>

            <div class="in-lbl" style="margin-top:18pt; margin-bottom:10pt;">in</div>
            <div class="prop-title" style="font-size:19pt; margin-bottom:7pt; line-height:1.15;">{$title}</div>
            <div class="prop-loc" style="font-size:10pt;">{$location}</div>

        </td>

      <!-- Right col: 63% — detail rows + signature + stamp -->
      <td style="width:63%; height:480pt; vertical-align:top; padding:14pt 15pt 14pt 13pt; overflow:hidden;">

        <table class="dtbl">
          <tr><td class="dl">Certificate No.</td>    <td class="dv">{$certNumber}</td></tr>
          <tr><td class="dl">Land Reference</td>     <td class="dv">{$plotIdentifier}</td></tr>
          <tr><td class="dl">Tenure</td>              <td class="dv">{$tenure}</td></tr>
          <tr><td class="dl">Purchase Reference</td> <td class="dv-small">{$purchaseRef}</td></tr>
          <tr><td class="dl">Total Invested</td>     <td class="dv-gold">{$total}</td></tr>
          <tr><td class="dl">Issue Date</td>         <td class="dv">{$issueDate}</td></tr>
          {$updatedRow}
          <tr><td class="dl">LGA</td>                <td class="dv">{$lga}</td></tr>
          <tr><td class="dl">State</td>              <td class="dv">{$state}</td></tr>
        </table>

        <table style="width:100%; border-collapse:collapse; border-top:0.75pt solid #d4aa60; margin-top:10pt;">
            <tr>
                <td style="vertical-align:top; padding-top:8pt;">
                <div class="sig-lbl">Digital Signature (SHA-256 HMAC)</div>
                <div class="sig-val">{$signature}</div>

                <div style="text-align:center; margin-top:20pt;">
                    {$stampImg}
                </div>
                </td>
            </tr>
            </table>
        </td>
        </tr>

    <!-- ROW 4: verify strip — 112pt -->
    <tr style="height:112pt;">
      <td colspan="2" style="height:112pt; text-align:center; padding:14pt 18pt 12pt; border-top:0.75pt solid #d4aa60; vertical-align:middle; overflow:hidden;">
        <div class="vfy-hdr">TO VERIFY THIS CERTIFICATE</div>
        <div class="vfy-sub">Visit the address below and enter the certificate number exactly as printed.</div>
        <table style="border-collapse:collapse; margin:0 auto; border:0.75pt solid #B8862A; background-color:#fdf8ef;">
          <tr><td style="padding:6pt 18pt; text-align:center;">
            <div class="vfy-num">{$certNumber}</div>
            <span class="vfy-url">{$verifyUrl}</span>
          </td></tr>
        </table>
      </td>
    </tr>

    <!-- ROW 5: footer — 36pt -->
    <tr style="height:36pt;">
      <td colspan="2" style="height:36pt; border-top:0.75pt solid #ececec; padding:0; overflow:hidden;">
        <table style="width:100%; border-collapse:collapse;">
          <tr>
            <td style="padding:8pt 14pt; vertical-align:middle;">
              <div class="ftr-brand">&#9670; SPROUTVEST GSE LTD</div>
              <div class="ftr-tag">Digitally issued &amp; verifiable certificate</div>
            </td>
            <td style="padding:8pt 14pt; text-align:right; vertical-align:middle;">
              <div class="ftr-email">&#9993; info@reu.ng</div>
            </td>
          </tr>
        </table>
      </td>
    </tr>

  </tbody>
  </table>

</td>
</tr>
</tbody>
</table>
<!-- /page1 -->


<!-- ═══════════════════════════ PAGE 2 ════════════════════════-->
<table style="width:210mm; border-collapse:collapse; table-layout:fixed;">
<tbody>
<tr>
<td style="padding:0; vertical-align:top; background-color:#0D1F1A; overflow:hidden;">

  <table style="width:100%; border-collapse:collapse; table-layout:fixed;">
  <tbody>

    <!-- ROW 1: gold bar — 5pt -->
    <tr style="height:5pt;">
      <td style="background-color:#C8873A; height:5pt; font-size:0; line-height:0; padding:0;"></td>
    </tr>

    <!-- ROW 2: dark header — 65pt -->
    <tr style="height:65pt;">
      <td style="height:65pt; padding:0; background-color:#091510; border-bottom:0.75pt solid #2a3a28; overflow:hidden;">
        <table style="width:100%; border-collapse:collapse; height:65pt; table-layout:fixed;">
          <tr>
            <td style="width:82%; padding:10pt 14pt 8pt; vertical-align:top; background-color:#091510;">
              <div class="p2-co">&#9670; SPROUTVEST GSE LTD (REU.NG)</div>
              <div class="p2-deed">DEED OF FRACTIONAL ASSIGNMENT &bull; DIGITAL FORM</div>
              <div class="p2-sub">This Digital Certificate serves as a legally binding record of fractional ownership made on {$issueDateShort}</div>
            </td>
            <td style="width:18%; padding:10pt 14pt 8pt; text-align:right; vertical-align:top; background-color:#091510; white-space:nowrap;">
              <div class="p2-badge">PAGE<br/>2<br/>OF 2</div>
            </td>
          </tr>
        </table>
      </td>
    </tr>

    <!-- ROW 3: 3-column deed content — auto height, clipped by outer 297mm -->
    <tr>
      <td style="padding:0; vertical-align:top; background-color:#0D1F1A; overflow:hidden;">
        <table style="width:100%; border-collapse:collapse; table-layout:fixed;">
        <tr>

          <!-- COL 1 — Parties, Whereas, Operative Clause, Nature of Ownership -->
          <td style="width:33.33%; vertical-align:top; padding:10pt 9pt 10pt 11pt; background-color:#0D1F1A; overflow:hidden;">

            <div class="p2-sh"><span class="p2-sn">1.</span> PARTIES</div>
            <div class="p2-sb">
              <div class="p2-role">Assignor / Trustee:</div>
              <div class="p2-pnm">SproutVest GSE Ltd</div>
              <div class="p2-pdsc">(A company duly incorporated under the laws of the Federal Republic of Nigeria)</div>
              <div class="p2-role">Assignee / Investor:</div>
              <div class="p2-pnm">{$ownerUpper}</div>
            </div>

            <div style="height:8pt;"></div>

            <div class="p2-sh"><span class="p2-sn">2.</span> WHEREAS</div>
            <div class="p2-sb">
              <p>A. The Assignor is the legal or beneficial owner of the parcel described in the Schedule below.</p>
              <p>B. The Assignor has elected to fractionalize the property into investment units.</p>
              <p>C. The Assignee has agreed to purchase units representing a proportional interest.</p>
              <p>D. Full consideration has been received for the units allocated.</p>
            </div>

            <div style="height:8pt;"></div>

            <div class="p2-sh"><span class="p2-sn">3.</span> OPERATIVE CLAUSE</div>
            <div class="p2-sb">
              <p>In consideration of the sum paid, the Assignor hereby transfers to the Assignee a proportional, undivided beneficial interest equivalent to the units stated herein.</p>
            </div>

            <div style="height:8pt;"></div>

            <div class="p2-sh"><span class="p2-sn">4.</span> NATURE OF OWNERSHIP</div>
            <div class="p2-sb">
              <ul>
                <li>The ownership conferred is fractional and undivided.</li>
                <li>The Assignee does not hold exclusive physical possession of any portion.</li>
                <li>Legal title is held by SproutVest GSE Ltd (or its SPV/Trustee) on behalf of all unit holders.</li>
              </ul>
            </div>

          </td>

          <!-- COL 2 — Rights, Obligations, Indemnity, Property Schedule -->
          <td style="width:33.33%; vertical-align:top; padding:10pt 9pt 10pt 10pt; background-color:#0D1F1A; border-left:0.75pt solid #1e3028; overflow:hidden;">

            <div class="p2-sh"><span class="p2-sn">5.</span> RIGHTS OF THE ASSIGNEE</div>
            <div class="p2-sb">
              <ul>
                <li>Hold, transfer, or trade units via the platform</li>
                <li>Benefit from capital appreciation of the property</li>
                <li>Receive income or proceeds arising from the property (where applicable)</li>
                <li>Access verifiable records of ownership</li>
              </ul>
            </div>

            <div style="height:8pt;"></div>

            <div class="p2-sh"><span class="p2-sn">6.</span> OBLIGATIONS OF ASSIGNOR / PLATFORM</div>
            <div class="p2-sb">
              <ul>
                <li>Maintain proper legal documentation of the property</li>
                <li>Ensure the property is free from undisclosed encumbrances</li>
                <li>Manage the property in the collective interest of investors</li>
                <li>Facilitate transparency and record integrity</li>
              </ul>
            </div>

            <div style="height:8pt;"></div>

            <div class="p2-sh"><span class="p2-sn">7.</span> INDEMNITY</div>
            <div class="p2-sb">
              <p>The Assignor guarantees the property is free from known encumbrances and agrees to indemnify the Assignee against any defect in title arising from prior claims or undisclosed interests.</p>
            </div>

            <div style="height:8pt;"></div>

            <div class="p2-sh"><span class="p2-sn">8.</span> PROPERTY SCHEDULE</div>
            <div class="p2-sb">
              <table class="p2-sc">
                <tr><td class="p2-sk">Description</td><td class="p2-sv">{$title}</td></tr>
                <tr><td class="p2-sk">Location</td>   <td class="p2-sv">{$location}</td></tr>
                <tr><td class="p2-sk">Size</td>        <td class="p2-sv">{$propertySize}</td></tr>
                <tr><td class="p2-sk">Survey Ref.</td> <td class="p2-sv">{$surveyRef}</td></tr>
                <tr><td class="p2-sk">Title Status</td><td class="p2-sv">{$titleStatus}</td></tr>
              </table>
            </div>

          </td>

          <!-- COL 3 — Platform Structure, Governing Law, Disclaimer, Execution -->
          <td style="width:33.33%; vertical-align:top; padding:10pt 11pt 10pt 10pt; background-color:#0D1F1A; border-left:0.75pt solid #1e3028; overflow:hidden;">

            <div class="p2-sh"><span class="p2-sn">9.</span> PLATFORM STRUCTURE</div>
            <div class="p2-sb">
              <ul>
                <li>The property is held under a custodial or trustee structure.</li>
                <li>Investors hold beneficial ownership via units.</li>
                <li>SproutVest GSE Ltd acts as platform operator and asset custodian.</li>
              </ul>
            </div>

            <div style="height:8pt;"></div>

            <div class="p2-sh"><span class="p2-sn">10.</span> GOVERNING LAW</div>
            <div class="p2-sb">
              <p>This certificate and all rights arising from it shall be governed by the laws of the Federal Republic of Nigeria.</p>
            </div>

            <div style="height:8pt;"></div>

            <div class="p2-sh"><span class="p2-sn">11.</span> LIMITATION &amp; DISCLAIMER</div>
            <div class="p2-sb">
              <ul>
                <li>This certificate is not a substitute for a traditional C of O.</li>
                <li>It represents a digitally managed fractional ownership structure.</li>
                <li>All transactions are subject to platform terms and applicable regulations.</li>
              </ul>
            </div>

            <div style="height:8pt;"></div>

            <div class="p2-sh"><span class="p2-sn">12.</span> EXECUTION &amp; AUTHENTICATION</div>
            <div class="p2-sb">
              <p style="margin-bottom:6pt;">This certificate is valid upon digital issuance and verification via the platform.</p>
              <div class="p2-sig-name-style">A. Alalade</div>
              <div class="p2-sig-rule">
                <div class="p2-sig-nm">AYOMIDE ALALADE</div>
                <div class="p2-sig-role">Director, SproutVest GSE Ltd</div>
              </div>
            </div>

          </td>

        </tr>
        </table>
      </td>
    </tr>

    <!-- ROW 4: dark footer — 38pt -->
    <tr style="height:38pt;">
      <td style="height:38pt; padding:0; background-color:#091510; border-top:0.75pt solid #2a3a28; overflow:hidden;">
        <table style="width:100%; border-collapse:collapse; height:38pt; table-layout:fixed;">
          <tr>
            <td style="width:70%; padding:7pt 14pt; vertical-align:middle; background-color:#091510;">
              <div class="p2-fn">
                <span class="p2-fchk">&#10003;</span>
                System-generated digital certificate &mdash; no physical signature required.<br/>
                All information verifiable on the SproutVest platform.
              </div>
            </td>
            <td style="width:30%; padding:7pt 14pt; text-align:right; vertical-align:middle; background-color:#091510;">
              <div class="p2-cnlbl">Certificate No.</div>
              <div class="p2-cn">{$certNumber}</div>
            </td>
          </tr>
        </table>
      </td>
    </tr>

  </tbody>
  </table>

</td>
</tr>
</tbody>
</table>
<!-- /page2 -->

</body>
</html>
HTML;
    }
}