<?php
// JSON satırlarını anlık gönderebilmek için
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // nginx için

function sendProgress($progress, $message) {
    echo json_encode(['progress' => $progress, 'message' => $message]) . "\n";
    ob_flush();
    flush();
}

// Parametreleri al
$src_host = $_POST['src_host'] ?? '';
$src_user = $_POST['src_user'] ?? '';
$src_pass = $_POST['src_pass'] ?? '';
$src_db   = $_POST['src_db'] ?? '';

$dest_host = $_POST['dest_host'] ?? '';
$dest_user = $_POST['dest_user'] ?? '';
$dest_pass = $_POST['dest_pass'] ?? '';
$dest_db   = $_POST['dest_db'] ?? '';

$tables = $_POST['tables'] ?? [];
$views  = $_POST['views'] ?? [];
$drop_existing = isset($_POST['drop_existing']);

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

        if ($drop_existing) {
            $dest->exec("DROP TABLE IF EXISTS `$t`");
        }
        $dest->exec($createSQL_noFK);
        sendProgress(intval($done/$total*100), "Tablo oluşturuldu: $t");

        // Verileri kopyala
        $rows = $src->query("SELECT * FROM `$t`", PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $cols = array_map(fn($c) => "`$c`", array_keys($r));
            $vals = array_map(fn($v) => $dest->quote($v), array_values($r));
            $sql = "INSERT INTO `$t` (" . implode(",", $cols) . ") VALUES (" . implode(",", $vals) . ")";
            $dest->exec($sql);
        }
        sendProgress(intval($done/$total*100), "Veriler kopyalandı: $t");

    } catch (Exception $e) {
        sendProgress(intval($done/$total*100), "Hata ($t): " . $e->getMessage());
    }
}

// --- 2) Foreign key constraint’leri ekle ---
foreach ($tables as $t) {
    try {
        $row = $src->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
        $createSQL = $row['Create Table'];

        // Sadece constraint satırlarını al
        if (preg_match_all('/CONSTRAINT\s+[`"\w]+\s+FOREIGN KEY\s*\([^\)]+\)\s+REFERENCES\s+[^\)]+\)\s*(ON DELETE [A-Z ]+)?\s*(ON UPDATE [A-Z ]+)?/mi', $createSQL, $matches)) {
            foreach ($matches[0] as $constr) {
                $sql = "ALTER TABLE `$t` ADD $constr";
                try {
                    $dest->exec($sql);
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
        $createSQL = $row['Create View'];

        if ($drop_existing) {
            $dest->exec("DROP VIEW IF EXISTS `$v`");
        }

        $dest->exec($createSQL);
        sendProgress(intval($done/$total*100), "View oluşturuldu: $v");

    } catch (Exception $e) {
        sendProgress(intval($done/$total*100), "Hata (view $v): " . $e->getMessage());
    }
}

// Foreign key check enable
$dest->exec("SET FOREIGN_KEY_CHECKS=1");

sendProgress(100, "Migration tamamlandı ✅");

exit;
?>