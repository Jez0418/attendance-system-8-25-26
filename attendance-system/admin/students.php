<?php
/**
 * admin/students.php
 * Student CRUD. List with search/filter/pagination; Add/Edit via
 * modal + AJAX to ajax_students.php; Delete via AJAX confirm.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
$pageTitle = 'Student Management';

// ---- Filters ----
$search = clean($_GET['search'] ?? '');
$programFilter = clean($_GET['program'] ?? '');

$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(s.full_name LIKE ? OR s.student_number LIKE ? OR u.email LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($programFilter !== '') {
    $where[] = 's.program_id = ?';
    $params[] = $programFilter;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM students s JOIN users u ON u.user_id = s.user_id $whereSql");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$p = paginate($totalRows, 10);

$stmt = $pdo->prepare("
    SELECT s.*, u.username, u.email, u.status, pr.program_name
    FROM students s
    JOIN users u ON u.user_id = s.user_id
    LEFT JOIN programs pr ON pr.program_id = s.program_id
    $whereSql
    ORDER BY s.full_name ASC
    LIMIT {$p['limit']} OFFSET {$p['offset']}
");
$stmt->execute($params);
$students = $stmt->fetchAll();

$programs = $pdo->query('SELECT * FROM programs ORDER BY program_name')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h3>Students (<?php echo $totalRows; ?>)</h3>
        <button class="btn btn-primary btn-sm" onclick="openAddModal()"><i class="fa-solid fa-plus"></i> Add Student</button>
    </div>
    <div class="card-body">
        <form method="GET" class="toolbar">
            <div class="search-box">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" class="form-control" name="search" placeholder="Search name, ID, email..." value="<?php echo e($search); ?>">
            </div>
            <select name="program" class="form-control" style="max-width:200px" onchange="this.form.submit()">
                <option value="">All Programs</option>
                <?php foreach ($programs as $pr): ?>
                    <option value="<?php echo $pr['program_id']; ?>" <?php echo $programFilter == $pr['program_id'] ? 'selected' : ''; ?>><?php echo e($pr['program_code']); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-outline btn-sm" type="submit"><i class="fa-solid fa-filter"></i> Filter</button>
            <?php if ($search || $programFilter): ?><a href="students.php" class="btn btn-outline btn-sm">Reset</a><?php endif; ?>
        </form>

        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr><th>Student No.</th><th>Name</th><th>Program</th><th>Year</th><th>Email</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php if (empty($students)): ?>
                    <tr><td colspan="7" class="text-center text-muted">No students found.</td></tr>
                <?php else: foreach ($students as $s): ?>
                    <tr>
                        <td><?php echo e($s['student_number']); ?></td>
                        <td><?php echo e($s['full_name']); ?></td>
                        <td><?php echo e($s['program_name'] ?? '—'); ?></td>
                        <td><?php echo e($s['year_level']); ?></td>
                        <td><?php echo e($s['email']); ?></td>
                        <td><span class="badge badge-<?php echo $s['status'] === 'active' ? 'active' : 'inactive'; ?>"><?php echo ucfirst($s['status']); ?></span></td>
                        <td>
                            <button class="btn btn-outline btn-sm" onclick='openEditModal(<?php echo json_encode($s); ?>)'><i class="fa-solid fa-pen"></i></button>
                            <button class="btn btn-danger btn-sm" onclick="deleteStudent(<?php echo $s['student_id']; ?>)"><i class="fa-solid fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?php render_pagination($p['page'], $p['totalPages']); ?>
    </div>
</div>

<!-- ===================== ADD/EDIT MODAL ===================== -->
<div class="modal-backdrop" id="studentModal">
    <div class="modal">
        <div class="modal-header">
            <h3 id="studentModalTitle">Add Student</h3>
            <button class="modal-close" onclick="closeModal('studentModal')">&times;</button>
        </div>
        <form id="studentForm">
            <div class="modal-body">
                <input type="hidden" name="student_id" id="student_id">
                <div class="form-row">
                    <div class="form-group">
                        <label>Student Number *</label>
                        <input type="text" name="student_number" id="student_number" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="full_name" id="full_name" class="form-control" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Program</label>
                        <select name="program_id" id="program_id" class="form-control">
                            <option value="">— None —</option>
                            <?php foreach ($programs as $pr): ?>
                                <option value="<?php echo $pr['program_id']; ?>"><?php echo e($pr['program_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Year Level</label>
                        <select name="year_level" id="year_level" class="form-control">
                            <option value="1">1st Year</option><option value="2">2nd Year</option>
                            <option value="3">3rd Year</option><option value="4">4th Year</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Email *</label>
                        <input type="email" name="email" id="email" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Contact Number</label>
                        <input type="text" name="contact_number" id="contact_number" class="form-control">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Username *</label>
                        <input type="text" name="username" id="username" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label id="passwordLabel">Password *</label>
                        <input type="text" name="password" id="password" class="form-control" placeholder="Leave blank to keep unchanged">
                        <small class="text-muted">Tip: use the student number as the password (e.g. <?php echo e($students[0]['student_number'] ?? '2023-0001'); ?>) so students can log in with their ID.</small>
                    </div>
                </div>
                <div class="form-group">
                    <label>Account Status</label>
                    <select name="status" id="status" class="form-control">
                        <option value="active">Active</option><option value="inactive">Inactive</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeModal('studentModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="studentSubmitBtn">Save Student</button>
            </div>
        </form>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('studentForm').reset();
    document.getElementById('student_id').value = '';
    document.getElementById('studentModalTitle').textContent = 'Add Student';
    document.getElementById('passwordLabel').textContent = 'Password *';
    document.getElementById('password').required = true;
    openModal('studentModal');
}
// Convenience: auto-fill password with the student number as it's typed (new students only)
document.getElementById('student_number').addEventListener('input', (e) => {
    if (!document.getElementById('student_id').value) {
        document.getElementById('password').value = e.target.value;
    }
});
function openEditModal(s) {
    document.getElementById('studentForm').reset();
    document.getElementById('student_id').value = s.student_id;
    document.getElementById('student_number').value = s.student_number;
    document.getElementById('full_name').value = s.full_name;
    document.getElementById('program_id').value = s.program_id || '';
    document.getElementById('year_level').value = s.year_level;
    document.getElementById('email').value = s.email;
    document.getElementById('contact_number').value = s.contact_number || '';
    document.getElementById('username').value = s.username;
    document.getElementById('status').value = s.status;
    document.getElementById('studentModalTitle').textContent = 'Edit Student';
    document.getElementById('passwordLabel').textContent = 'Password';
    document.getElementById('password').required = false;
    openModal('studentModal');
}

document.getElementById('studentForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('studentSubmitBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving...';
    const data = Object.fromEntries(new FormData(e.target));
    data.action = data.student_id ? 'update' : 'create';
    try {
        const res = await ajaxPost('ajax_students.php', data);
        if (res.success) {
            showToast('success', res.message);
            closeModal('studentModal');
            setTimeout(() => location.reload(), 700);
        } else {
            showToast('error', res.message);
        }
    } catch (err) {
        showToast('error', 'Something went wrong. Please try again.');
    }
    btn.disabled = false; btn.innerHTML = 'Save Student';
});

async function deleteStudent(id) {
    if (!confirmDelete('Delete this student? Their attendance history will also be removed.')) return;
    const res = await ajaxPost('ajax_students.php', { action: 'delete', student_id: id });
    if (res.success) { showToast('success', res.message); setTimeout(() => location.reload(), 700); }
    else showToast('error', res.message);
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
