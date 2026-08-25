<?php
/**
 * admin/ajax_assignments.php — create / update / delete teacher_subjects (classes).
 * Now also carries program/year restrictions and a max_students cap,
 * which the student "Available Subjects" / enrollment-request flow
 * relies on for filtering and slot counts.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
header('Content-Type: application/json');

$action = $_POST['action'] ?? '';

function validate_assignment_input($pdo) {
    $teacherId = (int) ($_POST['teacher_id'] ?? 0);
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $labId     = (int) ($_POST['lab_id'] ?? 0);
    $section   = clean($_POST['section'] ?? '');
    $day       = clean($_POST['schedule_day'] ?? '');
    $start     = clean($_POST['start_time'] ?? '');
    $end       = clean($_POST['end_time'] ?? '');
    $status    = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
    $programId = $_POST['program_id'] !== '' ? (int) $_POST['program_id'] : null;
    $yearLevel = $_POST['year_level'] !== '' ? (int) $_POST['year_level'] : null;
    $maxStudents = (int) ($_POST['max_students'] ?? 40);

    if (!$teacherId || !$subjectId || !$labId || $section === '' || $day === '' || $start === '' || $end === '') {
        throw new Exception('Please fill in all required fields.');
    }
    if (strtotime($end) <= strtotime($start)) {
        throw new Exception('End time must be after start time.');
    }
    if ($maxStudents < 1 || $maxStudents > 200) {
        throw new Exception('Max students must be between 1 and 200.');
    }
    return [$teacherId, $subjectId, $labId, $section, $day, $start, $end, $status, $programId, $yearLevel, $maxStudents];
}

try {
    if ($action === 'create') {
        [$teacherId, $subjectId, $labId, $section, $day, $start, $end, $status, $programId, $yearLevel, $maxStudents] = validate_assignment_input($pdo);
        $stmt = $pdo->prepare('INSERT INTO teacher_subjects (teacher_id, subject_id, lab_id, section, schedule_day, start_time, end_time, status, program_id, year_level, max_students) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$teacherId, $subjectId, $labId, $section, $day, $start, $end, $status, $programId, $yearLevel, $maxStudents]);
        log_activity($pdo, $_SESSION['user_id'], "Created class assignment (section $section)");
        echo json_encode(['success' => true, 'message' => 'Class assignment created successfully.']);

    } elseif ($action === 'update') {
        $id = (int) ($_POST['teacher_subject_id'] ?? 0);
        if (!$id) throw new Exception('Invalid assignment.');
        [$teacherId, $subjectId, $labId, $section, $day, $start, $end, $status, $programId, $yearLevel, $maxStudents] = validate_assignment_input($pdo);

        // Don't let max_students drop below the number of students already enrolled
        $enrolledCountStmt = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE teacher_subject_id = ? AND status = "enrolled"');
        $enrolledCountStmt->execute([$id]);
        $enrolledCount = (int) $enrolledCountStmt->fetchColumn();
        if ($maxStudents < $enrolledCount) {
            throw new Exception("Max students cannot be lower than the number of students already enrolled ($enrolledCount).");
        }

        $stmt = $pdo->prepare('UPDATE teacher_subjects SET teacher_id=?, subject_id=?, lab_id=?, section=?, schedule_day=?, start_time=?, end_time=?, status=?, program_id=?, year_level=?, max_students=? WHERE teacher_subject_id=?');
        $stmt->execute([$teacherId, $subjectId, $labId, $section, $day, $start, $end, $status, $programId, $yearLevel, $maxStudents, $id]);
        log_activity($pdo, $_SESSION['user_id'], "Updated class assignment ID $id");
        echo json_encode(['success' => true, 'message' => 'Class assignment updated successfully.']);

    } elseif ($action === 'delete') {
        $id = (int) ($_POST['teacher_subject_id'] ?? 0);
        if (!$id) throw new Exception('Invalid assignment.');
        $stmt = $pdo->prepare('DELETE FROM teacher_subjects WHERE teacher_subject_id = ?');
        $stmt->execute([$id]);
        log_activity($pdo, $_SESSION['user_id'], "Deleted class assignment ID $id");
        echo json_encode(['success' => true, 'message' => 'Class assignment deleted successfully.']);
    } else {
        throw new Exception('Unknown action.');
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
