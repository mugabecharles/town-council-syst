<?php
require_once __DIR__ . '/../../includes/layout.php';
requireLogin();
$user = getCurrentUser();
$db   = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
verifyCsrf();

$redirect = $_POST['redirect'] ?? APP_URL . '/modules/documents/index.php';

if (empty($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    setFlash('danger','No file uploaded or upload error.');
    header('Location: '.$redirect); exit;
}

$subfolder  = 'documents/' . date('Y/m');
$uploadResult = handleFileUpload($_FILES['document'], $subfolder);
if (!$uploadResult['success']) {
    setFlash('danger', 'Upload failed: ' . $uploadResult['error']);
    header('Location: '.$redirect); exit;
}

$docNum     = generateDocNumber();
$relModule  = trim($_POST['related_module'] ?? '');
$relId      = (int)($_POST['related_id'] ?? 0);
$deptId     = !empty($_POST['department_id']) ? (int)$_POST['department_id'] : null;

// Check if updating an existing doc (version control)
$parentId   = null;
$version    = 1;
if ($relModule && $relId) {
    $existing = $db->prepare("SELECT id, version FROM documents WHERE related_module=? AND related_id=? AND is_latest=1 ORDER BY version DESC LIMIT 1");
    $existing->execute([$relModule, $relId]);
    $existingDoc = $existing->fetch();
    if ($existingDoc) {
        $version  = $existingDoc['version'] + 1;
        $parentId = $existingDoc['id'];
        $db->prepare("UPDATE documents SET is_latest=0 WHERE id=?")->execute([$existingDoc['id']]);
    }
}

$db->prepare("INSERT INTO documents (doc_number,title,description,doc_type,related_module,related_id,department_id,financial_year,file_name,file_path,file_size,file_type,version,is_latest,parent_doc_id,is_confidential,uploaded_by)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,?)")
   ->execute([
       $docNum, trim($_POST['title']), trim($_POST['description'] ?? ''),
       $_POST['doc_type'], $relModule ?: null, $relId ?: null,
       $deptId, trim($_POST['financial_year'] ?? ''),
       $uploadResult['file_name'], $uploadResult['file_path'],
       $uploadResult['file_size'], $uploadResult['file_type'],
       $version, $parentId,
       !empty($_POST['is_confidential']) ? 1 : 0, $user['id']
   ]);

logAudit('UPLOAD','documents','document',(int)$db->lastInsertId(),$docNum);
setFlash('success',"Document uploaded: {$docNum} (v{$version})");
header('Location: '.$redirect); exit;
