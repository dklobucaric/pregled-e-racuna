<?php
//
// 1.0.0 - Inicialna verzija
// 1.0.1 - Dodani 2D barcode HUB3 za brže plaćanje putem aplikacije
// 1.0.2 - Dodano preuzimanje PDF-a ako ima embedano u XML-u + sigurnosne provjere
// 1.0.3 - Poboljšana validacija IBAN-a i PaymentID-a
// 1.0.4 - Dodana provjera MIME tipa uploadanog file-a (finfo ili ekstenzija)
// 1.0.5 - Dodana provjera veličine uploadanog file-a (max 5 MB)
// 1.0.6 - Poboljšano rukovanje greškama kod XML parsiranja
// 1.0.7 - FIX: "Ukupno" više nije PayableAmount nego TaxInclusiveAmount; dodano "Za platiti" i logika za prepaid
// 1.0.8 - Code cleanup i refaktoriranje
// UBL 2.1 (HR CIUS 2025) XML → Human readable
// Vibe code by ChatGPT and Dalibor Klobučarić
//
declare(strict_types=1);

const ENCRYPTION_KEY = '12345678901234567890123456789012'; // <- 32 chars (AES-256 key) (dummy)
const BARCODE_ENDPOINT = 'https://hub3.dd-lab.hr/?data=';
const PAYMENT_MEANS_HR = [
  '30' => 'Uplata / kreditni transfer (virman)',
  '42' => 'Plaćanje na bankovni račun',
  '10' => 'Gotovina',
  '48' => 'Kartično plaćanje',
  '49' => 'Izravno terećenje (direct debit)',
];

