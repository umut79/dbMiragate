<?php
function strReplace($t) {
  $t = filter_var(trim($t), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
  $s = array("Ç", "ç", "Ğ", "ğ", "İ", "ı", "Ö", "ö", "Ş", "ş", "Ü", "ü");
  $r = array("C", "c", "G", "g", "I", "i", "O", "o", "S", "s", "U", "u");
  $t = str_replace($s, $r, $t);
  return $t;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['step']) && $_POST['step'] == 1) {
  // Kaynak DB bağlantısı
  $src_host = $_POST['src_host'];
  $src_user = $_POST['src_user'];
  $src_pass = $_POST['src_pass'];
  $src_db   = $_POST['src_db'];
  $src_dest = $_POST['db_settings_equal'];

  $dest_db_prefix = $_POST['dest_db_prefix_date'] ? date('Ymd') : str_replace(" ", "_", $_POST['dest_db_prefix']);


  if (isset($src_dest)) {
    // Hedef DB bağlantısı
    $dest_host = $src_host;
    $dest_user = $src_user;
    $dest_pass = $src_pass;
    $dest_db   = $src_db."_".$dest_db_prefix;
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
    <title>Migration Seçimi</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  </head>

  <body class="container py-4">

    <h2 class="mb-4">Kopyalama (Migration) Ayarları</h2>

    <form method="post" action="copy.php" id="migrationForm">
      <!-- Kaynak DB bilgileri -->
      <input type="hidden" name="src_host" value="<?= htmlspecialchars($src_host) ?>">
      <input type="hidden" name="src_user" value="<?= htmlspecialchars($src_user) ?>">
      <input type="hidden" name="src_pass" value="<?= htmlspecialchars($src_pass) ?>">
      <input type="hidden" name="src_db" value="<?= htmlspecialchars($src_db) ?>">

      <div class="row g-3">
        <div class="col-md-6">
          <div class="card">
            <div class="card-header bg-primary text-white">Hedef Veritabanı</div>
            <div class="card-body">
              <div class="mb-3">
                <label class="form-label">Host</label>
                <input type="text" class="form-control" name="dest_host" value="<?= htmlspecialchars($dest_host) ?>" required>
              </div>
              <div class=" mb-3">
                <label class="form-label">Kullanıcı</label>
                <input type="text" class="form-control" name="dest_user" value="<?= htmlspecialchars($dest_user); ?>" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Şifre</label>
                <input type="password" class="form-control" name="dest_pass" value="<?= htmlspecialchars($dest_pass); ?>">
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
        <div class="col-md-6">
          <div class="card">
            <div class="card-header bg-success text-white">Kopyalanacak Nesneler</div>
            <div class="card-body">
              <div class="mb-3">
                <label class="form-label">Tablolar</label>
                <select name="tables[]" multiple class="form-select" size="10">
                  <?php foreach ($tables as $t): ?>
                    <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">View'ler</label>
                <select name="views[]" multiple class="form-select" size="10">
                  <?php foreach ($views as $v): ?>
                    <option value="<?= htmlspecialchars($v) ?>"><?= htmlspecialchars($v) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="mt-4">
        <button type="submit" class="btn btn-primary">Kopyalama (Migration) Başlat</button>
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

    <script>
      document.getElementById('migrationForm').addEventListener('submit', function(e) {
        e.preventDefault();
        document.getElementById('progress-container').style.display = 'block';
        document.getElementById('log').innerHTML = '';
        document.getElementById('progress-bar').style.width = '0%';
        document.getElementById('progress-bar').innerText = '0%';

        const formData = new FormData(this);

        fetch('copy.php', {
            method: 'POST',
            body: formData
          })
          .then(response => response.body.getReader())
          .then(reader => {
            const decoder = new TextDecoder();

            function read() {
              reader.read().then(({
                done,
                value
              }) => {
                if (done) return;
                let text = decoder.decode(value);
                let jsons = text.trim().split("\n");
                jsons.forEach(j => {
                  if (!j.trim()) return;
                  try {
                    let data = JSON.parse(j);
                    if (data.progress) {
                      let bar = document.getElementById('progress-bar');
                      bar.style.width = data.progress + '%';
                      bar.innerText = data.progress + '%';
                    }
                    if (data.message) {
                      let log = document.getElementById('log');
                      log.innerHTML += data.message + '<br>';
                      log.scrollTop = log.scrollHeight;
                    }
                  } catch (err) {
                    console.error("Parse error", err, j);
                  }
                });
                read();
              });
            }
            read();
          });
      });
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
  <title>DB Migration Aracı</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>

<body class="container py-4">
  <h2 class="mb-4">Kaynak Veritabanı Bağlantısı</h2>
  <form method="post">
    <input type="hidden" name="step" value="1">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Host</label>
        <input type="text" class="form-control" name="src_host" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Kullanıcı</label>
        <input type="text" class="form-control" name="src_user" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Şifre</label>
        <input type="password" class="form-control" name="src_pass">
      </div>
      <div class="col-md-6">
        <label class="form-label">Veritabanı Adı</label>
        <input type="text" class="form-control" name="src_db" required>
      </div>
      <div class="col-md-6">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="db_settings_equal" value="1" id="db_settings_equal">
          <label class="form-check-label" for="db_settings_equal">
            Hedef ve kaynak veritabanı ayarları aynı
          </label>
        </div>
      </div>
      <div class="col-md-6">
        <div class="form-label">Hedef veritabanı son eki (prefix)</div>
        <div class="d-flex justify-content-between align-items-center">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="dest_db_prefix_date" value="1" id="dest_db_prefix_date">
            <label class="form-check-label" for="dest_db_prefix_date">Tarih <span class="text-muted">(YYmmdd)</span></label>
          </div>
          <div class="d-flex align-items-center">
            <div> Özel:</div><input class="form-control d-inline" type="text" name="dest_db_prefix" id="dest_db_prefix" maxlength="10" value="">
          </div>
        </div>
      </div>
    </div>

    <div class="mt-4">
      <button type="submit" class="btn btn-success">Bağlan</button>
    </div>
  </form>
</body>

</html>