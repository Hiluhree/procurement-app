<?php

function money($n): string
{
    return 'KSh ' . number_format((float)$n, 2);
}

function e($str): string
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function today(): string
{
    return date('Y-m-d');
}

function format_date($d): string
{
    if (!$d) return '—';
    return date('d M Y', strtotime($d));
}

function format_datetime($d): string
{
    if (!$d) return '—';
    return date('d M Y, H:i', strtotime($d));
}

/** Format a sequential document number directly from a row's auto-increment id, e.g. REQ-0001 */
function doc_no_from_id(string $prefix, int $id, int $pad = 4): string
{
    return $prefix . '-' . str_pad((string)$id, $pad, '0', STR_PAD_LEFT);
}

/** Status label + badge colour class */
function status_badge(string $status): string
{
    $map = [
        'pending_procurement' => ['Pending Procurement', 'amber'],
        'pending_finance'     => ['Pending Finance', 'blue'],
        'pending_principal'   => ['Pending Principal', 'purple'],
        'approved'            => ['Approved', 'green'],
        'rejected'            => ['Rejected', 'red'],
        'sent_to_supplier'    => ['Sent to Supplier', 'blue'],
        'goods_received'      => ['Goods Received', 'green'],
        'pending_payment'     => ['Pending Payment', 'amber'],
        'partially_paid'      => ['Partially Paid', 'orange'],
        'paid'                => ['Paid', 'green'],
    ];
    [$label, $class] = $map[$status] ?? [ucfirst(str_replace('_', ' ', $status)), 'gray'];
    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
}

const APPROVAL_ROLES = ['procurement', 'finance', 'principal'];
const REQUISITION_ROLES = ['requester', 'procurement', 'finance', 'principal', 'admin'];
const ROLE_LABELS = [
    'procurement' => 'Procurement',
    'finance'     => 'Finance',
    'principal'   => 'Principal',
    'requester'   => 'Requester',
    'admin'       => 'Administrator',
];

function role_label(string $role): string
{
    return ROLE_LABELS[$role] ?? ucfirst($role);
}

/** Given a requisition/lpo status, return which role's action is currently pending, or null */
function current_pending_role(string $status): ?string
{
    return match ($status) {
        'pending_procurement' => 'procurement',
        'pending_finance'     => 'finance',
        'pending_principal'   => 'principal',
        default               => null,
    };
}

function next_status_after(string $role, string $documentType): string
{
    if ($documentType === 'requisition') {
        return match ($role) {
            'procurement' => 'pending_finance',
            'finance'     => 'pending_principal',
            'principal'   => 'approved',
        };
    }
    // lpo: procurement stage is auto-approved at creation, so flow starts at finance
    return match ($role) {
        'finance'   => 'pending_principal',
        'principal' => 'approved',
    };
}

/** Fetch all recorded approvals for a document, keyed by role */
function get_approvals(PDO $pdo, string $type, int $documentId): array
{
    $stmt = $pdo->prepare('SELECT * FROM approvals WHERE document_type = ? AND document_id = ?');
    $stmt->execute([$type, $documentId]);
    $rows = $stmt->fetchAll();
    $byRole = [];
    foreach ($rows as $row) {
        $byRole[$row['role']] = $row;
    }
    return $byRole;
}

