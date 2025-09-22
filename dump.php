<?php
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

function sendProgress($progress, $message, $extra = [])
{
  echo json_encode(array_merge([
    'progress' => $progress,
    'message'  => $message
  ], $extra)) . "\n";
  ob_flush();
  flush();
}

if (!isset($_POST['payload'])) {
  http_response_code(400);
  sendProgress(0, "payload yok");
  // die(json_encode(['ok' => false, 'msg' => 'payload yok']));
}

$decoded = base64_decode($_POST['payload']);
if ($decoded === false) {
  http_response_code(400);
  sendProgress(0, "base64 decode hatası");
  // die(json_encode(['ok' => false, 'msg' => 'base64 decode hatası']));
}

// DecodeURIComponent inverse (JS tarafında encodeURIComponent+unescape kullandıysak)
$decoded = rawurldecode($decoded); // genelde gerekmez; eğer sorun olursa deneyin

// JSON parse
$data = json_decode($decoded, true);
if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
  http_response_code(400);
  sendProgress(0, "JSON parse hatası: " . json_last_error_msg());
  // die(json_encode(['ok' => false, 'msg' => 'JSON parse hatası: ' . json_last_error_msg()]));
}

// Normalleştirme: 'tables' veya 'tables[]' varyasyonlarını kontrol et
// (JS tarafında biz cleanKey kullandık, ama burada ek kontrol koymak zarar vermez)
if (isset($data['tables']) && !is_array($data['tables'])) {
  // tek seçili ise string gelebilir; tek elemanlı diziye çevir
  $data['tables'] = [$data['tables']];
}
if (isset($data['views']) && !is_array($data['views'])) {
  $data['views'] = [$data['views']];
}

// Parametreler v.2
$src_host = $data['src_host'] ?? '';
$src_user = $data['src_user'] ?? '';
$src_pass = $data['src_pass'] ?? '';
$src_db   = $data['src_db'] ?? '';
$tables = $data['tables'] ?? [];
$views  = $data['views'] ?? [];
$drop_existing = isset($data['drop_existing']) ?? false;


// Parametreler v.1
/*
$src_host = $_POST['src_host'] ?? '';
$src_user = $_POST['src_user'] ?? '';
$src_pass = $_POST['src_pass'] ?? '';
$src_db   = $_POST['src_db'] ?? '';
$tables   = $_POST['tables'] ?? [];
$views    = $_POST['views'] ?? [];
$drop_existing = isset($_POST['drop_existing']) ?? false;
*/

// Bağlantı
try {
  $pdo = new PDO("mysql:host=$src_host;dbname=$src_db;charset=utf8mb4", $src_user, $src_pass);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
  sendProgress(0, "Bağlantı hatası: " . $e->getMessage());
  exit;
}

// Dump dosyası yolu
$filename = "dump_" . $src_db . "_" . date("Ymd_His") . ".sql";
$filepath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $filename;
$f = fopen($filepath, "w");

// Batch size
$batch_size = 500;

// İşlem toplamı
$total = count($tables) + count($views);
$done  = 0;

fwrite($f, "-- SQL Dump ". date("Y-m-d H:i:s") . "\n");
fwrite($f, "-- Kaynak Host: $src_host\n");
fwrite($f, "-- Kaynak DB: $src_db\n");
fwrite($f, "SET FOREIGN_KEY_CHECKS=0;\n");
fwrite($f, "START TRANSACTION;\n\n");

// $output[] = "-- SQL Dump ". date("Y-m-d H:i:s") . "\n";
// $output[] = "-- Kaynak Host: $src_host\n";
// $output[] = "-- Kaynak DB: $src_db\n";
// $output[] = "SET FOREIGN_KEY_CHECKS=0;\n";
// $output[] = "START TRANSACTION;\n";

