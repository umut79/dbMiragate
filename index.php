<?php
$appName = "Veritabanı Kopyalama/Yedekleme (DB Migration/Backup)";
$appVersion = "v0.1";
$appAuthor = "Umut AÇIKGÖZ / github.com/umutacikgoz";
function strReplace($t)
{
  $t = filter_var(trim($t), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
  $s = array("Ç", "ç", "Ğ", "ğ", "İ", "ı", "Ö", "ö", "Ş", "ş", "Ü", "ü");
  $r = array("C", "c", "G", "g", "I", "i", "O", "o", "S", "s", "U", "u");
  $t = str_replace($s, $r, $t);
  return $t;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] == 1) {
  // Kaynak DB bağlantısı
  $src_host = $_POST['src_host'] ?? 'localhost';
  $src_user = $_POST['src_user'] ?? '';
  $src_pass = $_POST['src_pass'] ?? '';
  $src_db   = $_POST['src_db'] ?? '';

  $src_equal_dest = isset($_POST['db_settings_equal']) ? 1 : 0;
  if (isset($src_equal_dest)) {
    $dest_db_prefix = $_POST['dest_db_prefix'] ?? '';
    switch ($dest_db_prefix) {
      case 'date':
        $dest_db = $src_db . '_' . date('Ymd');
        break;
      case 'custom':
        $dest_db = $src_db . '_' . strReplace($_POST['dest_db_prefix_text']);
        break;
      case 'random':
        $dest_db = $src_db . '_' . date('YmdHis');
        break;
      case 'original':
        $dest_db = $src_db;
        break;
      default:
        $dest_db = $src_db;
        break;
    }
    // Hedef DB bağlantısı
    $dest_host = $src_host;
    $dest_user = $src_user;
    $dest_pass = $src_pass;
  }

  try {
    $pdo = new PDO("mysql:host=$src_host;dbname=$src_db;charset=utf8mb4", $src_user, $src_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Tablo listesi
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_Type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

    // View listesi
    $views = $pdo->query("SHOW FULL TABLES WHERE Table_Type = 'VIEW'")->fetchAll(PDO::FETCH_COLUMN);
  } catch (Exception $e) {
    die("<div class='alert alert-danger'>Bağlantı hatası: " . $e->getMessage() . "</div>");
  }


?>
  <!DOCTYPE html>
  <html lang="tr">

  <head>
    <meta charset="UTF-8">
    <title><?= $appName ?> | <?= $appVersion ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  </head>

  <body class="container-fluid container-lg py-4">

    <h2 class="mb-4"><?= $appName ?> <small class="text-muted fs-6">(<?= $appVersion ?>)</small></h2>

    <form method="post" action="" id="migrationForm">
      <!-- Kaynak DB bilgileri -->
      <input type="hidden" name="src_host" value="<?= htmlspecialchars($src_host) ?>">
      <input type="hidden" name="src_user" value="<?= htmlspecialchars($src_user) ?>">
      <input type="hidden" name="src_pass" value="<?= htmlspecialchars($src_pass) ?>">
      <input type="hidden" name="src_db" value="<?= htmlspecialchars($src_db) ?>">

      <div class="row g-3 mb-4">
        <div class="col-12 col-lg-6">
          <div class="card">
            <div class="card-header bg-primary text-white">Hedef Veritabanı/SQL Çıktı Ayarları</div>
            <div class="card-body">
              <div class="mb-3">
                <label class="form-label">Sunucu/Host</label>
                <input type="text" class="form-control" name="dest_host" value="<?= htmlspecialchars($dest_host) ?>" required>
              </div>
              <div class=" mb-3">
                <label class="form-label">Kullanıcı</label>
                <input type="text" class="form-control" name="dest_user" value="<?= htmlspecialchars($dest_user); ?>" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Şifre</label>
                <input type="password" class="form-control" name="dest_pass" value="<?= htmlspecialchars($dest_pass); ?>" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Veritabanı Adı</label>
                <input type="text" class="form-control" name="dest_db" value="<?= htmlspecialchars($dest_db); ?>" required>
              </div>
              <div class="mb-3 form-check">
                <input class="form-check-input" type="checkbox" name="drop_existing" value="1" id="drop_existing">
                <label class="form-check-label" for="drop_existing">
                  Hedefteki mevcut tabloları önce <span class="text-danger">sil</span>
                </label>
              </div>
            </div>
          </div>
        </div>

        <!-- Tablo ve view seçimleri -->
        <div class="col-12 col-lg-6">
          <div class="card">
            <div class="card-header bg-success text-white">Kopyalanacak Nesneler</div>
            <div class="card-body">
              <div class="mb-3">
                <label class="form-label">Tablolar</label>
                <select name="tables[]" id="tables" multiple class="form-select" size="10" required>
                  <?php foreach ($tables as $t): ?>
                    <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">View'ler</label>
                <select name="views[]" id="views" multiple class="form-select" size="10">
                  <?php foreach ($views as $v): ?>
                    <option value="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($v) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3 mb-4 align-items-center">
        <div class="col-12 col-lg-4 text-center">
          <button type="submit" class="btn btn-primary w-100">Kopyalama (Migration) Başlat</button>
        </div>
        <div class="col-12 col-lg-4 text-center">
          <button type="button" class="btn btn-success w-100" id="sqlDump">SQL (Dump) Çıktısı Oluştur</button>
        </div>
        <div class="col-12 col-lg-4 text-center">
          <button type="button" class="btn btn-danger w-100" id="reset">İptal Et/Başa Dön</button>
        </div>
      </div>
    </form>

    <div id="progress-container" style="display:none;" class="mt-4">
      <h4>İlerleme</h4>
      <div class="progress mb-2" style="height:25px;">
        <div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated"
          role="progressbar" style="width:0%">0%</div>
      </div>
      <div class="card">
        <div class="card-header">Log</div>
        <div class="card-body" id="log" style="height:200px; overflow:auto; font-size:0.9em;"></div>
      </div>
    </div>

    <form id="resetForm" method="post" action="" style="display:none;">
      <input type="hidden" name="step" value="0">
    </form>

    <script>
      // Butonlar
      const copyBtn = document.querySelector('button[type=submit]');
      const dumpBtn = document.getElementById('sqlDump');
      const resetBtn = document.getElementById('reset');

      // Reset
      resetBtn.addEventListener("click", function() {
        const form = document.getElementById('resetForm');
        form.submit();
      });

      // Kopyalama submit
      document.getElementById('migrationForm').addEventListener('submit', function(e) {
        e.preventDefault();
        copyBtn.disabled = true;
        dumpBtn.disabled = true;

        document.getElementById('progress-container').style.display = 'block';
        document.getElementById('log').innerHTML = '';
        document.getElementById('progress-bar').style.width = '0%';
        document.getElementById('progress-bar').innerText = '0%';

        const form = document.getElementById("migrationForm");
        const formData = new FormData(form);
        /*
        const obj = Object.fromEntries(formData.entries());
        const encoded = btoa(JSON.stringify(obj));
        */
        const fd = new FormData(form);
        const obj = {};
        for (const key of fd.keys()) {
          const values = fd.getAll(key); // aynı isimli alanlar varsa hepsi döner
          // Eğer name sonu [] ile geliyorsa temizle (örn: tables[] -> tables)
          const cleanKey = key.endsWith('[]') ? key.slice(0, -2) : key;
          // Tek elemanlı dizi ise düz değer yap, çokluysa dizi bırak
          obj[cleanKey] = (values.length === 1) ? values[0] : values;
        }
        const json = JSON.stringify(obj);
        const encoded = btoa(unescape(encodeURIComponent(json))); // Unicode güvenli base64
        // x-www-form-urlencoded gönderim
        const body = 'payload=' + encodeURIComponent(encoded);

        fetch('copy.php', {
            method: 'POST',
            //body: formData
            headers: {
              "Content-Type": "application/x-www-form-urlencoded"
            },
            body: body
          }).then(response => response.body.getReader())
          .then(reader => streamReader(reader, () => {
            copyBtn.disabled = false;
            dumpBtn.disabled = false;
          }));
      });

      // SQL Dump
      dumpBtn.addEventListener("click", function() {
        copyBtn.disabled = true;
        dumpBtn.disabled = true;
        document.getElementById('progress-container').style.display = 'block';
        document.getElementById('log').innerHTML = '';
        document.getElementById('progress-bar').style.width = '0%';
        document.getElementById('progress-bar').innerText = '0%';
        if (document.getElementById('tables').value.length == 0) {
          alert('Tablo seçiniz.');
          copyBtn.disabled = false;
          dumpBtn.disabled = false;
          document.getElementById('tables').focus();
          document.getElementById('log').innerHTML = '<div class="alert alert-danger">Tablo seçiniz.</div>';
        } else {
          const form = document.getElementById("migrationForm");
          /*const formData = new FormData(form);
          const obj = Object.fromEntries(formData.entries());
          const encoded = btoa(JSON.stringify(obj));
          */
          // tüm anahtarları tek tek dolaş ve getAll ile tüm değerleri al
          const fd = new FormData(form);
          const obj = {};
          for (const key of fd.keys()) {
            const values = fd.getAll(key); // aynı isimli alanlar varsa hepsi döner
            // Eğer name sonu [] ile geliyorsa temizle (örn: tables[] -> tables)
            const cleanKey = key.endsWith('[]') ? key.slice(0, -2) : key;
            // Tek elemanlı dizi ise düz değer yap, çokluysa dizi bırak
            obj[cleanKey] = (values.length === 1) ? values[0] : values;
          }

          const json = JSON.stringify(obj);
          const encoded = btoa(unescape(encodeURIComponent(json))); // Unicode güvenli base64

          // x-www-form-urlencoded gönderim
          const body = 'payload=' + encodeURIComponent(encoded);
          fetch("dump.php", {
              method: 'POST',
              //body: formData
              headers: {
                "Content-Type": "application/x-www-form-urlencoded"
              },
              body: body
            }).then(response => response.body.getReader())
            .then(reader => streamReader(reader, () => {
              copyBtn.disabled = false;
              dumpBtn.disabled = false;
            }));
        }
      });

      // Ortak stream okuma
      function streamReader(reader, onFinish) {
        const decoder = new TextDecoder();

        function read() {
          reader.read().then(({
            done,
            value
          }) => {
            if (done) {
              onFinish();
              return;
            }
            let text = decoder.decode(value);
            let jsons = text.trim().split("\n");

            jsons.forEach(j => {
              if (!j.trim()) return;
              try {
                let data = JSON.parse(j);

                // Progress bar
                if (data.progress !== undefined) {
                  let bar = document.getElementById('progress-bar');
                  bar.style.width = data.progress + '%';
                  bar.innerText = data.progress + '%';
                }

                // Log
                if (data.message) {
                  let log = document.getElementById('log');
                  log.innerHTML += data.message + '<br>';
                  log.scrollTop = log.scrollHeight;
                }

                // ✅ Dump bittiğinde otomatik indirme
                if (data.fileReady && data.filename) {
                  window.location.href = "download.php?file=" + encodeURIComponent(data.filename);
                }

              } catch (err) {
                console.error("Parse error", err, j);
              }
            });
            read();
          });
        }
        read();
      }
    </script>


  </body>

  </html>
<?php
  exit;
}
?>

<!DOCTYPE html>
<html lang="tr">

<head>
  <meta charset="UTF-8">
  <title><?= $appName ?> | <?= $appVersion ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="container-fluid container-lg py-4">
  <h2 class="mb-4"><?= $appName ?> <small class="text-muted fs-6">(<?= $appVersion ?>)</small></h2>
  <div class="card">
    <div class="card-header bg-primary text-white">Veritabanı Bağlantısı</div>
    <div class="card-body">
      <form method="post" action="" id="dbSettingsForm">
        <input type="hidden" name="step" value="1">
        <div class="row g-3 mb-3">
          <div class="col-12 col-lg-6">
            <label class="form-label" for="src_host">Sunucu/Host</label>
            <input type="text" class="form-control" name="src_host" required>
          </div>
          <div class="col-12 col-lg-6">
            <label class="form-label" for="src_user">Kullanıcı</label>
            <input type="text" class="form-control" name="src_user" required>
          </div>
          <div class="col-12 col-lg-6">
            <label class="form-label" for="src_pass">Şifre</label>
            <input type="password" class="form-control" name="src_pass">
          </div>
          <div class="col-12 col-lg-6">
            <label class="form-label" for="src_db">Veritabanı</label>
            <input type="text" class="form-control" name="src_db" required>
          </div>
          <div class="col-12 col-lg-6">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="db_settings_equal" value="1" id="db_settings_equal">
              <label class="form-check-label" for="db_settings_equal">
                Hedef veritabanı bağlantı ayarları kaynak veritabanıyla aynı
              </label>
            </div>
          </div>

          <div class="col-12">
            <div class="row g-2 mb-3 align-items-center">
              <div class="col-12 col-lg-4 form-label">Hedef veritabanı son eki (prefix)</div>
              <div class="col-12 col-lg-8 d-flex flex-column flex-lg-row justify-content-start justify-content-lg-between">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="dest_db_prefix" value="original" id="dest_db_prefix_null" checked>
                  <label class="form-check-label" for="dest_db_prefix_null">Yok</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="dest_db_prefix" value="date" id="dest_db_prefix_date">
                  <label class="form-check-label" for="dest_db_prefix_date">Tarih <span class="text-muted">(YYmmdd)</span></label>
                </div>
                <div class="d-flex align-items-center">
                  <div class="form-check">
                    <input class="form-check-input" type="radio" name="dest_db_prefix" value="custom" id="dest_db_prefix_custom">
                    <label class="form-check-label" for="dest_db_prefix_custom">Özel</label>
                  </div>
                  <input class="form-control ms-2" type="text" name="dest_db_prefix_text" id="dest_db_prefix_text" maxlength="10" value="">
                </div>
              </div>
            </div>
          </div>

          <div class="col-12 m-2 text-center">
            <button type="submit" class="btn btn-success px-5">Bağlan</button>
          </div>
        </div>
      </form>
    </div>
  </div>
</body>
<script>
  const customInput = document.getElementById("dest_db_prefix_text");
  const radios = document.querySelectorAll("input[name=dest_db_prefix]");

  function toggleCustomInput() {
    const selected = document.querySelector("input[name=dest_db_prefix]:checked").value;
    if (selected === "custom") {
      customInput.disabled = false;
    } else {
      customInput.disabled = true;
      customInput.value = "";
    }
  }
  // İlk yüklemede çalıştır
  toggleCustomInput();
  radios.forEach(r => r.addEventListener("change", toggleCustomInput));
</script>

</html>