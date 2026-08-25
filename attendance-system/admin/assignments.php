<?php
/**
 * admin/assignments.php
 * Assigns a teacher to teach a subject, in a laboratory, on a schedule.
 * This "class" (teacher_subjects row) is what students get enrolled into.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
$pageTitle = 'Class Assignments';

$search = clean($_GET['search'] ?? '');
$where = ''; $params = [];
if ($search !== '') {
    $where = 'WHERE (t.full_name LIKE ? OR sub.subject_name LIKE ? OR ts.section LIKE ?)';
    $params = ["%$search%", "%$search%", "%$search%"];
}

$countStmt = $pdo->prepare("
    SELECT COUNT(*) FROM teacher_subjects ts
    JOIN teachers t ON t.teacher_id = ts.teacher_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    $where
");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$p = paginate($totalRows, 10);

$stmt = $pdo->prepare("
    SELECT ts.*, t.full_name AS teacher_name, sub.subject_name, sub.subject_code, lab.lab_name, pr.program_code,
        (SELECT COUNT(*) FROM enrollments e WHERE e.teacher_subject_id = ts.teacher_subject_id AND e.status='enrolled') AS enrolled_count
    FROM teacher_subjects ts
    JOIN teachers t ON t.teacher_id = ts.teacher_id
    JOIN subjects sub ON sub.subject_id = ts.subject_id
    JOIN laboratories lab ON lab.lab_id = ts.lab_id
    LEFT JOIN programs pr ON pr.program_id = ts.program_id
    $where
    ORDER BY ts.created_at DESC
    LIMIT {$p['limit']} OFFSET {$p['offset']}
");
$stmt->execute($params);
$assignments = $stmt->fetchAll();

$teachers = $pdo->query('SELECT teacher_id, full_name FROM teachers ORDER BY full_name')->fetchAll();
$subjects = $pdo->query('SELECT subject_id, subject_code, subject_name FROM subjects WHERE status="active" ORDER BY subject_code')->fetchAll();
$labs = $pdo->query('SELECT lab_id, lab_name FROM laboratories WHERE status="active" ORDER BY lab_name')->fetchAll();
$programs = $pdo->query('SELECT program_id, program_code, program_name FROM programs ORDER BY program_code')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="card">
    <div class="card-header">
        <h3>Class Assignments (<?php echo $totalRows; ?>)</h3>
        <button class="btn btn-primary btn-sm" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> New Assignment</button>
    </div>
    <div class="card-body">
        <form method="GET" class="toolbar">
            <div class="search-box"><i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" class="form-control" name="search" placeholder="Search teacher, subject, section..." value="<?php echo e($search); ?>"></div>
            <button class="btn btn-outline btn-sm" type="submit">Search</button>
            <?php if ($search): ?><a href="assignments.php" class="btn btn-outline btn-sm">Reset</a><?php endif; ?>
        </form>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Teacher</th><th>Subject</th><th>Course/Year</th><th>Section</th><th>Lab</th><th>Schedule</th><th>Enrolled</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php if (empty($assignments)): ?>
                    <tr><td colspan="9" class="text-center text-muted">No class assignments yet.</td></tr>
                <?php else: foreach ($assignments as $a): ?>
                    <tr>
                        <td><?php echo e($a['teacher_name']); ?></td>
                        <td><?php echo e($a['subject_code'] . ' - ' . $a['subject_name']); ?></td>
                        <td><?php echo e($a['program_code'] ?? '—'); ?><?php echo $a['year_level'] ? ' / Yr ' . e($a['year_level']) : ''; ?></td>
                        <td><?php echo e($a['section']); ?></td>
                        <td><?php echo e($a['lab_name']); ?></td>
                        <td><?php echo e($a['schedule_day']); ?> · <?php echo format_time($a['start_time']); ?>–<?php echo format_time($a['end_time']); ?></td>
                        <td><?php echo (int) $a['enrolled_count']; ?>/<?php echo (int) $a['max_students']; ?></td>
                        <td><span class="badge badge-<?php echo $a['status'] === 'active' ? 'active' : 'inactive'; ?>"><?php echo ucfirst($a['status']); ?></span></td>
                        <td>
                            <button class="btn btn-outline btn-sm" onclick='openEditModal(<?php echo json_encode($a); ?>)'><i class="fa-solid fa-pen"></i></button>
                            <button class="btn btn-danger btn-sm" onclick="deleteAssignment(<?php echo $a['teacher_subject_id']; ?>)"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination($p['page'], $p['totalPages']); ?>
    </div>
</div>

<div class="modal-backdrop" id="assignModal">
    <div class="modal">
        <div class="modal-header"><h3 id="assignModalTitle">New Assignment</h3><button class="modal-close" onclick="closeModal('assignModal')">&times;</button></div>
        <form id="assignForm">
            <div class="modal-body">
                <input type="hidden" name="teacher_subject_id" id="teacher_subject_id">
                <div class="form-row">
                    <div class="form-group"><label>Teacher *</label>
                        <select name="teacher_id" id="teacher_id" class="form-control" required>
                            <option value="">Select teacher</option>
                            <?php foreach ($teachers as $t): ?><option value="<?php echo $t['teacher_id']; ?>"><?php echo e($t['full_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Subject *</label>
                        <select name="subject_id" id="subject_id" class="form-control" required>
                            <option value="">Select subject</option>
                            <?php foreach ($subjects as $s): ?><option value="<?php echo $s['subject_id']; ?>"><?php echo e($s['subject_code'] . ' - ' . $s['subject_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Laboratory *</label>
                        <select name="lab_id" id="lab_id" class="form-control" required>
                            <option value="">Select laboratory</option>
                            <?php foreach ($labs as $l): ?><option value="<?php echo $l['lab_id']; ?>"><?php echo e($l['lab_name']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Section *</label><input type="text" name="section" id="section" class="form-control" placeholder="e.g. BSCS-3A" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Course</label>
                        <select name="program_id" id="program_id" class="form-control">
                            <option value="">— Any / Not restricted —</option>
                            <?php foreach ($programs as $pr): ?><option value="<?php echo $pr['program_id']; ?>"><?php echo e($pr['program_code']); ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group"><label>Year Level</label>
                        <select name="year_level" id="year_level" class="form-control">
                            <option value="">— Any —</option>
                            <option value="1">1st Year</option><option value="2">2nd Year</option>
                            <option value="3">3rd Year</option><option value="4">4th Year</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Schedule Day *</label><input type="text" name="schedule_day" id="schedule_day" class="form-control" placeholder="e.g. Monday" required></div>
                    <div class="form-group"><label>Max Students</label><input type="number" name="max_students" id="max_students" class="form-control" value="40" min="1" max="200"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Start Time *</label><input type="time" name="start_time" id="start_time" class="form-control" required></div>
                    <div class="form-group"><label>End Time *</label><input type="time" name="end_time" id="end_time" class="form-control" required></div>
                </div>
                <div class="form-group"><label>Status</label>
                    <select name="status" id="status" class="form-control"><option value="active">Active</option><option value="inactive">Inactive</option></select>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Start Time *</label><input type="time" name="start_time" id="start_time" class="form-control" required></div>
                    <div class="form-group"><label>End Time *</label><input type="time" name="end_time" id="end_time" class="form-control" required></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('assignModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="assignSubmitBtn">Save Assignment</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('assignForm').reset();
    document.getElementById('teacher_subject_id').value = '';
    document.getElementById('assignModalTitle').textContent = 'New Assignment';
    openModal('assignModal');
}
function openEditModal(a) {
    document.getElementById('teacher_subject_id').value = a.teacher_subject_id;
    document.getElementById('teacher_id').value = a.teacher_id;
    document.getElementById('subject_id').value = a.subject_id;
    document.getElementById('lab_id').value = a.lab_id;
    document.getElementById('section').value = a.section;
    document.getElementById('program_id').value = a.program_id || '';
    document.getElementById('year_level').value = a.year_level || '';
    document.getElementById('max_students').value = a.max_students || 40;
    document.getElementById('schedule_day').value = a.schedule_day;
    document.getElementById('start_time').value = a.start_time.substring(0,5);
    document.getElementById('end_time').value = a.end_time.substring(0,5);
    document.getElementById('status').value = a.status;
    document.getElementById('assignModalTitle').textContent = 'Edit Assignment';
    openModal('assignModal');
}
document.getElementById('assignForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('assignSubmitBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving...';
    const data = Object.fromEntries(new FormData(e.target));
    data.action = data.teacher_subject_id ? 'update' : 'create';
    const res = await ajaxPost('ajax_assignments.php', data);
    if (res.success) { showToast('success', res.message); closeModal('assignModal'); setTimeout(() => location.reload(), 700); }
    else showToast('error', res.message);
    btn.disabled = false; btn.innerHTML = 'Save Assignment';
});
async function deleteAssignment(id) {
    if (!confirmDelete('Delete this class assignment? Enrollments and attendance history for it will also be removed.')) return;
    const res = await ajaxPost('ajax_assignments.php', { action: 'delete', teacher_subject_id: id });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 700); } else showToast('error', res.message);
}
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
