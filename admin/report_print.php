<?php
require_once __DIR__ . '/../config/app.php';
require_role('admin');
require_once __DIR__ . '/../includes/report_data.php';

$db = get_db();
$type = $_GET['type'] ?? '';
$validTypes = report_types();

if (!isset($validTypes[$type])) {
    die('Unknown report type.');
}

$viewOnly = ($_GET['mode'] ?? '') === 'view';

$data = fetch_report_data($db, $type);
$rows = $data['rows'];
$columns = $data['columns'];

// Log this generation
$db->prepare('INSERT INTO reports (report_type, generated_by, period_start, period_end, total_records) VALUES (?,?,?,?,?)')
   ->execute([$validTypes[$type], current_user_id(), date('Y-m-01'), date('Y-m-d'), count($rows)]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $validTypes[$type] ?> Report · <?= SITE_NAME ?></title>
<style>
  @page { size: 8.5in 13in; margin: 1in; }
  body { font-family: Arial, sans-serif; color: #212529; background: #e9ecef; margin: 0; padding: 2rem 0; }
  .page-sheet { width: 8.5in; min-height: 13in; margin: 0 auto; background: #fff; padding: 1in; box-shadow: 0 0 12px rgba(0,0,0,.15); box-sizing: border-box; }
  .report-head { display:flex; justify-content:space-between; align-items:center; border-bottom: 3px solid #800000; padding-bottom: 1rem; margin-bottom: 1.5rem; }
  .report-head h1 { font-size: 1.4rem; margin: 0; color: #800000; }
  .report-head p { margin: .2rem 0 0; color: #6c757d; font-size: .85rem; }
  table { width: 100%; border-collapse: collapse; font-size: .85rem; }
  th, td { border: 1px solid #dee2e6; padding: 8px 10px; text-align: left; }
  th { background: #f8f4f5; color: #800000; }
  tr:nth-child(even) { background: #fafafa; }
  html { scrollbar-color: #800000 #f1f1f1; scrollbar-width: thin; }
  ::-webkit-scrollbar { width: 10px; height: 10px; }
  ::-webkit-scrollbar-track { background: #f1f1f1; }
  ::-webkit-scrollbar-thumb { background: #800000; border-radius: 999px; }
  ::-webkit-scrollbar-thumb:hover { background: #5c1115; }
  @media print {
    .no-print { display:none; }
    body { background: #fff; padding: 0; }
    .page-sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
  }
</style>
</head>
<body>
  <div class="page-sheet">
    <div class="report-head">
      <div>
        <h1><?= SITE_NAME ?></h1>
        <p><?= $validTypes[$type] ?> Report · Generated <?= date('F j, Y g:i A') ?> · <?= count($rows) ?> record(s)</p>
      </div>
      <button class="btn btn-sm btn-action-primary no-print" onclick="window.print()"><i class="bi bi-printer-fill"></i> Print</button>
    </div>

    <?= render_report_table($columns, $rows) ?>
  </div>

  <?php if (!$viewOnly): ?>
  <script>window.addEventListener('load', function () { window.print(); });</script>
  <?php endif; ?>
</body>
</html>
