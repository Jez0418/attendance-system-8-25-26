<?php
/**
 * teacher/session.php
 * The core teacher workflow:
 *   1. Pick one of your assigned classes
 *   2. Click "Activate Attendance" -> creates/resumes today's session
 *      and displays a live QR code students can scan
 *   3. Watch students appear in the live "Scanned" list (AJAX polling)
 *   4. Click "Deactivate" to close the session
 *
 * Late logic: scheduled_start = TODAY + the class's official start_time.
 * A scan more than LATE_THRESHOLD_MINUTES after that is marked "Late".
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../qr/qr_helper.php';
require_role('teacher');
$pageTitle = 'Attendance Session';

auto_expire_sessions($pdo);

$teacherId = $_SESSION['profile_id'];

$classes = $pdo->prepare('
    SELECT ts.teacher_subject_id, sub.subject_code, sub.subject_name, ts.section, ts.start_time, ts.end_time, ts.schedule_day, lab.lab_name
    FROM teacher_subjects ts
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    WHERE ts.teacher_id = ? AND ts.status = "active"
    ORDER BY sub.subject_code
');
$classes->execute([$teacherId]);
$classes = $classes->fetchAll();

$selectedClass = (int) ($_GET['class'] ?? ($classes[0]['teacher_subject_id'] ?? 0));
$belongsToTeacher = false;
$classInfo = null;
foreach ($classes as $c) {
    if ((int) $c['teacher_subject_id'] === $selectedClass) { $belongsToTeacher = true; $classInfo = $c; }
}
if (!$belongsToTeacher) { $selectedClass = 0; $classInfo = null; }

// Check for an existing active session today for this class
$activeSession = null;
if ($selectedClass) {
    $stmt = $pdo->prepare('SELECT * FROM attendance_sessions WHERE teacher_subject_id = ? AND session_date = CURDATE() AND is_active = 1 LIMIT 1');
    $stmt->execute([$selectedClass]);
    $activeSession = $stmt->fetch();
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h3>Select Class</h3></div>
    <div class="card-body">
        <?php if (empty($classes)): ?>
            <div class="empty-state"><i class="fa-solid fa-book"></i><p>You have no active class assignments. Contact the administrator.</p></div>
        <?php else: ?>
        <form method="GET" class="toolbar">
            <select name="class" class="form-control" style="max-width:380px" onchange="this.form.submit()">
                <?php foreach ($classes as $c): ?>
                    <option value="<?php echo $c['teacher_subject_id']; ?>" <?php echo $selectedClass == $c['teacher_subject_id'] ? 'selected' : ''; ?>>
                        <?php echo e($c['subject_code'] . ' - ' . $c['subject_name'] . ' (' . $c['section'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($classInfo): ?>
<div class="card" style="margin-top:20px">
    <div class="card-body">
        <div class="toggle-row">
            <div>
                <div style="font-size:12.5px;color:var(--slate-500);font-weight:600;margin-bottom:3px">Assigned Lab</div>
                <div style="font-size:16px;font-weight:700;color:var(--slate-900)"><?php echo e($classInfo['lab_name']); ?> — <?php echo e($classInfo['subject_code']); ?></div>
                <div style="font-size:12.5px;color:var(--slate-500);margin-top:2px"><?php echo e($classInfo['schedule_day']); ?> · <?php echo format_time($classInfo['start_time']); ?>–<?php echo format_time($classInfo['end_time']); ?></div>
            </div>
            <div style="display:flex;align-items:center;gap:10px">
                <span id="toggleLabel" style="font-size:13px;font-weight:600;color:<?php echo $activeSession ? 'var(--green-600)' : 'var(--slate-500)'; ?>"><?php echo $activeSession ? 'Session Active' : 'Activate QR Code'; ?></span>
                <label class="toggle-switch">
                    <input type="checkbox" id="sessionToggle" <?php echo $activeSession ? 'checked' : ''; ?> onchange="onToggleChange(this)">
                    <span class="toggle-slider"></span>
                </label>
            </div>
        </div>
    </div>
</div>

<div class="grid-2" style="margin-top:20px">
    <div class="card">
        <div class="card-header"><h3>QR Code</h3></div>
        <div class="card-body qr-display" id="sessionPanel">
            <?php if ($activeSession): ?>
                <div id="qrcodeCanvas"></div>
                <div class="qr-session-meta">
                    <div><?php echo e($classInfo['subject_name']); ?></div>
                    <div>Late after: <?php echo date('h:i A', strtotime($activeSession['scheduled_start']) + LATE_THRESHOLD_MINUTES * 60); ?> · Expires: <?php echo date('h:i A', strtotime($activeSession['session_end'])); ?></div>
                    <div>Geofence radius: <?php echo (int) $activeSession['allowed_radius_meters']; ?>m
                        <?php if ($activeSession['created_by_role'] === 'admin'): ?><span class="badge badge-inactive" style="margin-left:6px">Activated by Admin</span><?php endif; ?>
                    </div>
                    <div class="text-muted" style="font-size:11.5px;margin-top:4px">QR refreshes automatically every few seconds for security.</div>
                </div>
            <?php else: ?>
                <div class="empty-state"><i class="fa-solid fa-qrcode"></i><p>Flip the switch above to start this session's QR code.</p></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3>Session Summary</h3>
        </div>
        <div class="card-body">
            <div style="display:flex;gap:16px">
                <div style="flex:1;text-align:center;padding:16px;background:var(--slate-50);border-radius:var(--radius-md)">
                    <div style="font-size:24px;font-weight:800;color:var(--indigo-600)" id="scannedCountVal">0</div>
                    <div style="font-size:12px;color:var(--slate-500);margin-top:2px">Scanned</div>
                </div>
                <div style="flex:1;text-align:center;padding:16px;background:var(--slate-50);border-radius:var(--radius-md)">
                    <div style="font-size:24px;font-weight:800;color:var(--slate-700)" id="totalCountVal">0</div>
                    <div style="font-size:12px;color:var(--slate-500);margin-top:2px">Enrolled</div>
                </div>
            </div>
            <p class="text-muted" style="font-size:13px;margin-top:16px">Students appear below the moment they scan. This panel refreshes automatically every few seconds.</p>
        </div>
    </div>
</div>

<div class="card" style="margin-top:20px">
    <div class="card-header">
        <h3><?php echo e($classInfo['subject_code'] . ' - ' . $classInfo['subject_name']); ?></h3>
    </div>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr><th>Student Name</th><th>ID</th><th>Check-in Time</th><th>Status</th></tr></thead>
            <tbody id="liveScanBody">
                <tr><td colspan="4" class="text-center text-muted">Activate the session to load the class roster.</td></tr>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
const CLASS_ID = <?php echo (int) $selectedClass; ?>;
let ACTIVE_SESSION_ID = <?php echo $activeSession ? (int) $activeSession['session_id'] : 'null'; ?>;
let pollTimer = null;
let qrTimer = null;
let qrRotationFailures = 0;

function renderQr(payload) {
    safeRenderQr(document.getElementById('qrcodeCanvas'), payload, 200);
}

// Wait for the full page (including app.js, loaded near the bottom) before
// doing anything that depends on it — safeRenderQr/ajaxGet/showToast all live there.
document.addEventListener('DOMContentLoaded', () => {
    <?php if ($activeSession): ?>
    // Render immediately from the page load data — don't wait on any AJAX call
    // to show the very first QR code. Polling below only upgrades it afterward.
    renderQr(<?php echo json_encode(qr_build_payload($activeSession['session_id'], $activeSession['qr_token'])); ?>);
    startPolling();
    startQrRotation();
    <?php endif; ?>
});

// Poll for the current (possibly just-rotated) signed token and re-render the QR.
// A screenshot of an earlier QR stops working once the token rotates.
function startQrRotation() {
    qrTimer = setInterval(fetchCurrentToken, 5000);
}
async function fetchCurrentToken() {
    if (!ACTIVE_SESSION_ID) return;
    try {
        const res = await ajaxGet('../qr/ajax_current_token.php?session_id=' + ACTIVE_SESSION_ID);
        if (res.success && res.active) {
            renderQr(res.payload);
            qrRotationFailures = 0;
        } else if (res.active === false) {
            clearInterval(qrTimer); clearInterval(pollTimer);
        } else {
            qrRotationFailures++;
            if (qrRotationFailures === 1) showToast('info', 'QR code is displayed but auto-refresh is unavailable: ' + (res.message || 'unknown error') + '. The code shown will keep working until you deactivate.');
        }
    } catch (err) {
        qrRotationFailures++;
        if (qrRotationFailures === 1) showToast('info', 'QR code is displayed but auto-refresh is unavailable right now. The code shown will keep working until you deactivate.');
    }
}

// Toggle switch drives activate/deactivate — flips back if the request fails
async function onToggleChange(checkbox) {
    checkbox.disabled = true;
    if (checkbox.checked) {
        const res = await ajaxPost('ajax_session.php', { action: 'activate', teacher_subject_id: CLASS_ID });
        if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 400); }
        else { showToast('error', res.message); checkbox.checked = false; checkbox.disabled = false; }
    } else {
        if (!confirm('Deactivate this attendance session? Students will no longer be able to scan.')) {
            checkbox.checked = true; checkbox.disabled = false; return;
        }
        const res = await ajaxPost('ajax_session.php', { action: 'deactivate', teacher_subject_id: CLASS_ID });
        if (res.success) { showToast('success', res.message); clearInterval(pollTimer); clearInterval(qrTimer); setTimeout(() => location.reload(), 400); }
        else { showToast('error', res.message); checkbox.checked = true; checkbox.disabled = false; }
    }
}

function startPolling() {
    fetchLiveScans();
    pollTimer = setInterval(fetchLiveScans, 4000);
}

function initials(name) {
    return name.split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase();
}

async function fetchLiveScans() {
    if (!ACTIVE_SESSION_ID) return;
    try {
        const res = await ajaxGet('ajax_session_status.php?session_id=' + ACTIVE_SESSION_ID);
        if (!res.success) return;
        document.getElementById('scannedCountVal').textContent = res.scanned_count;
        document.getElementById('totalCountVal').textContent = res.total_count;
        const body = document.getElementById('liveScanBody');
        if (res.records.length === 0) {
            body.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No students enrolled in this class yet.</td></tr>';
            return;
        }
        body.innerHTML = res.records.map(r => `
            <tr>
                <td><div class="roster-name-cell"><span class="avatar-sm">${initials(r.full_name)}</span> ${r.full_name}</div></td>
                <td>${r.student_number}</td>
                <td>${r.time_in}</td>
                <td><span class="badge badge-${r.status.toLowerCase()}">${r.status}</span></td>
            </tr>
        `).join('');
    } catch (err) { /* silent fail, will retry next poll */ }
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
