<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');
require_once __DIR__ . '/../includes/report_data.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$db = get_db();
$type = $_GET['type'] ?? '';
$validTypes = report_types();

if (!isset($validTypes[$type])) {
    http_response_code(400);
    die('Unknown report type.');
}

$data = fetch_report_data($db, $type);
$rows = $data['rows'];
$columns = $data['columns'];

// Log this generation
$db->prepare('INSERT INTO reports (report_type, generated_by, period_start, period_end, total_records) VALUES (?,?,?,?,?)')
   ->execute([$validTypes[$type], current_user_id(), date('Y-m-01'), date('Y-m-d'), count($rows)]);

$table = render_report_table($columns, $rows);
$siteName = htmlspecialchars(SITE_NAME);
$reportTitle = htmlspecialchars($validTypes[$type]);
$generatedAt = htmlspecialchars(date('F j, Y g:i A'));
$recordCount = count($rows);

$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
  body { font-family: Helvetica, Arial, sans-serif; color: #212529; }
  .report-head { border-bottom: 3px solid #800000; padding-bottom: 10px; margin-bottom: 20px; }
  .report-head h1 { font-size: 18px; margin: 0; color: #800000; }
  .report-head p { margin: 4px 0 0; color: #6c757d; font-size: 11px; }
  table { width: 100%; border-collapse: collapse; font-size: 11px; }
  th, td { border: 1px solid #dee2e6; padding: 6px 8px; text-align: left; }
  th { background: #f8f4f5; color: #800000; }
  tr:nth-child(even) { background: #fafafa; }
</style>
</head>
<body>
  <div class="report-head">
    <h1>{$siteName}</h1>
    <p>{$reportTitle} Report &middot; Generated {$generatedAt} &middot; {$recordCount} record(s)</p>
  </div>
  {$table}
</body>
</html>
HTML;

$options = new Options();
$options->set('isRemoteEnabled', false);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = $validTypes[$type] . '_Report_' . date('Y-m-d') . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
