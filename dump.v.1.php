<?php
// SQL dump çıktısı
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="dump.sql"');

$src_host = $_POST['src_host'] ?? 'localhost';
$src_user = $_POST['src_user'] ?? '';
$src_pass = $_POST['src_pass'] ?? '';
$src_db   = $_POST['src_db'] ?? '';

$tables = $_POST['tables'] ?? [];
$views  = $_POST['views'] ?? [];

try {
    $src = new PDO("mysql:host=$src_host;dbname=$src_db;charset=utf8mb4", $src_user, $src_pass);
    $src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("-- Bağlantı hatası: " . $e->getMessage());
}

$output = [];
$output[] = "-- SQL Dump";
$output[] = "-- Kaynak DB: $src_db";
$output[] = "SET FOREIGN_KEY_CHECKS=0;";
$output[] = "START TRANSACTION;\n";

// --- 1) Tabloları ve verileri dump et ---
$fk_constraints = [];

foreach ($tables as $t) {
    try {
        $row = $src->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC);
        $createSQL = $row['Create Table'];

        // Constraintleri sakla, tablosuz oluştur
        if (preg_match_all('/CONSTRAINT\s+[`"\w]+\s+FOREIGN KEY\s*\([^\)]+\)\s+REFERENCES\s+[^\)]+\)\s*(ON DELETE [A-Z ]+)?\s*(ON UPDATE [A-Z ]+)?/mi', $createSQL, $matches)) {
            foreach ($matches[0] as $constr) {
                $fk_constraints[] = "ALTER TABLE `$t` ADD $constr;";
            }
            $createSQL = preg_replace('/,\s*CONSTRAINT\s+[`"\w]+\s+FOREIGN KEY.*?(?=\))/', '', $createSQL);
        }

        $output[] = "DROP TABLE IF EXISTS `$t`;";
        $output[] = $createSQL . ";\n";

        // Veriler (1000 satırda bir toplu insert)
        $rows = $src->query("SELECT * FROM `$t`", PDO::FETCH_ASSOC);
        $batch = [];
        $counter = 0;
        $cols = [];

        foreach ($rows as $r) {
            if (empty($cols)) {
                $cols = array_map(fn($c) => "`$c`", array_keys($r));
            }
            $vals = array_map(fn($v) => $src->quote($v), array_values($r));
            $batch[] = "(" . implode(",", $vals) . ")";
            $counter++;

            if ($counter % 1000 === 0) {
                $output[] = "INSERT INTO `$t` (" . implode(",", $cols) . ") VALUES\n" . implode(",\n", $batch) . ";";
                $batch = [];
            }
        }

        // Kalan kayıtları yaz
        if (!empty($batch)) {
            $output[] = "INSERT INTO `$t` (" . implode(",", $cols) . ") VALUES\n" . implode(",\n", $batch) . ";";
        }

        $output[] = "";

    } catch (Exception $e) {
        $output[] = "-- Hata ($t): " . $e->getMessage();
    }
}

// --- 2) View’ler ---
foreach ($views as $v) {
    try {
        $row = $src->query("SHOW CREATE VIEW `$v`")->fetch(PDO::FETCH_ASSOC);
        $createSQL = $row['Create View'];

        $output[] = "DROP VIEW IF EXISTS `$v`;";
        $output[] = $createSQL . ";\n";
    } catch (Exception $e) {
        $output[] = "-- Hata (view $v): " . $e->getMessage();
    }
}

// --- 3) Foreign key’ler ---
foreach ($fk_constraints as $fk) {
    $output[] = $fk;
}

$output[] = "COMMIT;";
$output[] = "SET FOREIGN_KEY_CHECKS=1;";
$output[] = "-- Dump tamamlandı ✅";

// Çıktıyı yaz
echo implode("\n", $output);
exit;
