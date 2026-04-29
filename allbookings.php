<?php
// allbookings.php – Display ALL cab bookings with fare details
// Fixed: proper SQL injection prevention, correct purpose (shows all, not by route)

$allowed_types = ['Auto', 'Mini', 'Sedan', 'SUV'];
$filter = trim($_GET['filter'] ?? '');
if (!in_array($filter, $allowed_types)) $filter = ''; // whitelist only

$con = new mysqli('localhost', 'root', '', 'cabdb');
$rows = [];
$stats = ['cnt'=>0,'total'=>0,'avg'=>0,'avgrate'=>0];

function ensureVehicleNoColumn($con) {
  $check = $con->query("SHOW COLUMNS FROM cab_bookings LIKE 'vehicle_no'");
  if ($check && $check->num_rows === 0) {
    $con->query("ALTER TABLE cab_bookings ADD COLUMN vehicle_no VARCHAR(20) NOT NULL DEFAULT 'KL-00-AA-0000' AFTER cab_type");
  }
}

if (!$con->connect_error) {
    // Auto-create table (safety net for fresh installs)
    $con->query("CREATE TABLE IF NOT EXISTS cab_bookings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pname VARCHAR(100) NOT NULL, phone VARCHAR(15) NOT NULL,
        source VARCHAR(100) NOT NULL, dest VARCHAR(100) NOT NULL,
        distance FLOAT NOT NULL, cab_type VARCHAR(20) NOT NULL,
    rate_per_km INT NOT NULL, vehicle_no VARCHAR(20) NOT NULL,
    total_fare FLOAT NOT NULL,
        booked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
  ensureVehicleNoColumn($con);

    // Fetch filtered rows — cab_type comes from a whitelist so safe to interpolate
    // but use prepared stmt anyway for consistency
    if ($filter) {
        $stmt = $con->prepare("SELECT * FROM cab_bookings WHERE cab_type=? ORDER BY booked_at DESC");
        $stmt->bind_param('s', $filter);
    } else {
        $stmt = $con->prepare("SELECT * FROM cab_bookings ORDER BY booked_at DESC");
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $stmt->close();

    // Global stats (always across all bookings, ignoring filter)
    $s = $con->query("SELECT COUNT(*) as cnt, COALESCE(SUM(total_fare),0) as total,
                             COALESCE(AVG(total_fare),0) as avg, COALESCE(AVG(rate_per_km),0) as avgrate
                      FROM cab_bookings")->fetch_assoc();
    $stats = $s;
    $con->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>All Bookings – RideEase</title>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet"/>
  <style>
    :root{--bg:#0a0a0f;--card:#13131c;--border:#222234;--accent:#f5c542;--accent2:#ff6b35;--text:#f0f0f0;--muted:#7a7a9a;}
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}
    nav{display:flex;align-items:center;justify-content:space-between;padding:1.2rem 2.5rem;border-bottom:1px solid var(--border);background:rgba(10,10,15,.92);backdrop-filter:blur(12px);}
    .logo{font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:var(--accent);text-decoration:none;}
    .logo span{color:var(--accent2);}
    nav a{color:var(--muted);text-decoration:none;font-size:.88rem;margin-left:1.5rem;}
    nav a:hover{color:var(--text);}
    .page{max-width:1100px;margin:3rem auto;padding:0 1.5rem 4rem;}
    h1{font-family:'Syne',sans-serif;font-size:2rem;font-weight:800;margin-bottom:.5rem;}
    h1 span{color:var(--accent);}
    .subtitle{color:var(--muted);font-size:.88rem;margin-bottom:2rem;}
    .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:2.5rem;}
    .stat{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:1.2rem 1.5rem;}
    .stat .n{font-family:'Syne',sans-serif;font-size:1.8rem;font-weight:800;color:var(--accent);}
    .stat .l{font-size:.75rem;color:var(--muted);margin-top:.3rem;}
    .filters{display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1.5rem;}
    .filters a{padding:.45rem 1.1rem;border-radius:6px;font-size:.82rem;text-decoration:none;border:1px solid var(--border);color:var(--muted);transition:all .2s;}
    .filters a:hover,.filters a.active{border-color:var(--accent);color:var(--accent);background:rgba(245,197,66,.06);}
    .table-wrap{overflow-x:auto;background:var(--card);border:1px solid var(--border);border-radius:14px;}
    table{width:100%;border-collapse:collapse;}
    thead{background:rgba(255,255,255,.02);}
    th{padding:.8rem 1rem;text-align:left;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);white-space:nowrap;}
    td{padding:.85rem 1rem;font-size:.88rem;border-bottom:1px solid rgba(34,34,52,.6);}
    tr:last-child td{border-bottom:none;}
    tr:hover td{background:rgba(255,255,255,.015);}
    .badge{display:inline-block;padding:.2rem .65rem;border-radius:4px;font-size:.75rem;font-weight:600;}
    .badge-auto{background:rgba(61,220,132,.15);color:#3ddc84;}
    .badge-mini{background:rgba(100,160,255,.15);color:#64a0ff;}
    .badge-sedan{background:rgba(245,197,66,.15);color:#f5c542;}
    .badge-suv{background:rgba(255,107,53,.15);color:#ff6b35;}
    .route{display:flex;align-items:center;gap:.4rem;font-size:.85rem;}
    .route .arr{color:var(--muted);}
    .empty{text-align:center;padding:5rem 2rem;color:var(--muted);}
    .empty .e{font-size:3rem;margin-bottom:1rem;}
    .book-btn{display:inline-block;background:var(--accent);color:#000;padding:.6rem 1.4rem;border-radius:8px;font-weight:700;text-decoration:none;font-size:.88rem;}
    .book-btn:hover{background:#ffd35a;}
    .filter-note{color:var(--muted);font-size:.8rem;margin-bottom:.8rem;}
  </style>
</head>
<body>
<nav>
  <a href="index.html" class="logo">Ride<span>Ease</span></a>
  <div>
    <a href="index.html">Home</a>
    <a href="book.html">Book a Cab</a>
    <a href="search.html">Search Route</a>
  </div>
</nav>

<div class="page">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1rem;">
    <div>
      <h1>All <span>Bookings</span></h1>
      <p class="subtitle">Complete fare records for every cab ride (all time).</p>
    </div>
    <a href="book.html" class="book-btn">+ New Booking</a>
  </div>

  <!-- Global stats (always all bookings) -->
  <div class="stats">
    <div class="stat"><div class="n"><?= intval($stats['cnt']) ?></div><div class="l">Total Rides</div></div>
    <div class="stat"><div class="n">₹<?= number_format($stats['total'],0) ?></div><div class="l">Total Revenue</div></div>
    <div class="stat"><div class="n">₹<?= number_format($stats['avg'],0) ?></div><div class="l">Avg Fare / Ride</div></div>
    <div class="stat"><div class="n">₹<?= number_format($stats['avgrate'],1) ?></div><div class="l">Avg Rate / km</div></div>
  </div>

  <!-- Filter bar -->
  <div class="filters">
    <a href="allbookings.php" class="<?= !$filter?'active':'' ?>">All Types</a>
    <a href="?filter=Auto"  class="<?= $filter==='Auto' ?'active':'' ?>">🛺 Auto</a>
    <a href="?filter=Mini"  class="<?= $filter==='Mini' ?'active':'' ?>">🚗 Mini</a>
    <a href="?filter=Sedan" class="<?= $filter==='Sedan'?'active':'' ?>">🚕 Sedan</a>
    <a href="?filter=SUV"   class="<?= $filter==='SUV'  ?'active':'' ?>">🚙 SUV</a>
  </div>
  <?php if ($filter): ?>
    <p class="filter-note">Showing <?= count($rows) ?> record(s) for cab type: <strong><?= htmlspecialchars($filter) ?></strong></p>
  <?php endif; ?>

  <!-- Table -->
  <?php if (count($rows) > 0): ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>ID</th><th>Passenger</th><th>Phone</th><th>Route</th>
          <th>Cab</th><th>Vehicle No</th><th>Distance</th><th>Rate/km</th><th>Total Fare</th><th>Booked At</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): $cls = strtolower($r['cab_type']); ?>
        <tr>
          <td style="color:var(--muted);font-size:.78rem;">#<?= $r['id'] ?></td>
          <td><?= htmlspecialchars($r['pname']) ?></td>
          <td style="color:var(--muted)"><?= htmlspecialchars($r['phone']) ?></td>
          <td>
            <div class="route">
              <?= htmlspecialchars($r['source']) ?><span class="arr">→</span><?= htmlspecialchars($r['dest']) ?>
            </div>
          </td>
          <td><span class="badge badge-<?= $cls ?>"><?= htmlspecialchars($r['cab_type']) ?></span></td>
          <td style="color:var(--accent);font-weight:600;letter-spacing:.5px;white-space:nowrap;"><?= htmlspecialchars($r['vehicle_no'] ?? 'KL-00-AA-0000') ?></td>
          <td><?= $r['distance'] ?> km</td>
          <td style="color:var(--muted)">₹<?= $r['rate_per_km'] ?></td>
          <td style="color:var(--accent);font-weight:700;font-family:'Syne',sans-serif;">₹<?= number_format($r['total_fare'],0) ?></td>
          <td style="color:var(--muted);font-size:.75rem;"><?= date('d M Y, h:i A', strtotime($r['booked_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php else: ?>
  <div class="empty">
    <div class="e">📋</div>
    <p>No bookings yet<?= $filter ? " for $filter cabs" : '' ?>.</p>
    <p style="margin-top:.5rem;font-size:.82rem;"><a href="book.html" style="color:var(--accent)">Book your first ride →</a></p>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