$fk_constraints = [];
// --- 1) Tablolar ---
foreach ($tables as $t) {
  $done++;
  try {
    // CREATE TABLE
    $row = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
    $createSQL = $row['Create Table'];

    // Constraintleri sakla, tablosuz oluştur
    if (preg_match_all('/CONSTRAINT\s+[`"\w]+\s+FOREIGN KEY\s*\([^\)]+\)\s+REFERENCES\s+[^\)]+\)\s*(ON DELETE [A-Z ]+)?\s*(ON UPDATE [A-Z ]+)?/mi', $createSQL, $matches)) {
      foreach ($matches[0] as $constr) {
        $fk_constraints[] = "ALTER TABLE `$t` ADD $constr;";
      }
      // Constraintleri CREATE TABLE'dan kaldır
      $createSQL = preg_replace('/,\s*CONSTRAINT\s+[`"\w]+\s+FOREIGN KEY.*?(?=\))/', '', $createSQL);
    }
    if ($drop_existing) {
      fwrite($f, "DROP TABLE IF EXISTS `$t`;\n");
      // $output[] = "DROP TABLE IF EXISTS `$t`;";
      sendProgress(intval($done / $total * 100), "Tablo silindi: $t");
    }
    fwrite($f, $createSQL . ";\n\n");
    // $output[] = $createSQL . ";\n";     
    sendProgress(intval($done / $total * 100), "Tablo yapısı yazıldı: $t");

    // INSERT verileri (1000 satır per batch)
    $stmt = $pdo->query("SELECT * FROM `$t`", PDO::FETCH_ASSOC);
    $stmt->rowCount();
    fwrite($f, "-- ". $t ." tablosunda ". $stmt->rowCount() . " kayıt bulundu.\n");
    // $output[] = "-- ". $t ." tablosunda ". $stmt->rowCount() . " kayıt var.\n";
    $batch = [];
    foreach ($stmt as $r) {
      $vals = array_map(fn($v) => $pdo->quote($v), array_values($r));
      $batch[] = "(" . implode(",", $vals) . ")";
      if (count($batch) >= $batch_size) {
        fwrite($f, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n");
        // $output[] = "INSERT INTO `$t` VALUES " . implode(",", $batch) . ";\n";
        $batch = [];
      }
    }
    if ($batch) {
      fwrite($f, "INSERT INTO `$t` VALUES " . implode(",\n", $batch) . ";\n");
      // $output[] = "INSERT INTO `$t` VALUES " . implode(",", $batch) . ";\n";
    }
    fwrite($f, "\n\n");
    sendProgress(intval($done / $total * 100), "Tablo verisi yazıldı: $t");
  } catch (Exception $e) {
    sendProgress(intval($done / $total * 100), "Hata ($t): " . $e->getMessage());
  }
}

// --- 2) View’ler ---
foreach ($views as $v) {
  $done++;
  try {
    $row = $pdo->query("SHOW CREATE VIEW `$v`")->fetch(PDO::FETCH_ASSOC);
    $createView = $row['Create View'];
    if ($drop_existing) {
      fwrite($f, "DROP VIEW IF EXISTS `$v`;\n");
      // $output[] = "DROP VIEW IF EXISTS `$v`;\n";
      sendProgress(intval($done / $total * 100), "View silindi: $v");
    }
    fwrite($f, $createView . ";\n\n");
    // $output[] = $createView . ";\n\n";
    sendProgress(intval($done / $total * 100), "View yapısı yazıldı: $v");
  } catch (Exception $e) {
    sendProgress(intval($done / $total * 100), "Hata (view $v): " . $e->getMessage());
  }
}

// --- 3) Foreign key’ler ---
foreach ($fk_constraints as $fk) {
  $done++;
  try {
    fwrite($f, $fk . "\n");
    // $output[] = $fk . "\n";
    sendProgress(intval($done / $total * 100), "Foreign key eklendi.");
  } catch (Exception $e) {
    sendProgress(intval($done / $total * 100), "Hata (foreign key): " . $e->getMessage());
  }
}
fwrite($f, "\n");
fwrite($f, "COMMIT;\n");
fwrite($f, "SET FOREIGN_KEY_CHECKS=1;\n");
fwrite($f, "-- SQL Dump tamamlandı @" . date("H:i:s") . "\n");

// $output[] = "\n");
// $output[] = "COMMIT;\n";
// $output[] = "SET FOREIGN_KEY_CHECKS=1;\n";
// $output[] = "-- SQL Dump tamamlandı @" . date("H:i:s") . "\n";

fclose($f);
// echo implode("\n", $output)

// ✅ Dump tamamlandı → frontende indirilecek dosya bilgisini gönder
sendProgress(100, "İşlem tamamlandı @" . date("H:i:s") . " ", [
  'fileReady' => true,
  'filename'  => $filename
]);
exit;
?>