<?php
@error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
session_start();
if($_SESSION['login'] != true) {
    http_response_code(403);
    echo "<script>console.error('403 Unauthorized Access');</script>";
    echo "<p style='color:red'>403 Unauthorized Access</p>";
    exit;
}
// JSON satırlarını anlık gönderebilmek için
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // nginx için

function sendProgress($progress, $message) {
    echo json_encode(['progress' => $progress, 'message' => $message]) . "\n";
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

// Parametreleri al v.1
// Hedef DB bağlantısı parametreleri al
$src_host = $data['src_host'] ? $data['src_host'] : '';
$src_user = $data['src_user'] ? $data['src_user'] : '';
$src_pass = $data['src_pass'] ? $data['src_pass'] : '';
$src_db   = $data['src_db'] ? $data['src_db'] : '';
$dest_host = $data['dest_host'] ? $data['dest_host'] : '';
$dest_user = $data['dest_user'] ? $data['dest_user'] : '';
$dest_pass = $data['dest_pass'] ? $data['dest_pass'] : '';
$dest_db   = $data['dest_db'] ? $data['dest_db'] : '';
$tables = $data['tables'] ? $data['tables'] : null;
$views  = $data['views'] ? $data['views'] : null;
$drop_existing = isset($data['drop_existing']) ? $data['drop_existing'] : false;


// Bağlantılar
try {
    $src = new PDO("mysql:host=$src_host;dbname=$src_db;charset=utf8mb4", $src_user, $src_pass);
    $src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $dest = new PDO("mysql:host=$dest_host;charset=utf8mb4", $dest_user, $dest_pass);
    $dest->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Hedef veritabanı yoksa oluştur
    $dest->exec("CREATE DATABASE IF NOT EXISTS `$dest_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $dest->exec("USE `$dest_db`");

    // Foreign key check disable
    $dest->exec("SET FOREIGN_KEY_CHECKS=0");

} catch (Exception $e) {
    sendProgress(0, "Bağlantı hatası: " . $e->getMessage());
    exit;
}

// İşlem toplamı
$total = count($tables) + count($views);
$done = 0;

// --- 1) Tabloları oluştur ve veriyi kopyala ---
foreach ($tables as $t) {
    $done++;
    try {
        // CREATE TABLE al
        $row = $src->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
        $createSQL = $row['Create Table'];

        // Foreign key constraint'leri ayıkla (önce constraintsiz oluşturacağız)
        $createSQL_noFK = preg_replace('/,\s*CONSTRAINT\s+[`"\w]+\s+FOREIGN KEY\s*\([^\)]+\)\s+REFERENCES\s+[^\)]+\)\s*(ON DELETE [A-Z ]+)?\s*(ON UPDATE [A-Z ]+)?/mi', '', $createSQL);
        //$createSQL_noFK = preg_replace('/,\n\s*FOREIGN KEY.*?$/m', '', $createSQL_noFK);

        if ($drop_existing==true) {
            $dest->exec("DROP TABLE IF EXISTS `$t`");
            sendProgress(intval($done/$total*100), "Tablo silindi:<b>". $t ."</b>");
        } else {
            $dest->exec("CREATE TABLE IF NOT EXISTS `$t` LIKE $t");
        }
        $dest->exec($createSQL_noFK);
        sendProgress(intval($done/$total*100), "Tablo oluşturuldu:<b>". $t ."</b>");

        // Verileri kopyala
        $rows = $src->query("SELECT * FROM `$t`", PDO::FETCH_ASSOC);        
        foreach ($rows as $r) {
            $cols = array_map(fn($c) => "`$c`", array_keys($r));
            $vals = array_map(fn($v) => $dest->quote($v), array_values($r));
            $sql = "INSERT INTO `$t` (" . implode(",", $cols) . ") VALUES (" . implode(",", $vals) . ")";
            $dest->exec($sql);
        }
        sendProgress(intval($done/$total*100), "Veriler kopyalandı:<b>". $t ."</b> Toplam: <b>". $rows->rowCount() . "</b> kayıt.");

    } catch (Exception $e) {
        sendProgress(intval($done/$total*100), "Hata ($t): " . $e->getMessage());
    }
}

// --- 2) Foreign key constraint’leri ekle ---
foreach ($tables as $t) {
    $done++;
    try {
        $row = $src->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
        $createSQL = $row['Create Table'];

        // Sadece constraint satırlarını al
        if (preg_match_all('/CONSTRAINT\s+[`"\w]+\s+FOREIGN KEY\s*\([^\)]+\)\s+REFERENCES\s+[^\)]+\)\s*(ON DELETE [A-Z ]+)?\s*(ON UPDATE [A-Z ]+)?/mi', $createSQL, $matches)) {
            foreach ($matches[0] as $constr) {
                $sql = "ALTER TABLE `$t` ADD $constr";
                try {
                    $dest->exec($sql);
                    sendProgress(100, "Foreign key eklendi:<b>". $t ."</b>");
                } catch (Exception $ex) {
                    sendProgress(100, "Foreign key eklenemedi ($t): " . $ex->getMessage());
                }
            }
        }
    } catch (Exception $e) {
        sendProgress(100, "Foreign key analiz hatası ($t): " . $e->getMessage());
    }
}

// --- 3) View’leri oluştur ---
foreach ($views as $v) {
    $done++;
    try {
        $row = $src->query("SHOW CREATE VIEW `$v`")->fetch(PDO::FETCH_ASSOC);
        $createView = $row['Create View'];

        if ($drop_existing==true) {
            $dest->exec("DROP VIEW IF EXISTS `$v`");
            sendProgress(intval($done/$total*100), "View silindi:<b>". $v ."</b>");
        }
        $dest->exec($createView);
        sendProgress(intval($done/$total*100), "View oluşturuldu:<b>". $v ."</b>");
    } catch (Exception $e) {
        sendProgress(intval($done/$total*100), "Hata (view $v): " . $e->getMessage());
    }
}

// Foreign key check enable
$dest->exec("SET FOREIGN_KEY_CHECKS=1");

sendProgress(100, "Kopyalama (Migration) tamamlandı ✅");

exit;
?>