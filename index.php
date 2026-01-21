<?php
//
// 1.0.0 - Inicialna verzija
// 1.0.1 - Dodani 2D barcode HUB3 za brže plaćanje putem aplikacije
// UBL 2.1 (HR CIUS 2025) XML → Human readable
// Vibe code by ChatGPT by Dalibor Klobučarić 
// and ChatGPT
// 

declare(strict_types=1);

const ENCRYPTION_KEY = '12345678901234567890123456789012'; // <- 32 chars (AES-256 key)
const BARCODE_ENDPOINT = 'https://hub3.dd-lab.hr/?data=';

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function xpValue(DOMXPath $xp, string $q, ?DOMNode $ctx = null): string {
  $n = $xp->query($q, $ctx)->item(0);
  return $n ? trim((string)$n->textContent) : '';
}

function normalizeIban(string $iban): string {
  // makni razmake i sve što nije slovo/broj
  $iban = preg_replace('/\s+/', '', $iban) ?? $iban;
  $iban = preg_replace('/[^A-Za-z0-9]/', '', $iban) ?? $iban;
  return strtoupper($iban);
}

function splitModelReference(string $paymentId): array {
  // Primjeri iz prakse:
  // "HR05 37567-261-015"
  // "HR05 123-456-789"
  $s = trim($paymentId);
  $s = preg_replace('/\s+/', ' ', $s) ?? $s;

  // uhvati "HR" + 2 znamenke (s ili bez razmaka) + ostatak
  if (preg_match('/^(HR\s?\d{2})\s+(.*)$/i', $s, $m)) {
    $model = strtoupper(str_replace(' ', '', $m[1])); // HR05
    $ref   = trim($m[2]);
    return [$model, $ref];
  }

  // fallback: ako nema modela
  return ['', $s];
}

function encryptPayload(array $data, string $encryptionKey): string {
  $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($jsonData === false) {
    throw new RuntimeException('Ne mogu JSON-encode payload.');
  }

  if (strlen($encryptionKey) !== 32) {
    throw new RuntimeException('ENCRYPTION_KEY mora imati točno 32 znaka (AES-256).');
  }

  $cipher = 'aes-256-cbc';
  $ivLen = openssl_cipher_iv_length($cipher);
  if ($ivLen === false || $ivLen <= 0) {
    throw new RuntimeException('Ne mogu odrediti IV length za AES-256-CBC.');
  }

  $iv = openssl_random_pseudo_bytes($ivLen);
  if ($iv === false) {
    throw new RuntimeException('Ne mogu generirati IV.');
  }

  $encrypted = openssl_encrypt($jsonData, $cipher, $encryptionKey, OPENSSL_RAW_DATA, $iv);
  if ($encrypted === false) {
    throw new RuntimeException('openssl_encrypt nije uspio (provjeri openssl ekstenziju).');
  }

  $combined = $iv . $encrypted;
  $payload = base64_encode($combined);
  return urlencode($payload);
}

