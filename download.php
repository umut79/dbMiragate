<?php
$filename = $_GET['file'] ? $_GET['file'] : '';
$filepath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . basename($filename);

if (!file_exists($filepath)) {
    http_response_code(404);
    echo "Dosya bulunamadı.";
    exit;
}

header('Content-Description: File Transfer');
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . basename($filename) . '"');
header('Content-Length: ' . filesize($filepath));
readfile($filepath);
exit;
?>