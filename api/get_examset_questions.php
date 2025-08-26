<?php
require_once '../config/db.php';
require_once '../vendor/autoload.php';
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

header('Content-Type: application/json');

$headers = apache_request_headers();
if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'No token provided']);
    exit;
}

try {
    $token = str_replace('Bearer ', '', $headers['Authorization']);
    $decoded = JWT::decode($token, new Key('your_secret_key', 'HS256'));
    
    $data = json_decode(file_get_contents('php://input'), true);
    $examset_id = isset($data['examset_id']) ? intval($data['examset_id']) : 0;

    if ($examset_id <= 0) {
        throw new Exception('Invalid examset ID');
    }

    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get examset title first
    $stmt = $conn->prepare("SELECT title FROM exam_sets WHERE id = ?");
    $stmt->execute([$examset_id]);
    $examset = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$examset) {
        throw new Exception('Examset not found');
    }

    // Get questions in the examset
    $stmt = $conn->prepare("
        SELECT q.*, eq.examset_id 
        FROM questions q
        INNER JOIN examset_questions eq ON q.question_id = eq.question_id
        WHERE eq.examset_id = ?
        ORDER BY q.question_id
    ");
    $stmt->execute([$examset_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'title' => $examset['title'],
        'questions' => $questions
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
