<?php
//
// 1.0.0 - Inicialna verzija
// UBL 2.1 (HR CIUS 2025) XML → Human readable
// Vibe code by ChatGPT by Dalibor Klobučarić 
// and ChatGPT
// 

declare(strict_types=1);

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function xpValue(DOMXPath $xp, string $q): string {
  $n = $xp->query($q)->item(0);
  return $n ? trim($n->textContent) : '';
}

function xpValueCtx(DOMXPath $xp, string $q, ?DOMNode $ctx = null): string {
  $n = $xp->query($q, $ctx)->item(0);
  return $n ? trim($n->textContent) : '';
}

function xpFirst(DOMXPath $xp, array $queries): string {
  foreach ($queries as $q) {
    $v = xpValue($xp, $q);
    if ($v !== '') return $v;
  }
  return '';
}

function partyNameFromBase(DOMXPath $xp, string $base): string {
  // base = XPath to cac:Party
  return xpFirst($xp, [
    $base . '/cac:PartyName/cbc:Name',
    $base . '/cac:PartyLegalEntity/cbc:RegistrationName',
    $base . '/cac:PartyLegalEntity/cbc:CompanyName',
    $base . '/cac:PartyIdentification/cbc:ID',
  ]);
}

function normalizeIban(string $iban): string {
  $iban = strtoupper($iban);
  return preg_replace('/[^A-Z0-9]/', '', $iban) ?? '';
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

  // Namespaces
  $xp->registerNamespace('ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2');
  $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
  $xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
  $xp->registerNamespace('ext', 'urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2');
  $xp->registerNamespace('hrextac', 'urn:mfin.gov.hr:schema:xsd:HRExtensionAggregateComponents-1');

  $supplierPartyBase   = '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party';
  $customerPartyBase   = '/ubl:Invoice/cac:AccountingCustomerParty/cac:Party';
  $supplierAddressBase = $supplierPartyBase . '/cac:PostalAddress';
  $customerAddressBase = $customerPartyBase . '/cac:PostalAddress';

  $data = [
    'invoice_id' => xpValue($xp, '/ubl:Invoice/cbc:ID'),
    'issue_date' => xpValue($xp, '/ubl:Invoice/cbc:IssueDate'),
    'issue_time' => xpValue($xp, '/ubl:Invoice/cbc:IssueTime'),
    'due_date'   => xpValue($xp, '/ubl:Invoice/cbc:DueDate'),
    'currency'   => xpValue($xp, '/ubl:Invoice/cbc:DocumentCurrencyCode'),
    'note'       => xpValue($xp, '/ubl:Invoice/cbc:Note'),

    'supplier' => [
      'name'        => partyNameFromBase($xp, $supplierPartyBase),
      'oib'         => xpValue($xp, $supplierPartyBase . '/cac:PartyLegalEntity/cbc:CompanyID'),
      'vat'         => xpValue($xp, $supplierPartyBase . '/cac:PartyTaxScheme/cbc:CompanyID'),
      'email'       => xpValue($xp, $supplierPartyBase . '/cac:Contact/cbc:ElectronicMail'),
      'street'      => xpValue($xp, $supplierAddressBase . '/cbc:StreetName'),
      'building'    => xpValue($xp, $supplierAddressBase . '/cbc:BuildingNumber'),
      'city'        => xpValue($xp, $supplierAddressBase . '/cbc:CityName'),
      'postal'      => xpValue($xp, $supplierAddressBase . '/cbc:PostalZone'),
      'country'     => xpValue($xp, $supplierAddressBase . '/cac:Country/cbc:IdentificationCode'),
      'subdivision' => xpValue($xp, $supplierAddressBase . '/cbc:CountrySubentity'),
      'line'        => xpValue($xp, $supplierAddressBase . '/cac:AddressLine/cbc:Line'),
    ],

    'customer' => [
      'name'        => partyNameFromBase($xp, $customerPartyBase),
      'oib'         => xpValue($xp, $customerPartyBase . '/cac:PartyLegalEntity/cbc:CompanyID'),
      'vat'         => xpValue($xp, $customerPartyBase . '/cac:PartyTaxScheme/cbc:CompanyID'),
      'email'       => xpValue($xp, $customerPartyBase . '/cac:Contact/cbc:ElectronicMail'),
      'street'      => xpValue($xp, $customerAddressBase . '/cbc:StreetName'),
      'building'    => xpValue($xp, $customerAddressBase . '/cbc:BuildingNumber'),
      'city'        => xpValue($xp, $customerAddressBase . '/cbc:CityName'),
      'postal'      => xpValue($xp, $customerAddressBase . '/cbc:PostalZone'),
      'country'     => xpValue($xp, $customerAddressBase . '/cac:Country/cbc:IdentificationCode'),
      'subdivision' => xpValue($xp, $customerAddressBase . '/cbc:CountrySubentity'),
      'line'        => xpValue($xp, $customerAddressBase . '/cac:AddressLine/cbc:Line'),
    ],

    'totals' => [
      'net'   => xpValue($xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount'),
      'vat'   => xpValue($xp, '/ubl:Invoice/cac:TaxTotal/cbc:TaxAmount'),
      'gross' => xpValue($xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount'),
    ],

    'payment' => [
      'means_code' => xpValue($xp, '/ubl:Invoice/cac:PaymentMeans/cbc:PaymentMeansCode'),
      'note'       => xpValue($xp, '/ubl:Invoice/cac:PaymentMeans/cbc:InstructionNote'),
      'payment_id' => xpValue($xp, '/ubl:Invoice/cac:PaymentMeans/cbc:PaymentID'),
      'iban_raw'   => xpValue($xp, '/ubl:Invoice/cac:PaymentMeans/cac:PayeeFinancialAccount/cbc:ID'),
      'iban'       => '',
    ],

    'tax_breakdown' => [],
    'lines' => [],

    'attachment_pdf' => [
      'filename' => '',
      'mime'     => '',
      'b64'      => '',
      'size'     => 0,
    ],
  ];

  $data['payment']['iban'] = normalizeIban($data['payment']['iban_raw']);

  // Lines
  foreach ($xp->query('/ubl:Invoice/cac:InvoiceLine') as $line) {
   /** @var DOMElement|null $qtyN */
$qtyN = $xp->query('cbc:InvoicedQuantity', $line)->item(0);

$qty = ($qtyN instanceof DOMElement)
  ? trim($qtyN->textContent)
  : '';

$uom = ($qtyN instanceof DOMElement && $qtyN->hasAttribute('unitCode'))
  ? $qtyN->getAttribute('unitCode')
  : '';


    $data['lines'][] = [
      'id'          => xpValueCtx($xp, 'cbc:ID', $line),
      'name'        => xpValueCtx($xp, 'cac:Item/cbc:Name', $line),
      'qty'         => $qty,
      'uom'         => $uom,
      'price'       => xpValueCtx($xp, 'cac:Price/cbc:PriceAmount', $line),
      'net'         => xpValueCtx($xp, 'cbc:LineExtensionAmount', $line),
      'tax_percent' => xpValueCtx($xp, 'cac:Item/cac:ClassifiedTaxCategory/cbc:Percent', $line),
    ];
  }

  // TAX BREAKDOWN (prefer HRFISK20 if present, else fallback to UBL)
  $taxRows = [];

  foreach ($xp->query('/ubl:Invoice/ext:UBLExtensions/ext:UBLExtension/ext:ExtensionContent/hrextac:HRFISK20Data/hrextac:HRTaxTotal/hrextac:HRTaxSubtotal') as $sub) {
    /** @var DOMElement $sub */
    $taxRows[] = [
      'source'  => 'HRFISK20',
      'cat_id'  => xpValueCtx($xp, 'hrextac:HRTaxCategory/cbc:ID', $sub),
      'cat'     => xpValueCtx($xp, 'hrextac:HRTaxCategory/cbc:Name', $sub),
      'percent' => xpValueCtx($xp, 'hrextac:HRTaxCategory/cbc:Percent', $sub),
      'taxable' => xpValueCtx($xp, 'cbc:TaxableAmount', $sub),
      'tax'     => xpValueCtx($xp, 'cbc:TaxAmount', $sub),
    ];
  }

  if (count($taxRows) === 0) {
    foreach ($xp->query('/ubl:Invoice/cac:TaxTotal/cac:TaxSubtotal') as $sub) {
      /** @var DOMElement $sub */
      $taxRows[] = [
        'source'  => 'UBL',
        'cat_id'  => xpValueCtx($xp, 'cac:TaxCategory/cbc:ID', $sub),
        'cat'     => '',
        'percent' => xpValueCtx($xp, 'cac:TaxCategory/cbc:Percent', $sub),
        'taxable' => xpValueCtx($xp, 'cbc:TaxableAmount', $sub),
        'tax'     => xpValueCtx($xp, 'cbc:TaxAmount', $sub),
      ];
    }
  }

  $data['tax_breakdown'] = $taxRows;

  // Embedded PDF attachment (if present)
  $attNode = $xp->query('/ubl:Invoice/cac:AdditionalDocumentReference/cac:Attachment/cbc:EmbeddedDocumentBinaryObject')->item(0);
  if ($attNode) {
    $data['attachment_pdf']['b64'] = trim($attNode->textContent);
    $data['attachment_pdf']['mime'] = ($attNode->attributes && $attNode->attributes->getNamedItem('mimeCode'))
      ? $attNode->attributes->getNamedItem('mimeCode')->nodeValue
      : '';
    $data['attachment_pdf']['filename'] = ($attNode->attributes && $attNode->attributes->getNamedItem('filename'))
      ? $attNode->attributes->getNamedItem('filename')->nodeValue
      : 'racun.pdf';

    $b64 = $data['attachment_pdf']['b64'];
    if ($b64 !== '') {
      $pad = 0;
      if (substr($b64, -2) === '==') $pad = 2;
      elseif (substr($b64, -1) === '=') $pad = 1;
      $data['attachment_pdf']['size'] = (int) floor((strlen($b64) * 3) / 4) - $pad;
    }
  }

  return $data;
}

$parsed = null;
$error = null;
$xmlPayload = null;

// POST handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    // A) Download PDF mode
    if (isset($_POST['download_pdf']) && isset($_POST['xml_payload'])) {
      $xml = base64_decode((string)$_POST['xml_payload'], true);
      if ($xml === false || trim($xml) === '') {
        throw new RuntimeException('Invalid XML payload.');
      }

      $tmp = parseUblInvoice($xml);
      $b64 = $tmp['attachment_pdf']['b64'] ?? '';
      if ($b64 === '') throw new RuntimeException('PDF nije pronađen u XML-u.');

      $pdf = base64_decode($b64, true);
      if ($pdf === false) throw new RuntimeException('Neispravan Base64 PDF.');

      if (strncmp($pdf, "%PDF", 4) !== 0) throw new RuntimeException('Attachment nije PDF (ne počinje s %PDF).');

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

    if (!empty($_FILES['xml']['size']) && $_FILES['xml']['size'] > 5 * 1024 * 1024) {
      throw new RuntimeException('File too large (max 5 MB).');
    }

    $xml = file_get_contents($_FILES['xml']['tmp_name']);
    if ($xml === false || trim($xml) === '') {
      throw new RuntimeException('Empty file.');
    }

    $parsed = parseUblInvoice($xml);
    $xmlPayload = base64_encode($xml);

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
    .copy-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
    .copy-btn{border:1px solid #ccc;border-radius:10px;padding:6px 10px;background:#fff;cursor:pointer}
    .copy-btn:hover{background:#fafafa}
    .pill{display:inline-block;border:1px solid #ddd;border-radius:999px;padding:2px 8px;font-size:12px;background:#fafafa}
  </style>
</head>
<body>

  <h1>UBL 2.1 (HR CIUS 2025) XML → Human readable</h1>

  <div class="card">
    <form method="post" enctype="multipart/form-data">
      <label><b>Upload UBL 2.1 XML:</b></label><br>
      <input type="file" name="xml" accept=".xml,text/xml,application/xml" required>
      <button type="submit">Prikaži</button>
    </form>
    <p class="muted">Preglednik: dobavljač/kupac, adrese, iznosi, PDV razrada, plaćanje, stavke, PDF prilog.</p>
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

    <?php
      $s = $parsed['supplier'];
      $sStreetFull = trim(($s['street'] ?? '') . (empty($s['building']) ? '' : ' ' . $s['building']));
      $sCityLine   = trim(($s['postal'] ?? '') . ' ' . ($s['city'] ?? ''));
      $sCountry    = $s['country'] ?? '';
      $sLineFallback = $s['line'] ?? '';

      $c = $parsed['customer'];
      $cStreetFull = trim(($c['street'] ?? '') . (empty($c['building']) ? '' : ' ' . $c['building']));
      $cCityLine   = trim(($c['postal'] ?? '') . ' ' . ($c['city'] ?? ''));
      $cCountry    = $c['country'] ?? '';
      $cLineFallback = $c['line'] ?? '';
    ?>

    <div class="card">
      <h3>Dobavljač</h3>
      <div><b><?=h($s['name'])?></b></div>
      <div class="muted">OIB: <?=h($s['oib'])?> | VAT: <?=h($s['vat'])?></div>

      <?php if ($sStreetFull !== ''): ?>
        <div><?=h($sStreetFull)?></div>
      <?php elseif ($sLineFallback !== ''): ?>
        <div><?=h($sLineFallback)?></div>
      <?php endif; ?>

      <div class="muted">
        <?php if ($sCityLine !== ''): ?><?=h($sCityLine)?><?php endif; ?>
        <?php if (!empty($s['subdivision'])): ?> · <?=h($s['subdivision'])?><?php endif; ?>
        <?php if ($sCountry !== ''): ?> · <?=h($sCountry)?><?php endif; ?>
      </div>

      <?php if (!empty($s['email'])): ?><div>Email: <?=h($s['email'])?></div><?php endif; ?>
    </div>

    <div class="card">
      <h3>Kupac</h3>
      <div><b><?=h($c['name'])?></b></div>
      <div class="muted">OIB: <?=h($c['oib'])?> | VAT: <?=h($c['vat'])?></div>

      <?php if ($cStreetFull !== ''): ?>
        <div><?=h($cStreetFull)?></div>
      <?php elseif ($cLineFallback !== ''): ?>
        <div><?=h($cLineFallback)?></div>
      <?php endif; ?>

      <div class="muted">
        <?php if ($cCityLine !== ''): ?><?=h($cCityLine)?><?php endif; ?>
        <?php if (!empty($c['subdivision'])): ?> · <?=h($c['subdivision'])?><?php endif; ?>
        <?php if ($cCountry !== ''): ?> · <?=h($cCountry)?><?php endif; ?>
      </div>

      <?php if (!empty($c['email'])): ?><div>Email: <?=h($c['email'])?></div><?php endif; ?>
    </div>

    <?php if (!empty($parsed['tax_breakdown'])): ?>
      <div class="card">
        <h3>PDV razrada</h3>
        <table>
          <thead>
            <tr>
              <th>Izvor</th>
              <th>Kategorija</th>
              <th>Stopa</th>
              <th>Osnovica</th>
              <th>PDV</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($parsed['tax_breakdown'] as $t): ?>
              <tr>
                <td><span class="muted"><?=h($t['source'])?></span></td>
                <td>
                  <?=h($t['cat_id'])?>
                  <?php if (!empty($t['cat'])): ?>
                    <span class="muted">— <?=h($t['cat'])?></span>
                  <?php endif; ?>
                </td>
                <td><?=h($t['percent'])?>%</td>
                <td><?=h($t['taxable'])?> <?=h($parsed['currency'])?></td>
                <td><?=h($t['tax'])?> <?=h($parsed['currency'])?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="card">
      <h3>Plaćanje</h3>

      <div class="copy-row">
        <div><b>PaymentMeansCode:</b> <code><?=h($parsed['payment']['means_code'])?></code></div>
        <?php if (!empty($parsed['payment']['note'])): ?>
          <span class="pill"><?=h($parsed['payment']['note'])?></span>
        <?php endif; ?>
      </div>

      <?php if (!empty($parsed['payment']['payment_id'])): ?>
        <div class="copy-row" style="margin-top:10px">
          <div><b>Poziv/PaymentID:</b> <code id="payid"><?=h($parsed['payment']['payment_id'])?></code></div>
          <button class="copy-btn" type="button" data-copy-target="payid">Copy</button>
        </div>
      <?php endif; ?>

      <?php if (!empty($parsed['payment']['iban'])): ?>
        <div class="copy-row" style="margin-top:10px">
          <div><b>IBAN (normalizirano):</b> <code id="iban"><?=h($parsed['payment']['iban'])?></code></div>
          <button class="copy-btn" type="button" data-copy-target="iban">Copy</button>
        </div>
        <?php if (!empty($parsed['payment']['iban_raw']) && $parsed['payment']['iban_raw'] !== $parsed['payment']['iban']): ?>
          <div class="muted" style="margin-top:6px">
            Original: <code><?=h($parsed['payment']['iban_raw'])?></code>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <?php if (!empty($parsed['attachment_pdf']['b64']) && !empty($xmlPayload)): ?>
      <div class="card">
        <h3>PDF prilog</h3>
        <div class="muted">
          <?=h($parsed['attachment_pdf']['filename'] ?: 'racun.pdf')?>
          <?php if (!empty($parsed['attachment_pdf']['mime'])): ?> · <?=h($parsed['attachment_pdf']['mime'])?><?php endif; ?>
          <?php if (!empty($parsed['attachment_pdf']['size'])): ?> · <?=h((string)round($parsed['attachment_pdf']['size']/1024))?> KB<?php endif; ?>
        </div>

        <form method="post" style="margin-top:10px">
          <input type="hidden" name="download_pdf" value="1">
          <input type="hidden" name="xml_payload" value="<?=h($xmlPayload)?>">
          <button class="copy-btn" type="submit">Preuzmi PDF</button>
        </form>
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
              <td><?=h($l['tax_percent'])?>%</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

<script>
(function () {
  async function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return true;
    }
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    const ok = document.execCommand('copy');
    document.body.removeChild(ta);
    return ok;
  }

  function flash(btn, msg) {
    const old = btn.textContent;
    btn.textContent = msg;
    btn.disabled = true;
    setTimeout(() => { btn.textContent = old; btn.disabled = false; }, 900);
  }

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('button[data-copy-target]');
    if (!btn) return;

    const id = btn.getAttribute('data-copy-target');
    const el = document.getElementById(id);
    if (!el) return;

    const text = (el.textContent || '').trim();
    if (!text) return;

    try {
      const ok = await copyText(text);
      flash(btn, ok ? 'Copied!' : 'Copy?');
    } catch {
      flash(btn, 'Nope');
    }
  });
})();
</script>

</body>
</html>