function parseUblInvoice(string $xml): array {
  libxml_use_internal_errors(true);

  $dom = new DOMDocument();
  $dom->preserveWhiteSpace = false;

  if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
    $errs = array_map(fn($e) => trim($e->message), libxml_get_errors());
    libxml_clear_errors();
    throw new RuntimeException("XML parse error:\n" . implode("\n", $errs));
  }

  $xp = new DOMXPath($dom);

  // UBL + HR CIUS namespace-i
  $xp->registerNamespace('ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
  $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
  $xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
  $xp->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
  $xp->registerNamespace('hrextac', 'urn:mfin.gov.hr:schema:xsd:HRExtensionAggregateComponents-1');

  // Imena firmi znaju biti i u RegistrationName / PartyName ovisno o izdavatelju
  $supplierName =
    xpValue($xp, '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name')
    ?: xpValue($xp, '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName');

  $customerName =
    xpValue($xp, '/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyName/cbc:Name')
    ?: xpValue($xp, '/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName');

  // IBAN / PaymentID
  $rawIban = xpValue($xp, '/ubl:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID');
  $iban = normalizeIban($rawIban);

  $paymentId = xpValue($xp, '/ubl:Invoice/cac:PaymentMeans/cbc:PaymentID');
  [$model, $reference] = splitModelReference($paymentId);

  // Totali
  $currency = xpValue($xp, '/ubl:Invoice/cbc:DocumentCurrencyCode');
  $gross = xpValue($xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount');

  // PDV (standard + HR extension) - složimo listu subtotal-a
  $vatSubtotals = [];

  // Standard UBL TaxTotal
  foreach ($xp->query('/ubl:Invoice/cac:TaxTotal/cac:TaxSubtotal') as $ts) {
    /** @var DOMElement $ts */
    $taxable = xpValue($xp, 'cbc:TaxableAmount', $ts);
    $taxAmt  = xpValue($xp, 'cbc:TaxAmount', $ts);
    $catId   = xpValue($xp, 'cac:TaxCategory/cbc:ID', $ts);
    $percent = xpValue($xp, 'cac:TaxCategory/cbc:Percent', $ts);
    $scheme  = xpValue($xp, 'cac:TaxCategory/cac:TaxScheme/cbc:ID', $ts);

    $vatSubtotals[] = [
      'source' => 'UBL',
      'scheme' => $scheme,
      'category' => $catId,
      'percent' => $percent,
      'taxable' => $taxable,
      'tax' => $taxAmt,
    ];
  }

  // HR FISK 2.0 extension (ako postoji)
  foreach ($xp->query('/ubl:Invoice/ext:UBLExtensions/ext:UBLExtension/ext:ExtensionContent/hrextac:HRFISK20Data/hrextac:HRTaxTotal/hrextac:HRTaxSubtotal') as $ts) {
    /** @var DOMElement $ts */
    $taxable = xpValue($xp, 'cbc:TaxableAmount', $ts);
    $taxAmt  = xpValue($xp, 'cbc:TaxAmount', $ts);
    $catId   = xpValue($xp, 'hrextac:HRTaxCategory/cbc:ID', $ts);
    $name    = xpValue($xp, 'hrextac:HRTaxCategory/cbc:Name', $ts);
    $percent = xpValue($xp, 'hrextac:HRTaxCategory/cbc:Percent', $ts);
    $scheme  = xpValue($xp, 'hrextac:HRTaxCategory/hrextac:HRTaxScheme/cbc:ID', $ts);

    $vatSubtotals[] = [
      'source' => 'HR',
      'scheme' => $scheme,
      'category' => $catId ?: $name,
      'percent' => $percent,
      'taxable' => $taxable,
      'tax' => $taxAmt,
    ];
  }

  // Stavke
  $lines = [];
  foreach ($xp->query('/ubl:Invoice/cac:InvoiceLine') as $line) {
    /** @var DOMElement $line */

    $id = xpValue($xp, 'cbc:ID', $line);

    /** @var DOMNode|null $qtyN */
    $qtyN = $xp->query('cbc:InvoicedQuantity', $line)->item(0);
    $qty  = $qtyN ? trim((string)$qtyN->textContent) : '';

    $uom = ($qtyN instanceof DOMElement && $qtyN->hasAttribute('unitCode'))
      ? $qtyN->getAttribute('unitCode')
      : '';

    $name  = xpValue($xp, 'cac:Item/cbc:Name', $line);
    $net   = xpValue($xp, 'cbc:LineExtensionAmount', $line);
    $price = xpValue($xp, 'cac:Price/cbc:PriceAmount', $line);
    $taxP  = xpValue($xp, 'cac:Item/cac:ClassifiedTaxCategory/cbc:Percent', $line);

    $lines[] = [
      'id' => $id,
      'name' => $name,
      'qty' => $qty,
      'uom' => $uom,
      'price' => $price,
      'net' => $net,
      'tax_percent' => $taxP,
    ];
  }

  $data = [
    'invoice_id'   => xpValue($xp, '/ubl:Invoice/cbc:ID'),
    'issue_date'   => xpValue($xp, '/ubl:Invoice/cbc:IssueDate'),
    'issue_time'   => xpValue($xp, '/ubl:Invoice/cbc:IssueTime'),
    'due_date'     => xpValue($xp, '/ubl:Invoice/cbc:DueDate'),
    'currency'     => $currency,
    'note'         => xpValue($xp, '/ubl:Invoice/cbc:Note'),

    'supplier' => [
      'name'   => $supplierName,
      'oib'    => xpValue($xp, '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:CompanyID'),
      'vat'    => xpValue($xp, '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID'),
      'email'  => xpValue($xp, '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:Contact/cbc:ElectronicMail'),
      'zip'    => xpValue($xp, '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:PostalZone'),
      'street' => xpValue($xp, '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:StreetName'),
      'city'   => xpValue($xp, '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:CityName'),
      'addrLine' => xpValue($xp, '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cac:AddressLine/cbc:Line'),
    ],

    'customer' => [
      'name'  => $customerName,
      'oib'   => xpValue($xp, '/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:CompanyID'),
      'vat'   => xpValue($xp, '/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID'),
      'email' => xpValue($xp, '/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:Contact/cbc:ElectronicMail'),
      'zip'   => xpValue($xp, '/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:PostalZone'),
      'street'=> xpValue($xp, '/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:StreetName'),
      'city'  => xpValue($xp, '/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:CityName'),
      'addrLine' => xpValue($xp, '/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cac:AddressLine/cbc:Line'),
    ],

    'totals' => [
      'net'   => xpValue($xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'),
      'vat'   => xpValue($xp, '/ubl:Invoice/cac:TaxTotal/cbc:TaxAmount'),
      'gross' => $gross,
    ],

    'payment' => [
      'means_code' => xpValue($xp, '/ubl:Invoice/cac:PaymentMeans/cbc:PaymentMeansCode'),
      'note'       => xpValue($xp, '/ubl:Invoice/cac:PaymentMeans/cbc:InstructionNote'),
      'payment_id' => $paymentId,
      'model'      => $model,
      'reference'  => $reference,
      'iban_raw'   => $rawIban,
      'iban'       => $iban,
    ],

    'vat_subtotals' => $vatSubtotals,
    'lines' => $lines,
  ];

  return $data;
}

$parsed = null;
$error = null;
$barcodeUrl = '';
$barcodePayload = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!isset($_FILES['xml']) || $_FILES['xml']['error'] !== UPLOAD_ERR_OK) {
      throw new RuntimeException('Upload failed.');
    }
    $xml = file_get_contents($_FILES['xml']['tmp_name']);
    if ($xml === false || trim($xml) === '') {
      throw new RuntimeException('Empty file.');
    }

    $parsed = parseUblInvoice($xml);

    // --- 2D barcode payload ---
    // 1) postal nebitan, bitan zip -> city = zip
    // 2) payer = kupac, payee = dobavljač
    $payerZip = $parsed['customer']['zip'] ?: '';
    $payeeZip = $parsed['supplier']['zip'] ?: '';

    $descriptionParts = [];
    if (!empty($parsed['invoice_id'])) $descriptionParts[] = 'Račun ' . $parsed['invoice_id'];
    if (!empty($parsed['note'])) $descriptionParts[] = $parsed['note'];
    $description = trim(implode(' | ', $descriptionParts));

    $code = 'COST'; // ako nemaš posebni kod, ovo je fallback

    $payloadData = [
      'payer' => [
        'name' => (string)($parsed['customer']['name'] ?? ''),
        'address' => (string)($parsed['customer']['street'] ?: $parsed['customer']['addrLine'] ?: ''),
        'city' => (string)$payerZip, // <- samo ZIP po tvojoj želji
      ],
      'payee' => [
        'name' => (string)($parsed['supplier']['name'] ?? ''),
        'address' => (string)($parsed['supplier']['street'] ?: $parsed['supplier']['addrLine'] ?: ''),
        'city' => (string)$payeeZip, // <- samo ZIP
      ],
      'iban' => (string)($parsed['payment']['iban'] ?? ''),
      'currency' => (string)($parsed['currency'] ?? ''),
      //'amount' => (string)($parsed['totals']['gross'] ?? ''),
      'amount' => preg_replace('/[^\d]/', '', number_format((float)($parsed['totals']['gross'] ?? 0), 2, '.', '')),
      'model' => (string)($parsed['payment']['model'] ?? ''),
      'reference' => (string)($parsed['payment']['reference'] ?? ''),
      'code' => $code,
      'description' => $description,
    ];

    $barcodePayload = encryptPayload($payloadData, ENCRYPTION_KEY);
    $barcodeUrl = BARCODE_ENDPOINT . $barcodePayload;

  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="hr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Pregled e-računa | DD-LAB</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial;max-width:1100px;margin:24px auto;padding:0 16px}
    .card{border:1px solid #ddd;border-radius:12px;padding:16px;margin:12px 0}
    .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
    table{width:100%;border-collapse:collapse}
    th,td{border-bottom:1px solid #eee;padding:8px;text-align:left;vertical-align:top}
    .muted{color:#666}
    .err{background:#ffecec;border:1px solid #ffb3b3}
    .ok{background:#f6fffa;border:1px solid #b7f5d0}
    code{background:#f5f5f5;padding:2px 6px;border-radius:6px}
    .row{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
    button{cursor:pointer}
    .btn{padding:8px 12px;border:1px solid #ccc;border-radius:10px;background:#fff}
    .btn.primary{border-color:#2b7; background:#f6fffa}
    .btn.danger{border-color:#f99; background:#fff5f5}
    .field{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    input.readonly{padding:6px 10px;border:1px solid #ddd;border-radius:10px;min-width:280px}
    img.barcode{max-width:450px;width:100%;height:auto;border:1px solid #eee;border-radius:12px}
  </style>
</head>
<body>

  <h1>UBL 2.1 (HR CIUS 2025) XML → Human readable</h1>

  <div class="card">
    <form method="post" enctype="multipart/form-data" class="row">
      <div>
        <label><b>Upload UBL XML:</b></label><br>
        <input type="file" name="xml" accept=".xml,text/xml,application/xml" required>
      </div>
      <div>
        <button class="btn primary" type="submit">Prikaži</button>
      </div>
    </form>
    <p class="muted">Preglednik: dobavljač/kupac, iznosi, stavke + 2D barcode za plaćanje.</p>
  </div>

  <?php if ($error): ?>
    <div class="card err"><b>Greška:</b><pre><?=h($error)?></pre></div>
  <?php endif; ?>

  <?php if ($parsed): ?>
    <div class="card ok">
      <h2>Račun <?=h($parsed['invoice_id'])?></h2>
      <div class="grid">
        <div>
          <div><b>Datum:</b> <?=h($parsed['issue_date'])?> <?=h($parsed['issue_time'])?></div>
          <div><b>Dospijeće:</b> <?=h($parsed['due_date'])?></div>
          <div><b>Valuta:</b> <?=h($parsed['currency'])?></div>
          <?php if ($parsed['note']): ?><div><b>Napomena:</b> <?=h($parsed['note'])?></div><?php endif; ?>
        </div>
        <div>
          <div><b>Neto:</b> <?=h($parsed['totals']['net'])?> <?=h($parsed['currency'])?></div>
          <div><b>PDV:</b> <?=h($parsed['totals']['vat'])?> <?=h($parsed['currency'])?></div>
          <div><b>Ukupno:</b> <?=h($parsed['totals']['gross'])?> <?=h($parsed['currency'])?></div>
        </div>
      </div>
    </div>

    <div class="card">
      <h3>Dobavljač</h3>
      <div><b><?=h($parsed['supplier']['name'])?></b></div>
      <div class="muted">OIB: <?=h($parsed['supplier']['oib'])?> | VAT: <?=h($parsed['supplier']['vat'])?></div>
      <div><?=h($parsed['supplier']['street'])?><?= $parsed['supplier']['city'] ? ', ' . h($parsed['supplier']['city']) : '' ?></div>
      <?php if ($parsed['supplier']['email']): ?><div>Email: <?=h($parsed['supplier']['email'])?></div><?php endif; ?>
    </div>

    <div class="card">
      <h3>Kupac</h3>
      <div><b><?=h($parsed['customer']['name'])?></b></div>
      <div class="muted">OIB: <?=h($parsed['customer']['oib'])?> | VAT: <?=h($parsed['customer']['vat'])?></div>
      <?php if ($parsed['customer']['email']): ?><div>Email: <?=h($parsed['customer']['email'])?></div><?php endif; ?>
    </div>

    <div class="card">
      <h3>Plaćanje</h3>
      <div><b>PaymentMeansCode:</b> <code><?=h($parsed['payment']['means_code'])?></code></div>
      <div><b>Uputa:</b> <?=h($parsed['payment']['note'])?></div>
      <div><b>Poziv/PaymentID:</b> <?=h($parsed['payment']['payment_id'])?></div>

      <div class="field" style="margin-top:10px">
        <b>IBAN:</b>
        <input class="readonly" id="iban" type="text" readonly value="<?=h($parsed['payment']['iban'])?>">
        <button class="btn" type="button" onclick="copyText('iban')">Copy</button>
        <span class="muted">(normalizirano, bez razmaka)</span>
      </div>

      <?php if (!empty($parsed['payment']['model']) || !empty($parsed['payment']['reference'])): ?>
        <div style="margin-top:8px">
          <b>Model:</b> <code><?=h($parsed['payment']['model'])?></code>
          &nbsp; <b>Poziv na broj:</b> <code><?=h($parsed['payment']['reference'])?></code>
        </div>
      <?php endif; ?>
    </div>

    <?php if (!empty($parsed['vat_subtotals'])): ?>
      <div class="card">
        <h3>PDV (subtotal)</h3>
        <table>
          <thead>
            <tr>
              <th>Izvor</th>
              <th>Shema</th>
              <th>Kategorija</th>
              <th>Stopa %</th>
              <th>Osnovica</th>
              <th>PDV</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($parsed['vat_subtotals'] as $v): ?>
              <tr>
                <td><?=h($v['source'])?></td>
                <td><?=h($v['scheme'])?></td>
                <td><?=h($v['category'])?></td>
                <td><?=h($v['percent'])?></td>
                <td><?=h($v['taxable'])?> <?=h($parsed['currency'])?></td>
                <td><?=h($v['tax'])?> <?=h($parsed['currency'])?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="card">
      <h3>Stavke</h3>
      <table>
        <thead>
          <tr>
            <th>#</th>
            <th>Naziv</th>
            <th>Količina</th>
            <th>Cijena</th>
            <th>Neto</th>
            <th>PDV %</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($parsed['lines'] as $l): ?>
            <tr>
              <td><?=h($l['id'])?></td>
              <td><?=h($l['name'])?></td>
              <td><?=h($l['qty'])?> <span class="muted"><?=h($l['uom'])?></span></td>
              <td><?=h($l['price'])?> <?=h($parsed['currency'])?></td>
              <td><?=h($l['net'])?> <?=h($parsed['currency'])?></td>
              <td><?=h($l['tax_percent'])?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h3>2D barcode za plaćanje</h3>

      <?php if ($barcodeUrl): ?>
        <div class="row" style="align-items:flex-start">
          <div style="max-width:480px">
            <img class="barcode" id="barcodeImg" src="<?=h($barcodeUrl)?>" alt="2D barcode za plaćanje">
            <div class="row" style="margin-top:10px">
              <button class="btn primary" type="button" onclick="downloadBarcode()">Preuzmi sliku</button>
            </div>
            <div class="muted" style="margin-top:8px">
              Naziv datoteke: <code id="fnamePreview"></code>
            </div>
          </div>
          <div class="muted" style="flex:1;min-width:260px">
            <div><b>Payee:</b> <?=h($parsed['supplier']['name'])?></div>
            <div><b>Iznos:</b> <?=h($parsed['totals']['gross'])?> <?=h($parsed['currency'])?></div>
            <div><b>IBAN:</b> <?=h($parsed['payment']['iban'])?></div>
            <?php if (!empty($parsed['payment']['model']) || !empty($parsed['payment']['reference'])): ?>
              <div><b>Model/Poziv:</b> <?=h($parsed['payment']['model'])?> <?=h($parsed['payment']['reference'])?></div>
            <?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="muted">Barcode nije generiran (provjeri ENCRYPTION_KEY i openssl).</div>
      <?php endif; ?>
    </div>

  <?php endif; ?>

<script>
function copyText(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.select?.();
  el.setSelectionRange?.(0, 99999);
  navigator.clipboard.writeText(el.value).catch(() => {
    // fallback
    try { document.execCommand('copy'); } catch(e) {}
  });
}

function pad(n){ return String(n).padStart(2,'0'); }

function makeFilename() {
  const d = new Date();
  // YYYYMMDDHHMMSS
  const name =
    d.getFullYear() +
    pad(d.getMonth()+1) +
    pad(d.getDate()) +
    pad(d.getHours()) +
    pad(d.getMinutes()) +
    pad(d.getSeconds()) +
    '.png';
  return name;
}

(function initFilenamePreview(){
  const el = document.getElementById('fnamePreview');
  if (el) el.textContent = makeFilename();
})();

async function downloadBarcode() {
  const img = document.getElementById('barcodeImg');
  if (!img || !img.src) return;

  const filename = makeFilename();
  const preview = document.getElementById('fnamePreview');
  if (preview) preview.textContent = filename;

  // fetch image as blob and download
  const resp = await fetch(img.src, { cache: 'no-store' });
  if (!resp.ok) {
    alert('Ne mogu preuzeti sliku (fetch failed).');
    return;
  }
  const blob = await resp.blob();
  const url = URL.createObjectURL(blob);

  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();

  URL.revokeObjectURL(url);
}
</script>

</body>
</html>