function flash(string $msg, string $type = 'success'): void
{
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function get_flash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function redirect(string $path): void
{
    header('Location: ' . BASE_PATH . $path);
    exit;
}

/**
 * ============================================================
 * RFQ (Request for Quotation) Workflow Functions
 * ============================================================
 */

/** Generate RFQ number from ID, e.g. RFQ-0001 */
function rfq_no(int $id): string
{
    return doc_no_from_id('RFQ', $id);
}

/** Generate quotation number from ID, e.g. QT-0001 */
function quotation_no(int $id): string
{
    return doc_no_from_id('QT', $id);
}

/** Get status badge for RFQ or quotation */
function rfq_status_badge(string $status): string
{
    $map = [
        'draft'      => ['Draft', 'gray'],
        'issued'     => ['Issued', 'blue'],
        'evaluating' => ['Evaluating', 'amber'],
        'awarded'    => ['Awarded', 'green'],
        'cancelled'  => ['Cancelled', 'red'],
        'submitted'  => ['Submitted', 'blue'],
        'evaluated'  => ['Evaluated', 'amber'],
        'awarded'    => ['Awarded', 'green'],
        'rejected'   => ['Rejected', 'red'],
    ];
    [$label, $class] = $map[$status] ?? [ucfirst(str_replace('_', ' ', $status)), 'gray'];
    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
}

/**
 * ============================================================
 * Email Notification Functions
 * ============================================================
 */

/**
 * Send email notification for workflow events
 * @param PDO $pdo Database connection
 * @param string $toEmail Recipient email address
 * @param int|null $toUserId Recipient user ID (for lookup)
 * @param string $subject Email subject
 * @param string $htmlBody HTML email body
 * @param string $eventType Type of event (e.g., 'requisition_approval', 'lpo_status_change')
 * @param string $documentType Type of document (requisition, lpo, grn, etc.)
 * @param int $documentId ID of the document
 * @param string|null $documentNumber Document number (REQ-0001, etc.)
 * @return bool True if sent/logged successfully
 */
function send_email_notification(
    PDO $pdo,
    string $toEmail,
    ?int $toUserId,
    string $subject,
    string $htmlBody,
    string $eventType,
    string $documentType,
    int $documentId,
    ?string $documentNumber = null
): bool {
    // Log the notification intent
    $stmt = $pdo->prepare('
        INSERT INTO email_notifications 
        (recipient_email, recipient_user_id, event_type, document_type, document_id, document_number, subject, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ');
    
    try {
        // In production, you would use mail() or a service like SendGrid, PHPMailer, etc.
        // For now, we'll just log the notification
        $stmt->execute([
            $toEmail,
            $toUserId,
            $eventType,
            $documentType,
            $documentId,
            $documentNumber,
            $subject,
            'sent'  // In production, set to 'pending' and send asynchronously
        ]);
        return true;
    } catch (PDOException $e) {
        error_log("Failed to log email notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Notify requisition approvers when status changes
 * @param PDO $pdo Database connection
 * @param int $requisitionId ID of requisition
 * @param string $newStatus New status of requisition
 * @param int $userId User ID who made the change
 */
function notify_requisition_approvers(PDO $pdo, int $requisitionId, string $newStatus, int $userId): void
{
    // Get requisition details
    $req_stmt = $pdo->prepare('SELECT * FROM requisitions WHERE id = ?');
    $req_stmt->execute([$requisitionId]);
    $requisition = $req_stmt->fetch();
    
    if (!$requisition) return;
    
    // Get user who made the change
    $user_stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $user_stmt->execute([$userId]);
    $user = $user_stmt->fetch();
    
    // Determine who to notify based on new status
    $notifyRole = current_pending_role($newStatus);
    if (!$notifyRole) return;
    
    // Get all active users with that role
    $notify_stmt = $pdo->prepare('
        SELECT id, name, email, phone_number FROM users 
        WHERE role = ? AND is_active = 1 AND email IS NOT NULL
    ');
    $notify_stmt->execute([$notifyRole]);
    $recipients = $notify_stmt->fetchAll();
    
    $statusLabel = ucfirst(str_replace('_', ' ', $newStatus));
    
    foreach ($recipients as $recipient) {
        $subject = "Requisition Approval Required: {$requisition['req_no']}";
        $htmlBody = "
            <h2>Approval Required</h2>
            <p>Hello {$recipient['name']},</p>
            <p>Requisition <strong>{$requisition['req_no']}</strong> requires your {$notifyRole} approval.</p>
            <p><strong>Status:</strong> $statusLabel</p>
            <p><strong>Department:</strong> {$requisition['department']}</p>
            <p>Please log in to review and approve this requisition.</p>
        ";
        
        send_email_notification(
            $pdo,
            $recipient['email'],
            $recipient['id'],
            $subject,
            $htmlBody,
            'requisition_approval_pending',
            'requisition',
            $requisitionId,
            $requisition['req_no']
        );
    }
}

/**
 * Notify users when LPO status changes
 */
function notify_lpo_approvers(PDO $pdo, int $lpoId, string $newStatus, int $userId): void
{
    $lpo_stmt = $pdo->prepare('
        SELECT l.*, s.name as supplier_name, s.email as supplier_email
        FROM lpos l
        JOIN suppliers s ON l.supplier_id = s.id
        WHERE l.id = ?
    ');
    $lpo_stmt->execute([$lpoId]);
    $lpo = $lpo_stmt->fetch();
    
    if (!$lpo) return;
    
    // Get user who made the change
    $user_stmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
    $user_stmt->execute([$userId]);
    $user = $user_stmt->fetch();
    
    $notifyRole = match ($newStatus) {
        'pending_finance' => 'finance',
        'pending_principal' => 'principal',
        'approved' => null,
        default => null
    };
    
    if (!$notifyRole) return;
    
    // Get workflow-assigned user for this stage
    $config_stmt = $pdo->prepare('
        SELECT assigned_user_id, assigned_role FROM workflow_config
        WHERE stage = CASE ? 
            WHEN "pending_finance" THEN "authorized_by_finance"
            WHEN "pending_principal" THEN "approved_by_principal"
        END
    ');
    $config_stmt->execute([$newStatus]);
    $config = $config_stmt->fetch();
    
    if ($config && $config['assigned_user_id']) {
        $recipient_stmt = $pdo->prepare('SELECT name, email FROM users WHERE id = ? AND is_active = 1');
        $recipient_stmt->execute([$config['assigned_user_id']]);
        $recipient = $recipient_stmt->fetch();
        
        if ($recipient && $recipient['email']) {
            $statusLabel = ucfirst(str_replace('_', ' ', $newStatus));
            $subject = "LPO Approval Required: {$lpo['lpo_no']}";
            $htmlBody = "
                <h2>Action Required</h2>
                <p>Hello {$recipient['name']},</p>
                <p>Local Purchase Order <strong>{$lpo['lpo_no']}</strong> requires your attention.</p>
                <p><strong>Status:</strong> $statusLabel</p>
                <p><strong>Supplier:</strong> {$lpo['supplier_name']}</p>
                <p>Please log in to review and process this LPO.</p>
            ";
            
            send_email_notification(
                $pdo,
                $recipient['email'],
                $config['assigned_user_id'],
                $subject,
                $htmlBody,
                'lpo_approval_pending',
                'lpo',
                $lpoId,
                $lpo['lpo_no']
            );
        }
    }
}

/**
 * Notify requester when their requisition is approved
 */
function notify_requisition_approved(PDO $pdo, int $requisitionId): void
{
    $req_stmt = $pdo->prepare('
        SELECT r.*, u.name, u.email FROM requisitions r
        JOIN users u ON r.applicant_id = u.id
        WHERE r.id = ? AND u.is_active = 1
    ');
    $req_stmt->execute([$requisitionId]);
    $requisition = $req_stmt->fetch();
    
    if (!$requisition || !$requisition['email']) return;
    
    $subject = "Requisition Approved: {$requisition['req_no']}";
    $htmlBody = "
        <h2>Requisition Approved</h2>
        <p>Hello {$requisition['name']},</p>
        <p>Your requisition <strong>{$requisition['req_no']}</strong> has been fully approved!</p>
        <p>Procurement will now proceed with the RFQ process.</p>
    ";
    
    send_email_notification(
        $pdo,
        $requisition['email'],
        $requisition['applicant_id'],
        $subject,
        $htmlBody,
        'requisition_approved',
        'requisition',
        $requisitionId,
        $requisition['req_no']
    );
}

/**
 * Notify Finance when supplier invoice is submitted
 */
function notify_invoice_submitted(PDO $pdo, int $invoiceId): void
{
    $inv_stmt = $pdo->prepare('
        SELECT i.*, l.lpo_no, s.name as supplier_name FROM invoices i
        JOIN lpos l ON i.lpo_id = l.id
        JOIN suppliers s ON i.supplier_id = s.id
        WHERE i.id = ?
    ');
    $inv_stmt->execute([$invoiceId]);
    $invoice = $inv_stmt->fetch();
    
    if (!$invoice) return;
    
    // Get all finance users
    $finance_stmt = $pdo->prepare('
        SELECT id, name, email FROM users
        WHERE role = "finance" AND is_active = 1 AND email IS NOT NULL
    ');
    $finance_stmt->execute();
    $recipients = $finance_stmt->fetchAll();
    
    foreach ($recipients as $recipient) {
        $subject = "Supplier Invoice Received: {$invoice['supplier_name']} - {$invoice['lpo_no']}";
        $htmlBody = "
            <h2>Invoice Submitted</h2>
            <p>Hello {$recipient['name']},</p>
            <p>A new supplier invoice is ready for processing.</p>
            <p><strong>LPO:</strong> {$invoice['lpo_no']}</p>
            <p><strong>Supplier:</strong> {$invoice['supplier_name']}</p>
            <p><strong>Amount:</strong> KSh " . number_format($invoice['amount'], 2) . "</p>
            <p>Please log in to review and process this invoice.</p>
        ";
        
        send_email_notification(
            $pdo,
            $recipient['email'],
            $recipient['id'],
            $subject,
            $htmlBody,
            'invoice_submitted',
            'invoice',
            $invoiceId,
            $invoice['supplier_name']
        );
    }
}

/**
 * ============================================================
 * Three-Way Matching Functions
 * ============================================================
 */

/**
 * Validate three-way match between LPO, GRN, and Invoice
 * Returns array with match status and discrepancies
 */
function validate_three_way_match(PDO $pdo, int $lpoId, ?int $grnId = null, ?int $invoiceId = null): array
{
    $result = [
        'status' => 'unmatched',
        'qty_variance' => null,
        'price_variance' => null,
        'notes' => [],
    ];
    
    // Get LPO total
    $lpo_stmt = $pdo->prepare('
        SELECT SUM(qty * unit_price) as total FROM lpo_items WHERE lpo_id = ?
    ');
    $lpo_stmt->execute([$lpoId]);
    $lpo_total = (float)($lpo_stmt->fetchColumn() ?? 0);
    
    // Get LPO quantities
    $lpo_qty_stmt = $pdo->prepare('SELECT SUM(qty) as total FROM lpo_items WHERE lpo_id = ?');
    $lpo_qty_stmt->execute([$lpoId]);
    $lpo_qty = (float)($lpo_qty_stmt->fetchColumn() ?? 0);
    
    if (!$grnId && !$invoiceId) {
        return $result; // No GRN or Invoice yet
    }
    
    if ($grnId) {
        // Get GRN total
        $grn_stmt = $pdo->prepare('
            SELECT SUM(qty_received * unit_price) as total FROM grn_items WHERE grn_id = ?
        ');
        $grn_stmt->execute([$grnId]);
        $grn_total = (float)($grn_stmt->fetchColumn() ?? 0);
        
        // Get GRN quantities
        $grn_qty_stmt = $pdo->prepare('SELECT SUM(qty_received) as total FROM grn_items WHERE grn_id = ?');
        $grn_qty_stmt->execute([$grnId]);
        $grn_qty = (float)($grn_qty_stmt->fetchColumn() ?? 0);
        
        // Check quantity variance
        if ($grn_qty < $lpo_qty) {
            $result['qty_variance'] = $lpo_qty - $grn_qty;
            $result['notes'][] = "Partial receipt: {$grn_qty} of {$lpo_qty} qty received";
        } elseif ($grn_qty > $lpo_qty) {
            $result['qty_variance'] = $grn_qty - $lpo_qty;
            $result['notes'][] = "Over-receipt: {$grn_qty} received vs {$lpo_qty} ordered";
        }
    }
    
    if ($invoiceId) {
        // Get Invoice total
        $inv_stmt = $pdo->prepare('SELECT amount FROM invoices WHERE id = ?');
        $inv_stmt->execute([$invoiceId]);
        $inv_total = (float)($inv_stmt->fetchColumn() ?? 0);
        
        // Check price variance
        if (abs($inv_total - $lpo_total) > 0.01) {
            $result['price_variance'] = abs($inv_total - $lpo_total);
            if ($inv_total > $lpo_total) {
                $result['notes'][] = "Invoice amount exceeds LPO by " . money($result['price_variance']);
            } else {
                $result['notes'][] = "Invoice amount less than LPO by " . money($result['price_variance']);
            }
        }
    }
    
    // Determine match status
    if (!empty($result['qty_variance']) || !empty($result['price_variance'])) {
        $result['status'] = 'variance';
    } elseif ($grnId && $invoiceId) {
        $result['status'] = 'matched';
    }
    
    return $result;
}

/**
 * Record three-way matching validation
 */
function record_three_way_match(PDO $pdo, int $lpoId, ?int $grnId = null, ?int $invoiceId = null, int $checkedBy): bool
{
    $validation = validate_three_way_match($pdo, $lpoId, $grnId, $invoiceId);
    
    try {
        $stmt = $pdo->prepare('
            INSERT INTO three_way_matches 
            (lpo_id, grn_id, invoice_id, match_status, qty_variance, price_variance, notes, checked_by, checked_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
            match_status = VALUES(match_status),
            qty_variance = VALUES(qty_variance),
            price_variance = VALUES(price_variance),
            notes = VALUES(notes),
            checked_by = VALUES(checked_by),
            checked_at = NOW()
        ');
        
        $stmt->execute([
            $lpoId,
            $grnId,
            $invoiceId,
            $validation['status'],
            $validation['qty_variance'],
            $validation['price_variance'],
            implode('; ', $validation['notes']),
            $checkedBy
        ]);
        
        return true;
    } catch (PDOException $e) {
        error_log("Failed to record three-way match: " . $e->getMessage());
        return false;
    }
}
