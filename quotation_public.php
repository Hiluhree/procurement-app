<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/db.php';

$pdo = db();
$token = trim($_GET['token'] ?? '');

if (!$token) {
    http_response_code(400);
    die('Invalid or missing quotation link.');
}

$stmt = $pdo->prepare('
    SELECT rs.rfq_id, rs.supplier_id, s.name as supplier_name, r.status as rfq_status, r.rfq_no
    FROM rfq_suppliers rs
    JOIN suppliers s ON s.id = rs.supplier_id
    JOIN rfqs r ON r.id = rs.rfq_id
    WHERE rs.token = ?
');
$stmt->execute([$token]);
$link = $stmt->fetch();

if (!$link) {
    http_response_code(404);
    die('Quotation link not found or expired.');
}

if ($link['rfq_status'] === 'awarded' || $link['rfq_status'] === 'cancelled') {
    die('This RFQ has been closed and is no longer accepting quotations.');
}

$rfq_id = (int)$link['rfq_id'];
$supplier_id = (int)$link['supplier_id'];

$items_stmt = $pdo->prepare('SELECT * FROM rfq_items WHERE rfq_id = ?');
$items_stmt->execute([$rfq_id]);
$rfq_items = $items_stmt->fetchAll();

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $quotation_date = trim($_POST['quotation_date'] ?? '');
    $supplier_reference = trim($_POST['supplier_reference'] ?? '');
    $delivery_days = (int)($_POST['delivery_days'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    $qtys = $_POST['qty_offered'] ?? [];
    $prices = $_POST['unit_price'] ?? [];
    $item_notes = $_POST['item_notes'] ?? [];

    if (!$quotation_date || strtotime($quotation_date) > strtotime('today')) {
        $errors[] = 'Please provide a valid quotation date.';
    }

    $items = [];
    foreach ($rfq_items as $i => $rfq_item) {
        $qty = (float)($qtys[$i] ?? 0);
        $price = (float)($prices[$i] ?? 0);
        if ($qty <= 0) {
            $errors[] = "Qty offered must be greater than 0 for item: " . $rfq_item['description'];
        }
        if ($price < 0) {
            $errors[] = "Unit price cannot be negative for item: " . $rfq_item['description'];
        }
        $items[] = [
            'rfq_item_id' => $rfq_item['id'],
            'description' => $rfq_item['description'],
            'unit' => $rfq_item['unit'],
            'qty_offered' => $qty,
            'unit_price' => $price,
            'notes' => trim($item_notes[$i] ?? '')
        ];
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            
            $check_stmt = $pdo->prepare('SELECT id FROM quotations WHERE rfq_id = ? AND supplier_id = ? FOR UPDATE');
            $check_stmt->execute([$rfq_id, $supplier_id]);
            if ($check_stmt->fetch()) {
                $pdo->rollBack();
                $errors[] = 'You have already submitted a quotation for this RFQ.';
            } else {
                $stmt = $pdo->prepare('INSERT INTO quotations (quotation_no, rfq_id, supplier_id, quotation_date, supplier_reference, delivery_days, notes, status) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute(['TEMP', $rfq_id, $supplier_id, $quotation_date, $supplier_reference ?: null, $delivery_days ?: null, $notes ?: null, 'submitted']);
                $quotation_id = (int)$pdo->lastInsertId();
                $quotation_no = doc_no_from_id('QT', $quotation_id);
                $pdo->prepare('UPDATE quotations SET quotation_no = ? WHERE id = ?')->execute([$quotation_no, $quotation_id]);

                $itemIns = $pdo->prepare('INSERT INTO quotation_items (quotation_id, rfq_item_id, description, unit, qty_offered, unit_price, notes) VALUES (?,?,?,?,?,?,?)');
                foreach ($items as $it) {
                    $itemIns->execute([$quotation_id, $it['rfq_item_id'], $it['description'], $it['unit'], $it['qty_offered'], $it['unit_price'], $it['notes'] ?: null]);
                }
                $pdo->commit();
                $success = true;
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = 'Error submitting quotation: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Quotation - <?= e($link['rfq_no'] ?? '') ?></title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 900px; margin: 0 auto; padding: 20px; background: #f5f5f5; }
        .card { background: white; border-radius: 4px; padding: 20px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { color: #333; margin-bottom: 10px; }
        h2 { color: #555; font-size: 18px; margin-bottom: 15px; }
        label { display: block; font-weight: bold; margin-bottom: 5px; color: #333; }
        input[type="text"], input[type="number"], input[type="date"], textarea, select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; margin-bottom: 15px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f9f9f9; font-weight: bold; }
        .btn { background: #C9AA35; color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-size: 14px; }
        .btn:hover { background: #b8982e; }
        .error { color: #d32f2f; background: #ffebee; padding: 10px; border-radius: 4px; margin-bottom: 15px; }
        .success { color: #2e7d32; background: #e8f5e9; padding: 15px; border-radius: 4px; margin-bottom: 15px; }
        .info { color: #666; font-size: 13px; margin-top: 5px; }
        .text-right { text-align: right; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Submit Quotation</h1>
        <p style="color:#666; margin-bottom:20px;">
            <strong>RFQ:</strong> <?= e($link['rfq_no'] ?? '') ?> | 
            <strong>Supplier:</strong> <?= e($link['supplier_name'] ?? '') ?>
        </p>

        <?php if ($success): ?>
            <div class="success">
                <strong>Quotation submitted successfully!</strong><br>
                Thank you for your submission. The procurement team will review your quotation.
            </div>
        <?php else: ?>
            <?php foreach ($errors as $err): ?>
                <div class="error"><?= e($err) ?></div>
            <?php endforeach; ?>

            <form method="post">
                <h2>Quotation Details</h2>
                <label>Quotation Date *</label>
                <input type="date" name="quotation_date" value="<?= e(today()) ?>" required>

                <label>Your Reference Number</label>
                <input type="text" name="supplier_reference" placeholder="Your quotation reference">

                <label>Delivery Days</label>
                <input type="number" name="delivery_days" min="0" placeholder="e.g. 7">

                <label>Notes</label>
                <textarea name="notes" rows="3" placeholder="Any additional notes..."></textarea>

                <h2>Quoted Items</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th>Unit</th>
                            <th class="text-right">RFQ Qty</th>
                            <th class="text-right">Qty Offered</th>
                            <th class="text-right">Unit Price (KES)</th>
                            <th class="text-right">Line Total</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rfq_items as $i => $item): ?>
                            <tr>
                                <td><?= e($item['description']) ?></td>
                                <td><?= e($item['unit'] ?: '—') ?></td>
                                <td class="text-right"><?= number_format($item['qty'], 0) ?></td>
                                <td class="text-right">
                                    <input type="number" step="0.01" min="0.01" name="qty_offered[]" value="<?= e($item['qty']) ?>" required style="width:90px; text-align:right;">
                                </td>
                                <td class="text-right">
                                    <input type="number" step="0.01" min="0" name="unit_price[]" value="0.00" required style="width:110px; text-align:right;">
                                </td>
                                <td class="text-right" id="line-total-<?= $i ?>">KSh 0.00</td>
                                <td><input type="text" name="item_notes[]" placeholder="Optional" style="width:150px;"></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="font-weight:bold; background:#f0f0f0;">
                            <td colspan="5" class="text-right">Grand Total:</td>
                            <td class="text-right" id="grand-total" style="color:#C9AA35;">KSh 0.00</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>

                <button type="submit" class="btn" style="margin-top:20px;">Submit Quotation</button>
            </form>
        <?php endif; ?>
    </div>

    <script>
    (function() {
        const priceInputs = document.querySelectorAll('input[name="unit_price[]"]');
        const qtyInputs = document.querySelectorAll('input[name="qty_offered[]"]');
        
        function recalc() {
            let total = 0;
            priceInputs.forEach((priceInput, i) => {
                const qty = parseFloat(qtyInputs[i].value) || 0;
                const price = parseFloat(priceInput.value) || 0;
                const line = qty * price;
                const lineTotalEl = document.getElementById('line-total-' + i);
                if (lineTotalEl) {
                    lineTotalEl.textContent = 'KSh ' + line.toLocaleString('en-KE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                }
                total += line;
            });
            const grandTotalEl = document.getElementById('grand-total');
            if (grandTotalEl) {
                grandTotalEl.textContent = 'KSh ' + total.toLocaleString('en-KE', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }
        }

        priceInputs.forEach(el => el.addEventListener('input', recalc));
        qtyInputs.forEach(el => el.addEventListener('input', recalc));
        recalc();
    })();
    </script>
</body>
</html>
