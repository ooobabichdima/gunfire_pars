<?php
$logDir = dirname(__DIR__, 2) . '/logs';
$files = glob($logDir . '/*.log');
usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));

$selectedFile = $_GET['file'] ?? '';
$levelFilter = $_GET['level'] ?? '';
$lines = [];

if ($selectedFile && preg_match('/^[\w\-\.]+\.log$/', $selectedFile)) {
    $path = $logDir . '/' . $selectedFile;
    if (file_exists($path)) {
        $allLines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $allLines = array_slice($allLines, -500);
        $allLines = array_reverse($allLines);

        if ($levelFilter) {
            $allLines = array_filter($allLines, fn($l) => str_contains($l, '[' . strtoupper($levelFilter) . ']'));
        }

        $lines = array_slice($allLines, 0, 200);
    }
}
?>

<h4 class="mb-3">Logs</h4>

<form class="row g-2 mb-3">
    <input type="hidden" name="page" value="logs">
    <div class="col-md-4">
        <select name="file" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">Select log file...</option>
            <?php foreach ($files as $f):
                $name = basename($f);
                $size = round(filesize($f) / 1024, 1); ?>
                <option value="<?= esc($name) ?>" <?= $selectedFile === $name ? 'selected' : '' ?>><?= esc($name) ?> (<?= $size ?>KB)</option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <select name="level" class="form-select form-select-sm">
            <option value="">All levels</option>
            <?php foreach (['ERROR','WARNING','INFO','DEBUG'] as $lv): ?>
                <option value="<?= strtolower($lv) ?>" <?= $levelFilter === strtolower($lv) ? 'selected' : '' ?>><?= $lv ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-primary">Filter</button></div>
</form>

<?php if (!empty($lines)): ?>
<div class="bg-dark text-light p-3 rounded" style="max-height: 600px; overflow-y: auto; font-family: monospace; font-size: 12px; white-space: pre-wrap;">
<?php foreach ($lines as $line):
    $class = '';
    if (str_contains($line, '[ERROR]')) $class = 'text-danger';
    elseif (str_contains($line, '[WARNING]')) $class = 'text-warning';
    elseif (str_contains($line, '[DEBUG]')) $class = 'text-secondary';
?><span class="<?= $class ?>"><?= esc($line) ?></span>
<?php endforeach; ?>
</div>
<?php elseif ($selectedFile): ?>
    <div class="alert alert-info">No log entries found.</div>
<?php endif; ?>
