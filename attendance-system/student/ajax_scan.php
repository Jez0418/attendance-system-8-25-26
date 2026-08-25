<?php
/**
 * ============================================================
 * student/ajax_scan.php
 * CORE ATTENDANCE VALIDATION ENGINE.
 *
 * The browser only ever supplies: the raw QR text, and raw
 * latitude/longitude/accuracy from the Geolocation API. Every
 * decision is made HERE, server-side, in this exact order:
 *
 *   1.  Student logged in?               (require_role)
 *   2.  QR token valid (signature)?       qr_parse_payload()
 *   3.  QR session active?
 *   4.  QR session expired?
 *   5.  Subject valid?                    (implicit via joins)
 *   6.  Student enrolled in the subject?
 *   7.  Within the allowed schedule?      (active + not expired)
 *   8.  Location permission available?
 *   9.  GPS accuracy acceptable?
 *   10. Within the allowed geofence radius (Haversine)?
 *   11. Already attended this session?
 *   12. Record attendance.
 *
 * Wrapped in a DB transaction so a failure partway through never
 * leaves a partial/inconsistent record behind.
 * ============================================================
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/geo.php';
require_once __DIR__ . '/../qr/qr_helper.php';
require_role('student');
header('Content-Type: application/json');

auto_expire_sessions($pdo);

$studentId = $_SESSION['profile_id'];

try {
    // ---- Step 2: QR token valid (well-formed + correctly signed)? ----
    $rawPayload = $_POST['qr_payload'] ?? '';
    if ($rawPayload === '') throw new Exception('No QR data received.');

    $parsed = qr_parse_payload($rawPayload);
    if (!$parsed) throw new Exception('This QR code is invalid or has expired.');

    // Load the session + everything needed to validate & display results
    $stmt = $pdo->prepare('
        SELECT s.*, ts.teacher_subject_id, ts.max_students,
            sub.subject_name, sub.subject_code,
            t.full_name AS teacher_name,
            lab.lab_name, lab.latitude AS lab_lat, lab.longitude AS lab_lon
        FROM attendance_sessions s
        JOIN teacher_subjects ts ON ts.teacher_subject_id = s.teacher_subject_id
        JOIN subjects sub ON sub.subject_id = ts.subject_id
        JOIN teachers t ON t.teacher_id = ts.teacher_id
        JOIN laboratories lab ON lab.lab_id = ts.lab_id
        WHERE s.session_id = ?
    ');
    $stmt->execute([$parsed['session_id']]);
    $session = $stmt->fetch();

    if (!$session) throw new Exception('This QR code is invalid or has expired.');
    if (!hash_equals($session['qr_token'], $parsed['token'])) {
        throw new Exception('This QR code is invalid or has expired.');
    }

    // ---- Step 3: QR session active? ----
    if ((int) $session['is_active'] !== 1) {
        throw new Exception('This attendance QR code is currently inactive.');
    }

    // ---- Step 4: Session expired? (belt-and-suspenders on top of auto_expire_sessions) ----
    if ($session['session_end'] && strtotime($session['session_end']) < time()) {
        $pdo->prepare('UPDATE attendance_sessions SET is_active = 0, deactivated_at = NOW() WHERE session_id = ?')->execute([$session['session_id']]);
        throw new Exception('This attendance session has expired.');
    }

    // ---- Step 5: Subject valid? (guaranteed by the JOINs above returning a row) ----

    // ---- Step 6: Student enrolled in the subject? ----
    $enrollCheck = $pdo->prepare('SELECT COUNT(*) FROM enrollments WHERE student_id = ? AND teacher_subject_id = ? AND status = "enrolled"');
    $enrollCheck->execute([$studentId, $session['teacher_subject_id']]);
    if ($enrollCheck->fetchColumn() == 0) {
        throw new Exception('You are not enrolled in this subject.');
    }

    // ---- Step 7: Within allowed schedule? (session is active & not expired = within schedule) ----
    // (No separate check needed — steps 3–4 already establish this.)

    // ---- Step 8: Location permission available? ----
    $lat = $_POST['latitude'] ?? null;
    $lon = $_POST['longitude'] ?? null;
    $accuracy = $_POST['accuracy'] ?? null;
    if ($lat === null || $lon === null || $lat === '' || $lon === '') {
        throw new Exception('Unable to determine your location. Please enable location services.');
    }
    if (!is_numeric($lat) || !is_numeric($lon)) {
        throw new Exception('Unable to determine your location. Please enable location services.');
    }

    // ---- Step 9: GPS accuracy acceptable? ----
    $maxAccuracy = get_setting_int($pdo, 'max_gps_accuracy_meters', 100);
    if ($accuracy !== null && $accuracy !== '' && is_numeric($accuracy) && (float) $accuracy > $maxAccuracy) {
        throw new Exception('Your current location accuracy is too low. Please move to an area with better GPS signal.');
    }

    // ---- Step 10: Within the allowed geofence radius? (server-side Haversine — never trust the client) ----
    if ($session['lab_lat'] === null || $session['lab_lon'] === null) {
        throw new Exception('This laboratory has no GPS coordinates configured. Please contact your administrator.');
    }
    [$withinRadius, $distance] = is_within_geofence((float) $lat, (float) $lon, (float) $session['lab_lat'], (float) $session['lab_lon'], (int) $session['allowed_radius_meters']);
    if (!$withinRadius) {
        throw new Exception('You are outside the allowed attendance area. Please move closer to the assigned laboratory.');
    }

    // ---- Step 11: Already attended this session? ----
    $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM attendance_records WHERE session_id = ? AND student_id = ?');
    $dupCheck->execute([$session['session_id'], $studentId]);
    if ($dupCheck->fetchColumn() > 0) {
        throw new Exception('You have already recorded attendance for this session.');
    }

    // ---- Step 12: Record attendance (inside a transaction) ----
    $pdo->beginTransaction();

    $now = new DateTime();
    $scheduledStart = new DateTime($session['scheduled_start']);
    $lateThreshold = (clone $scheduledStart)->modify('+' . (int) $session['late_threshold_minutes'] . ' minutes');
    $status = ($now > $lateThreshold) ? 'Late' : 'Present';

    $ins = $pdo->prepare('
        INSERT INTO attendance_records (session_id, student_id, time_in, status, latitude, longitude, location_accuracy, distance_from_location)
        VALUES (?, ?, NOW(), ?, ?, ?, ?, ?)
    ');
    $ins->execute([
        $session['session_id'], $studentId, $status,
        (float) $lat, (float) $lon,
        ($accuracy !== null && $accuracy !== '' && is_numeric($accuracy)) ? (float) $accuracy : null,
        $distance,
    ]);

    $studentName = $_SESSION['full_name'];
    create_notification($pdo, $_SESSION['user_id'], 'Attendance Recorded', "You were marked $status for {$session['subject_name']} in {$session['lab_name']}.");
    log_activity($pdo, $_SESSION['user_id'], "Scanned attendance for session #{$session['session_id']} - $status ({$distance}m)");

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Marked as $status for {$session['subject_name']}.",
        'status' => $status,
        'student_name' => $studentName,
        'subject_name' => $session['subject_name'],
        'teacher_name' => $session['teacher_name'],
        'lab_name' => $session['lab_name'],
        'date' => date('F j, Y'),
        'time_in' => date('h:i A'),
        'distance' => $distance,
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    // Unique constraint (uniq_scan) race-condition safety net
    if ($e->getCode() === '23000') {
        echo json_encode(['success' => false, 'message' => 'You have already recorded attendance for this session.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
}
