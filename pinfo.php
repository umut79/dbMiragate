<?php
header('Content-Type: text/html; charset=utf-8');
// Şüphelendiğiniz veya kritik gördüğünüz fonksiyonların listesi
$functions_to_check = [
  'exec',             // Komut çalıştırma
  'shell_exec',       // Komut çalıştırma
  'passthru',         // Komut çalıştırma
  'system',           // Komut çalıştırma
  'proc_open',        // Süreç yönetimi
  'dl',               // Dinamik yükleme
  'ini_set',          // Ayar değiştirme (bazı sunucularda)
  'ini_get',          // Ayar okuma (bazı sunucularda)
  'phpinfo',           // Test ettiğiniz fonksiyon
  'sys_get_temp_dir',
  'file_exists',
  'filesize',
  'fopen',
  'fwrite'
];

echo "<h2>Fonksiyon Varolma Kontrolü Sonuçları:</h2>";
echo "<table border='1' cellpadding='10' cellspacing='0'>";
echo "<tr><th>Fonksiyon Adı</th><th>Durum</th><th>Açıklama</th></tr>";

foreach ($functions_to_check as $func) {
  if (function_exists($func)) {
    // Fonksiyonun var olması, büyük ihtimalle yasaklanmadığı anlamına gelir.
    $status = "<span style='color: green; font-weight: bold;'>VAR (Muhtemelen İzinli)</span>";
  } else {
    // Fonksiyonun var olmaması, yasaklandığı veya hiç yüklenmediği anlamına gelir.
    $status = "<span style='color: red; font-weight: bold;'>YOK (Yasaklanmış olabilir)</span>";
  }

  // Basit bir açıklama ekleyelim
  $description = ''; // Başlangıçta tanımlıyoruz

  switch ($func) {
    case 'exec':
    case 'shell_exec':
    case 'passthru':
    case 'system':
      $description = 'Sunucu komut çalıştırma';
      break;

    case 'proc_open':
      $description = 'Gelişmiş süreç yönetimi';
      break;

    case 'dl':
      $description = 'Dinamik uzantı yükleme';
      break;

    case 'ini_set':
      $description = 'PHP ayarlarını değiştirme';
      break;

    case 'ini_get':
      $description = 'PHP ayarlarını okuma';
      break;

    case 'phpinfo':
      $description = 'PHP ayarlarını listeleme';
      break;

    default:
      $description = '';
      break;
  }

  echo "<tr><td>{$func}</td><td>{$status}</td><td>{$description}</td></tr>";
}

echo "</table>";
