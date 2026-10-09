<?php
if (!defined('BASE_URL')) { http_response_code(403); exit('Direct access not permitted.'); }

/** Generate and email an invoice after a payment first becomes Paid. */
function send_payment_invoice(PDO $db, int $paymentId): bool
{
    try {
        $stmt = $db->prepare("SELECT p.payment_id, p.payment_amount, p.payment_for_month, p.payment_date, p.paid_at,
                                     u.first_name, u.last_name, u.email, r.room_number,
                                     c.monthly_rent
                              FROM payments p
                              JOIN tenants t ON t.tenant_id = p.tenant_id
                              JOIN users u ON u.user_id = t.user_id
                              JOIN contracts c ON c.contract_id = p.contract_id
                              JOIN dorm_rooms r ON r.room_id = c.room_id
                              WHERE p.payment_id = ? AND p.payment_status = 'Paid'");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        if (!$payment || !filter_var($payment['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $tenantName = trim($payment['first_name'] . ' ' . $payment['last_name']);
        $month = $payment['payment_for_month'] ?: 'Not specified';
        $amount = number_format((float) $payment['payment_amount'], 2);
        $rent = number_format((float) $payment['monthly_rent'], 2);
        $transactionTime = $payment['paid_at'] ?: ($payment['payment_date'] ?: date('Y-m-d H:i:s'));
        $date = date('F j, Y g:i A', strtotime($transactionTime));
        $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $invoiceHtml = '<div style="font-family:Arial,sans-serif;max-width:620px;margin:0 auto;color:#20252b">'
            . '<div style="background:#800000;color:#fff;padding:20px 24px"><strong>' . $escape(SITE_NAME) . '</strong><div style="font-size:22px;margin-top:12px">Payment receipt</div></div>'
            . '<div style="padding:24px;border:1px solid #e5e7eb"><p>Hello ' . $escape($tenantName) . ',</p>'
            . '<p>Your payment has been recorded as paid. Keep this invoice for your records.</p>'
            . '<table style="width:100%;border-collapse:collapse">'
            . '<tr><td style="padding:10px;border-bottom:1px solid #e5e7eb">Invoice</td><td style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:right">#' . (int) $payment['payment_id'] . '</td></tr>'
            . '<tr><td style="padding:10px;border-bottom:1px solid #e5e7eb">Room</td><td style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:right">' . $escape($payment['room_number']) . '</td></tr>'
            . '<tr><td style="padding:10px;border-bottom:1px solid #e5e7eb">Billing month</td><td style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:right">' . $escape($month) . '</td></tr>'
            . '<tr><td style="padding:10px;border-bottom:1px solid #e5e7eb">Monthly room rent</td><td style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:right">PHP ' . $rent . '</td></tr>'
            . '<tr><td style="padding:10px;border-bottom:1px solid #e5e7eb">Amount paid</td><td style="padding:10px;border-bottom:1px solid #e5e7eb;text-align:right"><strong>PHP ' . $amount . '</strong></td></tr>'
            . '<tr><td style="padding:10px">Transaction timestamp</td><td style="padding:10px;text-align:right">' . $escape($date) . '</td></tr>'
            . '</table></div></div>';

        $tempPdf = null;
        $attachmentName = 'invoice-' . (int) $payment['payment_id'] . '.pdf';
        try {
            $autoload = __DIR__ . '/../vendor/autoload.php';
            if (is_file($autoload)) {
                require_once $autoload;
            }
            if (class_exists(Dompdf\Dompdf::class)) {
                $tempPdf = tempnam(sys_get_temp_dir(), 'dorm-invoice-');
                if ($tempPdf !== false) {
                    $pdf = new Dompdf\Dompdf();
                    $pdf->loadHtml('<html><meta charset="UTF-8"><body>' . $invoiceHtml . '</body></html>');
                    $pdf->setPaper('A4');
                    $pdf->render();
                    file_put_contents($tempPdf, $pdf->output());
                }
            }
        } catch (Throwable $e) {
            error_log('Invoice PDF generation failed for payment ' . $paymentId . ': ' . $e->getMessage());
            if (is_string($tempPdf) && is_file($tempPdf)) {
                unlink($tempPdf);
            }
            $tempPdf = null;
        }

        try {
            return send_email_alert(
                $payment['email'],
                $tenantName,
                'Payment invoice for ' . $month,
                $invoiceHtml,
                is_string($tempPdf) ? $tempPdf : null,
                $attachmentName
            );
        } finally {
            if (is_string($tempPdf) && is_file($tempPdf)) {
                unlink($tempPdf);
            }
        }
    } catch (Throwable $e) {
        error_log('Invoice delivery failed for payment ' . $paymentId . ': ' . $e->getMessage());
        return false;
    }
}