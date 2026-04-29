<?php
// bookride.php – Inserts a new cab booking into MySQL
// Fixed: prepared statements, server-side validation, consistent fare

// ── DB config ──────────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');        // Change to your MySQL password if set
define('DB_NAME', 'cabdb');

// ── Collect & sanitise inputs ──────────────────────────────────────────────
$pname       = trim($_POST['pname']       ?? '');
$phone       = trim($_POST['phone']       ?? '');
$source      = trim($_POST['source']      ?? '');
$dest        = trim($_POST['dest']        ?? '');
$distance    = trim($_POST['distance']    ?? '');
$cab_type    = trim($_POST['cab_type']    ?? '');
$rate_per_km = intval($_POST['rate_per_km'] ?? 0); // sent from JS hidden field

// ── Server-side validation ─────────────────────────────────────────────────
$errors = [];

if (strlen($pname) < 2)                         $errors[] = "Passenger name must be at least 2 characters.";
if (!preg_match('/^\d{10}$/', $phone))           $errors[] = "Phone must be exactly 10 digits (numbers only).";
if (strlen($source) < 2)                         $errors[] = "Pickup location is required.";
if (strlen($dest)   < 2)                         $errors[] = "Drop location is required.";
if (strtolower($source) === strtolower($dest))   $errors[] = "Pickup and drop cannot be the same place.";
if (!is_numeric($distance) || $distance < 1 || $distance > 5000)
                                                 $errors[] = "Distance must be between 1 and 5000 km.";

$allowed_types = ['Auto', 'Mini', 'Sedan', 'SUV'];
if (!in_array($cab_type, $allowed_types))        $errors[] = "Invalid cab type selected.";

// ── Rate ranges (server is the source of truth) ───────────────────────────
$rates = [
    'Auto'  => ['min' => 8,  'max' => 12],
    'Mini'  => ['min' => 12, 'max' => 18],
    'Sedan' => ['min' => 18, 'max' => 25],
    'SUV'   => ['min' => 25, 'max' => 35],
];

// Validate the rate the client sent is within the allowed band.
// If it's within range it means the JS preview was honest — keep it
// so the user gets exactly what the preview showed.
// If tampered or out of range, regenerate on server.
if (in_array($cab_type, $allowed_types)) {
    $min = $rates[$cab_type]['min'];
    $max = $rates[$cab_type]['max'];
    if ($rate_per_km < $min || $rate_per_km > $max) {
        $rate_per_km = rand($min, $max); // regenerate only if tampered
    }
} else {
    $rate_per_km = 0;
}

$distance = (float)$distance;
$fare     = round($distance * $rate_per_km, 2);

function generateVehicleNo() {
  $districtCodes = ['01','02','03','04','05','06','07','08','09','10','11','12','13','14','15'];
  $letters = chr(rand(65, 90)) . chr(rand(65, 90));
  $district = $districtCodes[array_rand($districtCodes)];
  $number = rand(1000, 9999);
  return sprintf('KL-%s-%s-%04d', $district, $letters, $number);
}

function ensureVehicleNoColumn($con) {
  $check = $con->query("SHOW COLUMNS FROM cab_bookings LIKE 'vehicle_no'");
  if ($check && $check->num_rows === 0) {
    $con->query("ALTER TABLE cab_bookings ADD COLUMN vehicle_no VARCHAR(20) NOT NULL DEFAULT 'KL-00-AA-0000' AFTER cab_type");
  }
}

// ── If validation errors, show error page immediately ─────────────────────
if (!empty($errors)) {
    renderPage(false, null, $errors, compact('pname','phone','source','dest','distance','cab_type','rate_per_km','fare'));
    exit;
}

// ── DB connection ──────────────────────────────────────────────────────────
$con = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($con->connect_error) {
    renderPage(false, null, ["Database connection failed. Please try again later."], compact('pname','phone','source','dest','distance','cab_type','rate_per_km','fare'));
    exit;
}

// ── Create table if not exists ─────────────────────────────────────────────
$con->query("CREATE TABLE IF NOT EXISTS cab_bookings (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    pname       VARCHAR(100) NOT NULL,
    phone       VARCHAR(15)  NOT NULL,
    source      VARCHAR(100) NOT NULL,
    dest        VARCHAR(100) NOT NULL,
    distance    FLOAT        NOT NULL,
    cab_type    VARCHAR(20)  NOT NULL,
    vehicle_no  VARCHAR(20)  NOT NULL,
    rate_per_km INT          NOT NULL,
    total_fare  FLOAT        NOT NULL,
    booked_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)");
  ensureVehicleNoColumn($con);

  $vehicle_no = generateVehicleNo();

// ── Prepared statement insert (prevents SQL injection) ─────────────────────
$stmt = $con->prepare(
    "INSERT INTO cab_bookings (pname, phone, source, dest, distance, cab_type, rate_per_km, vehicle_no, total_fare)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
);
  $stmt->bind_param('ssssdsisd', $pname, $phone, $source, $dest, $distance, $cab_type, $rate_per_km, $vehicle_no, $fare);
$ok = $stmt->execute();
$booking_id = $con->insert_id;
$stmt->close();
$con->close();

  renderPage($ok, $booking_id, [], compact('pname','phone','source','dest','distance','cab_type','rate_per_km','vehicle_no','fare'));