function h(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function xpValue(DOMXPath $xp, string $q, ?DOMNode $ctx = null): string
{
  $n = $xp->query($q, $ctx)->item(0);
  return $n ? trim((string)$n->textContent) : '';
}

function normalizeIban(string $iban): string
{
  $iban = preg_replace('/\s+/', '', $iban) ?? $iban;
  $iban = preg_replace('/[^A-Za-z0-9]/', '', $iban) ?? $iban;
  return strtoupper($iban);
}

function splitModelReference(string $paymentId): array
{
  $s = trim($paymentId);
  $s = preg_replace('/\s+/', ' ', $s) ?? $s;

  $fallback = ['HR00', '0000'];
  if ($s === '') return $fallback;

  if (preg_match('/^(HR\s?\d{2})\s+(.*)$/i', $s, $m)) {
    $model = strtoupper(str_replace(' ', '', $m[1]));
    $ref   = trim($m[2]);

    if ($model === 'HR99') return $fallback;
    if ($ref === '') return $fallback;

    return [$model, $ref];
  }

  if (preg_match('/^(HR\s?\d{2})$/i', $s)) {
    return $fallback;
  }

  return $fallback;
}

function paymentMeansLabel(string $code): string
{
  $code = trim($code);
  return PAYMENT_MEANS_HR[$code] ?? 'Nepoznato';
}

function amountToCents(string $amount): string
{
  $s = trim($amount);
  if ($s === '') return '0';

  $s = str_replace([' ', "\u{00A0}"], '', $s);
  $s = str_replace(',', '.', $s);
  $s = preg_replace('/[^0-9.]/', '', $s) ?? $s;

  if (substr_count($s, '.') > 1) {
    $parts = explode('.', $s);
    $dec = array_pop($parts);
    $int = implode('', $parts);
    $s = $int . '.' . $dec;
  }

  if (strpos($s, '.') === false) {
    return ($s === '' ? '0' : $s) . '00';
  }

  [$int, $dec] = explode('.', $s, 2);
  $int = $int === '' ? '0' : $int;
  $dec = substr($dec . '00', 0, 2);

  $cents = ltrim($int . $dec, '0');
  return $cents === '' ? '0' : $cents;
}

function encryptPayload(array $data, string $encryptionKey): string
{
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

function parseUblInvoice(string $xml): array
{
  libxml_use_internal_errors(true);

  $dom = new DOMDocument();
  $dom->preserveWhiteSpace = false;

  if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
    $errs = array_map(fn($e) => trim($e->message), libxml_get_errors());
    libxml_clear_errors();
    throw new RuntimeException("XML parse error:\n" . implode("\n", $errs));
  }

  $xp = new DOMXPath($dom);

  $xp->registerNamespace('ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
  $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
  $xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
  $xp->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
  $xp->registerNamespace('hrextac', 'urn:mfin.gov.hr:schema:xsd:HRExtensionAggregateComponents-1');

  $supplierName =
    xpValue($xp, '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyName/cbc:Name')
    ?: xpValue($xp, '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName');

  $customerName =
    xpValue($xp, '/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyName/cbc:Name')
    ?: xpValue($xp, '/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName');

  $rawIban = xpValue($xp, '/ubl:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID');
  $iban = normalizeIban($rawIban);

  $paymentId = xpValue($xp, '/ubl:Invoice/cac:PaymentMeans/cbc:PaymentID');
  [$model, $reference] = splitModelReference($paymentId);

  $currency = xpValue($xp, '/ubl:Invoice/cbc:DocumentCurrencyCode');

  // --- totals: važno! ---
  $net            = xpValue($xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount');
  $vatAmount      = xpValue($xp, '/ubl:Invoice/cac:TaxTotal/cbc:TaxAmount');
  $taxInclusive = xpValue($xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount'); // ukupno računa
  $prepaid      = xpValue($xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:PrepaidAmount'); // već plaćeno (ako postoji)
  $payable      = xpValue($xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount'); // za platiti

  if ($taxInclusive === '') {
    if ($payable !== '' && $prepaid !== '') {
      $taxInclusive = (string)((float)str_replace(',', '.', $payable) + (float)str_replace(',', '.', $prepaid));
    } elseif ($payable !== '') {
      $taxInclusive = $payable;
    }
  }

  $vatSubtotals = [];

  foreach ($xp->query('/ubl:Invoice/cac:TaxTotal/cac:TaxSubtotal') as $ts) {
    /** @var DOMElement $ts */
    $vatSubtotals[] = [
      'source' => 'UBL',
      'scheme' => xpValue($xp, 'cac:TaxCategory/cac:TaxScheme/cbc:ID', $ts),
      'category' => xpValue($xp, 'cac:TaxCategory/cbc:ID', $ts),
      'percent' => xpValue($xp, 'cac:TaxCategory/cbc:Percent', $ts),
      'taxable' => xpValue($xp, 'cbc:TaxableAmount', $ts),
      'tax' => xpValue($xp, 'cbc:TaxAmount', $ts),
    ];
  }

  foreach ($xp->query('/ubl:Invoice/ext:UBLExtensions/ext:UBLExtension/ext:ExtensionContent/hrextac:HRFISK20Data/hrextac:HRTaxTotal/hrextac:HRTaxSubtotal') as $ts) {
    /** @var DOMElement $ts */
    $catId = xpValue($xp, 'hrextac:HRTaxCategory/cbc:ID', $ts);
    $name  = xpValue($xp, 'hrextac:HRTaxCategory/cbc:Name', $ts);

    $vatSubtotals[] = [
      'source' => 'HR',
      'scheme' => xpValue($xp, 'hrextac:HRTaxCategory/hrextac:HRTaxScheme/cbc:ID', $ts),
      'category' => $catId ?: $name,
      'percent' => xpValue($xp, 'hrextac:HRTaxCategory/cbc:Percent', $ts),
      'taxable' => xpValue($xp, 'cbc:TaxableAmount', $ts),
      'tax' => xpValue($xp, 'cbc:TaxAmount', $ts),
    ];
  }

  $lines = [];
  foreach ($xp->query('/ubl:Invoice/cac:InvoiceLine') as $line) {
    /** @var DOMElement $line */
    $id = xpValue($xp, 'cbc:ID', $line);

    $qtyN = $xp->query('cbc:InvoicedQuantity', $line)->item(0);
    $qty  = $qtyN ? trim((string)$qtyN->textContent) : '';

    $uom = ($qtyN instanceof DOMElement && $qtyN->hasAttribute('unitCode'))
      ? $qtyN->getAttribute('unitCode')
      : '';

    $lines[] = [
      'id' => $id,
      'name' => xpValue($xp, 'cac:Item/cbc:Name', $line),
      'qty' => $qty,
      'uom' => $uom,
      'price' => xpValue($xp, 'cac:Price/cbc:PriceAmount', $line),
      'net' => xpValue($xp, 'cbc:LineExtensionAmount', $line),
      'tax_percent' => xpValue($xp, 'cac:Item/cac:ClassifiedTaxCategory/cbc:Percent', $line),
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
      'street' => xpValue($xp, '/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:StreetName'),
      'city'  => xpValue($xp, '/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cbc:CityName'),
      'addrLine' => xpValue($xp, '/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cac:AddressLine/cbc:Line'),
    ],

    // FIX: gross = TaxInclusiveAmount, payable = PayableAmount

    'totals' => [
      'net'           => xpValue($xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'),
      'vat'           => xpValue($xp, '/ubl:Invoice/cac:TaxTotal/cbc:TaxAmount'),
      'tax_inclusive' => $taxInclusive,   // UKUPNO računa (s PDV-om)
      'prepaid'       => $prepaid,         // plaćeno / prepaid
      'payable'       => $payable,         // za platiti
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

  // --- Embedded PDF attachment (if present) ---
  $data['attachment_pdf'] = [
    'b64' => '',
    'mime' => '',
    'filename' => '',
    'size' => 0,
  ];

  $attNode = $xp->query(
    '/ubl:Invoice/cac:AdditionalDocumentReference/cac:Attachment/cbc:EmbeddedDocumentBinaryObject[@mimeCode="application/pdf"]'
  )->item(0);

  if (!$attNode) {
    $attNode = $xp->query(
      '/ubl:Invoice/cac:AdditionalDocumentReference/cac:Attachment/cbc:EmbeddedDocumentBinaryObject'
    )->item(0);
  }

  if ($attNode instanceof DOMElement) {
    $b64 = trim($attNode->textContent);
    $b64 = preg_replace('/\s+/', '', $b64) ?? $b64;

    $mime = $attNode->getAttribute('mimeCode') ?: '';
    $fn   = $attNode->getAttribute('filename') ?: '';

    $isPdfByMime = (strtolower($mime) === 'application/pdf');
    $isPdfByName = ($fn !== '' && preg_match('/\.pdf$/i', $fn));

    if ($isPdfByMime || $isPdfByName) {
      if ($fn === '') $fn = 'racun.pdf';
      if ($mime === '') $mime = 'application/pdf';

      $data['attachment_pdf']['b64'] = $b64;
      $data['attachment_pdf']['mime'] = $mime;
      $data['attachment_pdf']['filename'] = $fn;

      if ($b64 !== '') {
        $pad = 0;
        if (substr($b64, -2) === '==') $pad = 2;
        elseif (substr($b64, -1) === '=') $pad = 1;
        $data['attachment_pdf']['size'] = (int) floor((strlen($b64) * 3) / 4) - $pad;
      }
    }
  }

  return $data;
}

// -------------------- Runtime --------------------
$parsed = null;
$error = null;
$barcodeUrl = '';
$barcodePayload = '';
$xmlPayload = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {

    // A) Download PDF mode (mora biti prvo!)
    if (isset($_POST['download_pdf'], $_POST['xml_payload'])) {
      $xml = base64_decode((string)$_POST['xml_payload'], true);
      if ($xml === false || trim($xml) === '') {
        throw new RuntimeException('Invalid XML payload.');
      }

      $tmp = parseUblInvoice($xml);
      $b64 = $tmp['attachment_pdf']['b64'] ?? '';
      if ($b64 === '') {
        throw new RuntimeException('PDF nije pronađen u XML-u.');
      }

      $b64 = preg_replace('/\s+/', '', $b64) ?? $b64;

      $pdf = base64_decode($b64, true);
      if ($pdf === false) {
        throw new RuntimeException('Neispravan Base64 PDF.');
      }

      if (strncmp($pdf, '%PDF', 4) !== 0) {
        throw new RuntimeException('Attachment nije PDF (ne počinje s %PDF).');
      }

      $fn = $tmp['attachment_pdf']['filename'] ?: ('racun_' . ($tmp['invoice_id'] ?: 'download') . '.pdf');
      $fn = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fn);

      header('Content-Type: application/pdf');
      header('Content-Disposition: attachment; filename="' . $fn . '"');
      header('Content-Length: ' . strlen($pdf));
      echo $pdf;
      exit;
    }

    // B) Normal upload mode
    if (!isset($_FILES['xml']) || $_FILES['xml']['error'] !== UPLOAD_ERR_OK) {
      throw new RuntimeException('Upload failed.');
    }

    // === File size provjera ===
    $maxSize = 5 * 1024 * 1024; // 5 MB
    if (!empty($_FILES['xml']['size']) && $_FILES['xml']['size'] > $maxSize) {
      throw new RuntimeException('XML file je prevelik (max 5 MB).');
    }

    // === MIME type provjera (finfo) ===
    $allowedMimes = ['text/xml', 'application/xml', 'text/plain'];

    $mimeType = '';
    if (class_exists('finfo')) {
      $fi = new finfo(FILEINFO_MIME_TYPE);
      $mimeType = (string) $fi->file($_FILES['xml']['tmp_name']);
    }

    if ($mimeType === '') {
      $ext = strtolower(pathinfo((string)($_FILES['xml']['name'] ?? ''), PATHINFO_EXTENSION));
      if ($ext !== 'xml') {
        throw new RuntimeException('Neispravan tip datoteke. Potreban je XML file.');
      }
    } else {
      if (!in_array($mimeType, $allowedMimes, true)) {
        throw new RuntimeException('Neispravan tip datoteke. Potreban je XML file.');
      }
    }

    $xml = file_get_contents($_FILES['xml']['tmp_name']);
    if ($xml === false || trim($xml) === '') {
      throw new RuntimeException('Empty file.');
    }

    $parsed = parseUblInvoice($xml);
    $xmlPayload = base64_encode($xml);

    // --- 2D barcode payload ---
    // Barcode ima smisla samo ako ima nešto "za platiti"
    $payable = (float) str_replace(',', '.', (string)($parsed['totals']['payable'] ?? '0'));
    if ($payable <= 0.0) {
      $barcodeUrl = ''; // sakrij barcode ako je već plaćeno / nema za platiti
    } else {
      $payerZip = $parsed['customer']['zip'] ?: '';
      $payeeZip = $parsed['supplier']['zip'] ?: '';

      $descriptionParts = [];
      if (!empty($parsed['invoice_id'])) $descriptionParts[] = 'Račun ' . $parsed['invoice_id'];
      if (!empty($parsed['note'])) $descriptionParts[] = $parsed['note'];
      $description = trim(implode(' | ', $descriptionParts));

      $code = 'COST';

      $payloadData = [
        'payer' => [
          'name' => (string)($parsed['customer']['name'] ?? ''),
          'address' => (string)($parsed['customer']['street'] ?: $parsed['customer']['addrLine'] ?: ''),
          'city' => (string)$payerZip,
        ],
        'payee' => [
          'name' => (string)($parsed['supplier']['name'] ?? ''),
          'address' => (string)($parsed['supplier']['street'] ?: $parsed['supplier']['addrLine'] ?: ''),
          'city' => (string)$payeeZip,
        ],
        'iban' => (string)($parsed['payment']['iban'] ?? ''),
        'currency' => (string)($parsed['currency'] ?? ''),
        // FIX: barcode amount = PayableAmount (za platiti), ne "ukupno"
        'amount' => amountToCents((string)($parsed['totals']['payable'] ?? '0')),
        'model' => (string)($parsed['payment']['model'] ?? ''),
        'reference' => (string)($parsed['payment']['reference'] ?? ''),
        'code' => $code,
        'description' => $description,
      ];

      $barcodePayload = encryptPayload($payloadData, ENCRYPTION_KEY);
      $barcodeUrl = BARCODE_ENDPOINT . $barcodePayload;
    }
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
    body {
      font-family: system-ui, Segoe UI, Arial;
      max-width: 1100px;
      margin: 24px auto;
      padding: 0 16px
    }

    .card {
      border: 1px solid #ddd;
      border-radius: 12px;
      padding: 16px;
      margin: 12px 0
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px
    }

    table {
      width: 100%;
      border-collapse: collapse
    }

    th,
    td {
      border-bottom: 1px solid #eee;
      padding: 8px;
      text-align: left;
      vertical-align: top
    }

    .muted {
      color: #666
    }

    .err {
      background: #ffecec;
      border: 1px solid #ffb3b3
    }

    .ok {
      background: #f6fffa;
      border: 1px solid #b7f5d0
    }

    code {
      background: #f5f5f5;
      padding: 2px 6px;
      border-radius: 6px
    }

    .row {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      align-items: center
    }

    button {
      cursor: pointer
    }

    .btn {
      padding: 8px 12px;
      border: 1px solid #ccc;
      border-radius: 10px;
      background: #fff
    }

    .btn.primary {
      border-color: #2b7;
      background: #f6fffa
    }

    .field {
      display: flex;
      gap: 8px;
      align-items: center;
      flex-wrap: wrap
    }

    input.readonly {
      padding: 6px 10px;
      border: 1px solid #ddd;
      border-radius: 10px;
      min-width: 280px
    }

    img.barcode {
      max-width: 450px;
      width: 100%;
      height: auto;
      border: 1px solid #eee;
      border-radius: 12px
    }

    .badge {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 999px;
      font-weight: 700;
      font-size: 12px;
      margin-left: 10px
    }

    .badge.paid {
      background: #e8fff1;
      border: 1px solid #2b7;
      color: #145c2e
    }
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
    <p class="muted">Preglednik: dobavljač/kupac, iznosi, stavke, 2D barcode za plaćanje i PDF preuzimanje ako postoji.</p>
  </div>

  <?php if ($error): ?>
    <div class="card err"><b>Greška:</b>
      <pre><?= h($error) ?></pre>
    </div>
  <?php endif; ?>

  <?php if ($parsed): ?>
    <div class="card ok">
      <h2>
        Račun <?= h($parsed['invoice_id']) ?>
        <?php
        $payable = (float) str_replace(',', '.', (string)($parsed['totals']['payable'] ?? '0'));
        $prepaid = (float) str_replace(',', '.', (string)($parsed['totals']['prepaid'] ?? '0'));

        if ($payable <= 0.00001 && $prepaid > 0.00001):
        ?>
          <span class="badge paid">PLAĆENO</span>
        <?php endif; ?>
      </h2>

      <div class="grid">
        <div>
          <div><b>Datum:</b> <?= h($parsed['issue_date']) ?> <?= h($parsed['issue_time']) ?></div>
          <div><b>Dospijeće:</b> <?= h($parsed['due_date']) ?></div>
          <div><b>Valuta:</b> <?= h($parsed['currency']) ?></div>
          <?php if ($parsed['note']): ?><div><b>Napomena:</b> <?= h($parsed['note']) ?></div><?php endif; ?>
        </div>
        <div>
          <div><b>Neto:</b> <?= h($parsed['totals']['net']) ?> <?= h($parsed['currency']) ?></div>
          <div><b>PDV:</b> <?= h($parsed['totals']['vat']) ?> <?= h($parsed['currency']) ?></div>

          <!-- FIX: Ukupno = TaxInclusiveAmount -->
          <div><b>Ukupno:</b> <?= h($parsed['totals']['tax_inclusive']) ?> <?= h($parsed['currency']) ?></div>
          <div><b>Za platiti:</b> <?= h($parsed['totals']['payable']) ?> <?= h($parsed['currency']) ?></div>

          <?php if (!empty($parsed['totals']['prepaid']) && (float)$parsed['totals']['prepaid'] > 0): ?>
            <div><b>Plaćeno:</b> <?= h($parsed['totals']['prepaid']) ?> <?= h($parsed['currency']) ?></div>
          <?php endif; ?>


          <?php
          $p = (float) str_replace(',', '.', (string)($parsed['totals']['payable'] ?? '0'));
          $pp = (float) str_replace(',', '.', (string)($parsed['totals']['prepaid'] ?? '0'));
          ?>
        </div>
      </div>
    </div>

    <div class="card">
      <h3>Dobavljač</h3>
      <div><b><?= h($parsed['supplier']['name']) ?></b></div>
      <div class="muted">OIB: <?= h($parsed['supplier']['oib']) ?> | VAT: <?= h($parsed['supplier']['vat']) ?></div>
      <div><?= h($parsed['supplier']['street']) ?><?= $parsed['supplier']['city'] ? ', ' . h($parsed['supplier']['city']) : '' ?></div>
      <?php if ($parsed['supplier']['email']): ?><div>Email: <?= h($parsed['supplier']['email']) ?></div><?php endif; ?>
    </div>

    <div class="card">
      <h3>Kupac</h3>
      <div><b><?= h($parsed['customer']['name']) ?></b></div>
      <div class="muted">OIB: <?= h($parsed['customer']['oib']) ?> | VAT: <?= h($parsed['customer']['vat']) ?></div>
      <?php if ($parsed['customer']['email']): ?><div>Email: <?= h($parsed['customer']['email']) ?></div><?php endif; ?>
    </div>

    <div class="card">
      <h3>Plaćanje</h3>
      <div>
        <b>PaymentMeansCode:</b>
        <code><?= h($parsed['payment']['means_code']) ?> / <?= h(paymentMeansLabel($parsed['payment']['means_code'])) ?></code>
      </div>

      <div><b>Uputa:</b> <?= h($parsed['payment']['note']) ?></div>
      <div><b>Poziv/PaymentID:</b> <?= h($parsed['payment']['payment_id']) ?></div>

      <div class="field" style="margin-top:10px">
        <b>IBAN:</b>
        <input class="readonly" id="iban" type="text" readonly value="<?= h($parsed['payment']['iban']) ?>">
        <button class="btn" type="button" onclick="copyText('iban')">Copy</button>
        <span class="muted">(normalizirano, bez razmaka)</span>
      </div>

      <?php if (!empty($parsed['payment']['model']) || !empty($parsed['payment']['reference'])): ?>
        <div style="margin-top:8px">
          <b>Model:</b> <code><?= h($parsed['payment']['model']) ?></code>
          &nbsp; <b>Poziv na broj:</b> <code><?= h($parsed['payment']['reference']) ?></code>
        </div>
      <?php endif; ?>
    </div>

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
              <td><?= h($l['id']) ?></td>
              <td><?= h($l['name']) ?></td>
              <td><?= h($l['qty']) ?> <span class="muted"><?= h($l['uom']) ?></span></td>
              <td><?= h($l['price']) ?> <?= h($parsed['currency']) ?></td>
              <td><?= h($l['net']) ?> <?= h($parsed['currency']) ?></td>
              <td><?= h($l['tax_percent']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
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
              <th>Ukupno</th> <!-- NOVO -->
            </tr>
          </thead>
          <tbody>
            <?php foreach ($parsed['vat_subtotals'] as $v): ?>
              <?php
              // robustno: radi i kad je "12,34"
              $taxable = (float) str_replace(',', '.', (string)($v['taxable'] ?? '0'));
              $tax     = (float) str_replace(',', '.', (string)($v['tax'] ?? '0'));
              $total   = $taxable + $tax;

              // format kao HR (zarez decimale)
              $totalFmt = number_format($total, 2, ',', '');
              ?>
              <tr>
                <td><?= h($v['source']) ?></td>
                <td><?= h($v['scheme']) ?></td>
                <td><?= h($v['category']) ?></td>
                <td><?= h($v['percent']) ?></td>
                <td><?= h($v['taxable']) ?> <?= h($parsed['currency']) ?></td>
                <td><?= h($v['tax']) ?> <?= h($parsed['currency']) ?></td>
                <td><?= h($totalFmt) ?> <?= h($parsed['currency']) ?></td> <!-- NOVO -->
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="card">
      <h3>2D barcode za plaćanje</h3>

      <?php if ($barcodeUrl): ?>
        <div class="row" style="align-items:flex-start">
          <div style="max-width:480px">
            <img class="barcode" id="barcodeImg" src="<?= h($barcodeUrl) ?>" alt="2D barcode za plaćanje">
            <div class="row" style="margin-top:10px">
              <button class="btn primary" type="button" onclick="downloadBarcode()">Preuzmi sliku</button>
            </div>
            <div class="muted" style="margin-top:8px">
              Naziv datoteke: <code id="fnamePreview"></code>
            </div>
          </div>
          <div class="muted" style="flex:1;min-width:260px">
            <div><b>Dobavljač:</b> <?= h($parsed['supplier']['name']) ?></div>
            <div><b>


                Za platiti:</b> <?= h((string)($parsed['totals']['payable'] ?? '')) ?> <?= h($parsed['currency']) ?>


            </div>
            <div><b>IBAN:</b> <?= h($parsed['payment']['iban']) ?></div>
            <?php if (!empty($parsed['payment']['model']) || !empty($parsed['payment']['reference'])): ?>
              <div><b>Model/Poziv:</b> <?= h($parsed['payment']['model']) ?> <?= h($parsed['payment']['reference']) ?></div>
            <?php endif; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="muted">Barcode nije prikazan jer nema iznosa za platiti</div>
        <?php if ($p > 0): ?>
          <div><b>Za platiti:</b> <?= h((string)($parsed['totals']['payable'] ?? '')) ?> <?= h($parsed['currency']) ?></div>
        <?php else: ?>
          <div class="muted"><b>Status:</b> Pretplata <?= $pp > 0 ? h((string)($parsed['totals']['prepaid'] ?? '')) . ' ' . h($parsed['currency']) : '' ?></div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php if (!empty($parsed['attachment_pdf']['b64']) && !empty($xmlPayload)): ?>
      <div class="card">
        <h3>PDF prilog</h3>
        <div class="muted">
          <?= h($parsed['attachment_pdf']['filename'] ?: 'racun.pdf') ?>
          <?php if (!empty($parsed['attachment_pdf']['mime'])): ?> · <?= h($parsed['attachment_pdf']['mime']) ?><?php endif; ?>
            <?php if (!empty($parsed['attachment_pdf']['size'])): ?> · <?= h((string)round($parsed['attachment_pdf']['size'] / 1024)) ?> KB<?php endif; ?>
        </div>

        <form method="post" style="margin-top:10px" class="row">
          <input type="hidden" name="download_pdf" value="1">
          <input type="hidden" name="xml_payload" value="<?= h($xmlPayload) ?>">
          <button class="btn primary" type="submit">Preuzmi PDF</button>
        </form>
      </div>
    <?php else: ?>
      <div class="card">
        <h3>PDF prilog</h3>
        <div class="muted">PDF nije pronađen u XML-u.</div>
      </div>
    <?php endif; ?>

  <?php endif; ?>

  <script>
    function copyText(id) {
      const el = document.getElementById(id);
      if (!el) return;
      el.select?.();
      el.setSelectionRange?.(0, 99999);
      navigator.clipboard.writeText(el.value).catch(() => {
        try {
          document.execCommand('copy');
        } catch (e) {}
      });
    }

    function pad(n) {
      return String(n).padStart(2, '0');
    }

    function makeFilename() {
      const d = new Date();
      return (
        d.getFullYear() +
        pad(d.getMonth() + 1) +
        pad(d.getDate()) +
        pad(d.getHours()) +
        pad(d.getMinutes()) +
        pad(d.getSeconds()) +
        '.png'
      );
    }

    (function initFilenamePreview() {
      const el = document.getElementById('fnamePreview');
      if (el) el.textContent = makeFilename();
    })();

    async function downloadBarcode() {
      try {
        const img = document.getElementById('barcodeImg');
        if (!img || !img.src) {
          alert('Barcode slika nije pronađena.');
          return;
        }

        const resp = await fetch(img.src, {
          cache: 'no-store'
        });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);

        const blob = await resp.blob();
        const url = URL.createObjectURL(blob);

        const a = document.createElement('a');
        a.href = url;
        a.download = makeFilename();
        document.body.appendChild(a);
        a.click();
        a.remove();

        URL.revokeObjectURL(url);

      } catch (err) {
        console.error('Download error:', err);
        const msg = err instanceof Error ? err.message : String(err);
        alert('Greška pri preuzimanju slike: ' + msg);
      }
    }
  </script>

</body>

</html>