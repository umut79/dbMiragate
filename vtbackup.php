<?php
session_start();

function input($name, $default = '') {
    return isset($_POST[$name]) ? $_POST[$name] : (isset($_SESSION[$name]) ? $_SESSION[$name] : $default);
}

$step = isset($_POST['step']) ? intval($_POST['step']) : 1;

if ($step === 1) {
    // --- 1. ADIM: Bağlantı bilgilerini al ---
    ?>
    <h2>Kaynak ve Hedef Veritabanı Bilgileri</h2>
    <form method="post">
        <h3>Kaynak</h3>
        Host: <input type="text" name="src_host" value="<?= input('src_host','localhost') ?>"><br>
        Kullanıcı: <input type="text" name="src_user" value="<?= input('src_user') ?>"><br>
        Şifre: <input type="password" name="src_pass" value="<?= input('src_pass') ?>"><br>

        <h3>Hedef</h3>
        Host: <input type="text" name="dst_host" value="<?= input('dst_host','localhost') ?>"><br>
        Kullanıcı: <input type="text" name="dst_user" value="<?= input('dst_user') ?>"><br>
        Şifre: <input type="password" name="dst_pass" value="<?= input('dst_pass') ?>"><br>

        <input type="hidden" name="step" value="2">
        <button type="submit">Bağlan</button>
    </form>
    <?php
} elseif ($step === 2) {
    // --- 2. ADIM: Veritabanı seç ---
    $_SESSION = array_merge($_SESSION, $_POST);

    try {
        $srcPdo = new PDO(
            "mysql:host={$_SESSION['src_host']};charset=utf8mb4",
            $_SESSION['src_user'],
            $_SESSION['src_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $dstPdo = new PDO(
            "mysql:host={$_SESSION['dst_host']};charset=utf8mb4",
            $_SESSION['dst_user'],
            $_SESSION['dst_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (Exception $e) {
        die("Bağlantı hatası: " . $e->getMessage());
    }

    $dbs = $srcPdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);

    ?>
    <h2>Kaynak Veritabanı Seç</h2>
    <form method="post">
        <select name="src_db">
            <?php foreach ($dbs as $db): ?>
                <option value="<?= $db ?>"><?= $db ?></option>
            <?php endforeach; ?>
        </select>
        <br><br>
        Hedef Veritabanı Adı: <input type="text" name="dst_db" value=""><br>
        <input type="hidden" name="step" value="3">
        <button type="submit">Tabloları Listele</button>
    </form>
    <?php
} elseif ($step === 3) {
    // --- 3. ADIM: Tablo seç ---
    $_SESSION = array_merge($_SESSION, $_POST);

    try {
        $srcPdo = new PDO(
            "mysql:host={$_SESSION['src_host']};dbname={$_SESSION['src_db']};charset=utf8mb4",
            $_SESSION['src_user'],
            $_SESSION['src_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (Exception $e) {
        die("Kaynak DB hatası: " . $e->getMessage());
    }

    $tables = $srcPdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);

    ?>
    <h2>Tablo Seç</h2>
    <form method="post">
        <?php foreach ($tables as $row): ?>
            <label>
                <input type="checkbox" name="tables[]" value="<?= $row[0] ?>"> <?= $row[0] ?>
            </label><br>
        <?php endforeach; ?>
        <input type="hidden" name="step" value="4">
        <button type="submit">Kopyala</button>
    </form>
    <?php
} elseif ($step === 4) {
  // --- 4. ADIM: Kopyalama ---
  $_SESSION = array_merge($_SESSION, $_POST);

  $srcDb = $_SESSION['src_db'];
  $dstDb = $_SESSION['dst_db'];
  $tables = $_SESSION['tables'] ?? [];

  if (!$tables) die("Hiç tablo seçilmedi!");

  try {
    $srcPdo = new PDO(
      "mysql:host={$_SESSION['src_host']};dbname={$srcDb};charset=utf8mb4",
      $_SESSION['src_user'],
      $_SESSION['src_pass'],
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $dstPdo = new PDO(
      "mysql:host={$_SESSION['dst_host']};charset=utf8mb4",
      $_SESSION['dst_user'],
      $_SESSION['dst_pass'],
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    // Hedef veritabanı yoksa oluştur
    $dstPdo->exec("CREATE DATABASE IF NOT EXISTS `$dstDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
    $dstPdo->exec("USE `$dstDb`");    
  } catch (Exception $e) {
    die("Bağlantı hatası: " . $e->getMessage());
  }

  echo "<h2>Kopyalama Sonuçları</h2><pre>";
  foreach ($tables as $table) {
    echo "Tablo: $table\n";

    // CREATE TABLE al
    $createRow = $srcPdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_ASSOC);
    $createSql = array_values($createRow)[1] ?? '';

    // Foreign key satırlarını ayrı kaydet
    preg_match_all('/CONSTRAINT\s+[`"\w]+\s+FOREIGN KEY\s*\([^\)]+\)\s+REFERENCES\s+[^\)]+\)\s*(ON DELETE [A-Z ]+)?\s*(ON UPDATE [A-Z ]+)?/mi', $createSql, $matches);
    $constraints = $matches[0] ?? [];

    // CREATE TABLE içinden foreign key kısımlarını çıkar
    $createSqlClean = preg_replace('/,\s*CONSTRAINT.*FOREIGN KEY.*\)/i', '', $createSql);

    // Kaynak DB ismini kaldır
    $createSqlClean = preg_replace(
      '/,\s*CONSTRAINT\s+[`"\w]+\s+FOREIGN KEY\s*\([^\)]+\)\s+REFERENCES\s+[^\)]+\)\s*(ON DELETE [A-Z ]+)?\s*(ON UPDATE [A-Z ]+)?/mi',
      '',
      $createSql
    );

    // Tabloyu oluştur
    $dstPdo->exec("DROP TABLE IF EXISTS `$table`");
    $dstPdo->exec($createSqlClean);

    // Kopyalanan foreign key tanımlarını sakla (ileride kullanmak için session’da tutalım)
    $_SESSION['fkeys'][$table] = $constraints;

    // Verileri aktar
    $dstPdo->exec("INSERT INTO `$dstDb`.`$table` SELECT * FROM `$srcDb`.`$table`");
    $dstPdo->exec("SET FOREIGN_KEY_CHECKS=1");
    echo "  ✔ Kopyalandı\n";
  }
  echo "</pre><p>Tüm tablolar başarıyla kopyalandı.</p>";
  // Foreign checks
  foreach ($_SESSION['fkeys'] as $table => $constraints) {
    foreach ($constraints as $c) {
      $sql = "ALTER TABLE `$table` ADD $c";
      try {
        $dstPdo->exec($sql);
        echo "✔ Foreign key eklendi: $table → $c\n";
      } catch (Exception $e) {
        echo "✖ Foreign key eklenemedi ($table): " . $e->getMessage() . "\n";
      }
    }
  }
}