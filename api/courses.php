<?php
/**
 * GET /api/courses.php?university_id=&faculty_id=&department_id=&level_id=&semester_id=&q=
 * Public catalogue/filter endpoint — read-only, no auth required to
 * browse (matches the guide's endpoint table), though the student
 * portal pages themselves are behind login per section 8.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_error('Method not allowed.', 405);
}

$db = get_db();

$sql = "SELECT c.id, c.code, c.title, c.department_id, c.level_id, c.semester_id, c.university_id,
               (SELECT COUNT(*) FROM documents d WHERE d.course_id = c.id AND d.status = 'ACTIVE') AS document_count
        FROM courses c
        JOIN departments d ON d.id = c.department_id
        WHERE c.status = 'active'";
$params = [];

if (!empty($_GET['university_id'])) { $sql .= ' AND c.university_id = :university_id'; $params['university_id'] = (int) $_GET['university_id']; }
if (!empty($_GET['faculty_id'])) { $sql .= ' AND d.faculty_id = :faculty_id'; $params['faculty_id'] = (int) $_GET['faculty_id']; }
if (!empty($_GET['department_id'])) { $sql .= ' AND c.department_id = :department_id'; $params['department_id'] = (int) $_GET['department_id']; }
if (!empty($_GET['level_id'])) { $sql .= ' AND c.level_id = :level_id'; $params['level_id'] = (int) $_GET['level_id']; }
if (!empty($_GET['semester_id'])) { $sql .= ' AND c.semester_id = :semester_id'; $params['semester_id'] = (int) $_GET['semester_id']; }
if (!empty($_GET['q'])) { $sql .= ' AND (c.title LIKE :q OR c.code LIKE :q)'; $params['q'] = '%' . $_GET['q'] . '%'; }

$sql .= ' ORDER BY c.code LIMIT 100';

$stmt = $db->prepare($sql);
$stmt->execute($params);

json_ok(['courses' => $stmt->fetchAll()]);
