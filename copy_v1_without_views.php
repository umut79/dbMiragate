<?php
session_start();
header('Content-Type: application/json');

try {
    $srcDsn = "mysql:host={$_SESSION['src_host']};dbname={$_SESSION['src_db']};charset=utf8mb4";
    $dstDsn = "mysql:host={$_SESSION['dst_host']};dbname={$_SESSION['dst_db']};charset=utf8mb4";

    $src = new PDO($srcDsn, $_SESSION['src_user'], $_SESSION['src_pass']);
    $dst = new PDO($dstDsn, $_SESSION['dst_user'], $_SESSION['dst_pass']);
    $src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dst->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Tablolar listesi
    if (!isset($_SESSION['tables'])) {
        $tables = $src->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $_SESSION['tables'] = $tables;
        $_SESSION['constraints'] = []; // FK'ler burada tutulacak
    } else {
        $tables = $_SESSION['tables'];
    }

    $total = count($tables);
    $step = isset($_GET['step']) ? (int)$_GET['step'] : 0;

    // 1️⃣ Tablo kopyalama aşamaları
    if ($step < $total) {
        $table = $tables[$step];

        // CREATE TABLE al
        $row = $src->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
        $createSql = $row['Create Table'];

        $views = $src->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_COLUMN);
        $_SESSION['views'] = $views;

        // Foreign key constraint’leri yakala
        preg_match_all(
            '/CONSTRAINT\s+[`"\w]+\s+FOREIGN KEY\s*\([^\)]+\)\s+REFERENCES\s+[^\)]+\)\s*(ON DELETE [A-Z ]+)?\s*(ON UPDATE [A-Z ]+)?/mi',
            $createSql,
            $matches
        );
        if (!isset($_SESSION['constraints'][$table])) {
            $_SESSION['constraints'][$table] = $matches[0];
        }

        // CREATE TABLE'dan foreign key’leri çıkar
        $createSqlClean = preg_replace(
            '/,\s*CONSTRAINT\s+[`"\w]+\s+FOREIGN KEY\s*\([^\)]+\)\s+REFERENCES\s+[^\)]+\)\s*(ON DELETE [A-Z ]+)?\s*(ON UPDATE [A-Z ]+)?/mi',
            '',
            $createSql
        );

        // Hedef tabloyu oluştur
        $dst->exec("SET FOREIGN_KEY_CHECKS=0");
        $dst->exec("DROP TABLE IF EXISTS `$table`");
        $dst->exec($createSqlClean);

        // Veri kopyala
        $rows = $src->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $cols = array_keys($rows[0]);
            $colList = implode("`,`", $cols);
            $placeholders = rtrim(str_repeat("?,", count($cols)), ",");
            $stmt = $dst->prepare("INSERT INTO `$table` (`$colList`) VALUES ($placeholders)");
            foreach ($rows as $r) {
                $stmt->execute(array_values($r));
            }
        }

        echo json_encode([
            "status" => "ok",
            "total" => $total,
            "current" => $step + 1,
            "table" => $table
        ]);
        exit;
    }

    // 2️⃣ Tüm tablolar bittikten sonra foreign key’leri ekleme
    if ($step == $total) {
        foreach ($_SESSION['constraints'] as $table => $cons) {
            foreach ($cons as $c) {
                try {
                    $dst->exec("ALTER TABLE `$table` ADD $c");
                } catch (Exception $e) {
                    // FK eklenemezse hata bastırıyoruz ama loglanabilir
                }
            }
        }
        $dst->exec("SET FOREIGN_KEY_CHECKS=1");

        echo json_encode([
            "status" => "done",
            "total" => $total
        ]);

        // Session temizle
        unset($_SESSION['tables'], $_SESSION['constraints']);
        exit;
    }
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
}