// ── HTML renderer ──────────────────────────────────────────────────────────
function renderPage($success, $booking_id, $errors, $d) {
    extract($d);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= $success ? 'Booking Confirmed' : 'Booking Issue' ?> – RideEase</title>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet"/>
  <style>
    :root{--bg:#0a0a0f;--card:#13131c;--border:#222234;--accent:#f5c542;--accent2:#ff6b35;--text:#f0f0f0;--muted:#7a7a9a;--green:#3ddc84;--red:#ff5c5c;}
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem;}
    nav{position:fixed;top:0;left:0;right:0;display:flex;align-items:center;justify-content:space-between;padding:1.2rem 2.5rem;border-bottom:1px solid var(--border);background:rgba(10,10,15,.95);backdrop-filter:blur(12px);z-index:99;}
    .logo{font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:var(--accent);text-decoration:none;}
    .logo span{color:var(--accent2);}
    nav a{color:var(--muted);text-decoration:none;font-size:.88rem;margin-left:1.5rem;}
    nav a:hover{color:var(--text);}
    .card{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:2.5rem;max-width:480px;width:100%;text-align:center;margin-top:5rem;}
    .icon{font-size:3.5rem;margin-bottom:1rem;}
    h1{font-family:'Syne',sans-serif;font-size:1.8rem;font-weight:800;}
    .fare-box{background:var(--bg);border-radius:12px;padding:1.5rem;margin:1.8rem 0;}
    .fare-label{font-size:.78rem;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;}
    .fare-amt{font-family:'Syne',sans-serif;font-size:3rem;font-weight:800;color:var(--accent);}
    .fare-detail{font-size:.82rem;color:var(--muted);margin-top:.4rem;}
    .plate{display:inline-block;background:rgba(245,197,66,.08);border:1px solid rgba(245,197,66,.25);color:var(--accent);border-radius:999px;padding:.35rem .75rem;font-size:.82rem;font-weight:700;letter-spacing:.8px;margin-top:.8rem;}
    table{width:100%;border-collapse:collapse;text-align:left;margin-bottom:1.5rem;}
    td{padding:.5rem .6rem;font-size:.88rem;border-bottom:1px solid var(--border);}
    td:first-child{color:var(--muted);width:45%;}
    .btns{display:flex;gap:1rem;justify-content:center;flex-wrap:wrap;}
    .btn{padding:.65rem 1.4rem;border-radius:8px;font-weight:600;text-decoration:none;font-size:.88rem;}
    .btn-y{background:var(--accent);color:#000;}
    .btn-y:hover{background:#ffd35a;}
    .btn-o{background:transparent;border:1px solid var(--border);color:var(--text);}
    .btn-o:hover{border-color:var(--accent);color:var(--accent);}
    .error-list{background:rgba(255,92,92,.08);border:1px solid rgba(255,92,92,.3);border-radius:10px;padding:1rem 1.2rem;margin:1.2rem 0;text-align:left;}
    .error-list li{font-size:.85rem;color:var(--red);padding:.3rem 0;list-style:none;}
    .error-list li::before{content:"✕  ";}
    @keyframes popIn{0%{transform:scale(.88);opacity:0}80%{transform:scale(1.02)}100%{transform:scale(1);opacity:1}}
    .card{animation:popIn .45s ease both;}
  </style>
</head>
<body>
<nav>
  <a href="index.html" class="logo">Ride<span>Ease</span></a>
  <div>
    <a href="index.html">Home</a>
    <a href="book.html">Book a Cab</a>
    <a href="allbookings.php">All Bookings</a>
  </div>
</nav>

<div class="card">
  <?php if (!empty($errors)): ?>
    <div class="icon">⚠️</div>
    <h1 style="color:var(--red)">Fix These Issues</h1>
    <ul class="error-list">
      <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
      <?php endforeach; ?>
    </ul>
    <div class="btns">
      <a href="javascript:history.back()" class="btn btn-y">← Go Back & Fix</a>
    </div>

  <?php elseif ($success): ?>
    <div class="icon">✅</div>
    <h1>Booking <span style="color:var(--green)">Confirmed!</span></h1>
    <p style="color:var(--muted);font-size:.85rem;margin-top:.4rem;">Booking ID: #<?= $booking_id ?></p>

    <div class="fare-box">
      <div class="fare-label">Total Fare</div>
      <div class="fare-amt">₹<?= number_format($fare, 0) ?></div>
      <div class="fare-detail"><?= $distance ?> km × ₹<?= $rate_per_km ?>/km (<?= htmlspecialchars($cab_type) ?>)</div>
      <div class="plate"><?= htmlspecialchars($vehicle_no) ?></div>
    </div>

    <table>
      <tr><td>Passenger</td><td><?= htmlspecialchars($pname) ?></td></tr>
      <tr><td>Phone</td><td><?= htmlspecialchars($phone) ?></td></tr>
      <tr><td>From</td><td><?= htmlspecialchars($source) ?></td></tr>
      <tr><td>To</td><td><?= htmlspecialchars($dest) ?></td></tr>
      <tr><td>Cab Type</td><td><?= htmlspecialchars($cab_type) ?></td></tr>
      <tr><td>Vehicle No</td><td><?= htmlspecialchars($vehicle_no) ?></td></tr>
      <tr><td>Distance</td><td><?= $distance ?> km</td></tr>
      <tr><td>Rate</td><td>₹<?= $rate_per_km ?>/km</td></tr>
    </table>

    <div class="btns">
      <a href="book.html" class="btn btn-y">Book Another</a>
      <a href="allbookings.php" class="btn btn-o">All Bookings</a>
    </div>

  <?php else: ?>
    <div class="icon">❌</div>
    <h1 style="color:var(--red)">Save Failed</h1>
    <p style="color:var(--muted);margin-top:1rem;font-size:.9rem;">Could not save your booking. Please check your DB connection and try again.</p>
    <div class="btns" style="margin-top:1.5rem;">
      <a href="javascript:history.back()" class="btn btn-y">← Try Again</a>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
<?php } ?>
