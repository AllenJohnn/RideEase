<?php
// searchride.php – Search bookings by source and destination
// Fixed: prepared statements (no SQL injection)

$source   = trim($_GET['source']   ?? '');
$dest     = trim($_GET['dest']     ?? '');
$cab_type = trim($_GET['cab_type'] ?? '');

$errors = [];
if (strlen($source) < 2) $errors[] = "Please enter a valid source location.";
if (strlen($dest)   < 2) $errors[] = "Please enter a valid destination.";

$allowed_types = ['', 'Auto', 'Mini', 'Sedan', 'SUV'];
if (!in_array($cab_type, $allowed_types)) $cab_type = '';

$rows  = [];
$stats = null;

require_once __DIR__ . '/cab_booking_helpers.php';

if (empty($errors)) {
    $con = new mysqli('localhost', 'root', '', 'cabdb');
    if (!$con->connect_error) {
  ensureCabBookingTables($con);
        // Build query with optional cab_type filter — using prepared statements
        if ($cab_type) {
      $tableName = cabBookingTableForType($cab_type);
            $stmt = $con->prepare(
        "SELECT * FROM " . cabBookingTableSql($tableName) . "
         WHERE LOWER(source)=LOWER(?) AND LOWER(dest)=LOWER(?)
         ORDER BY booked_at DESC"
            );
            $stmt->bind_param('ss', $source, $dest);
        } else {
      $unionSql = cabBookingUnionSelectSql();
            $stmt = $con->prepare(
        "SELECT * FROM ($unionSql) AS cab_bookings
                 WHERE LOWER(source)=LOWER(?) AND LOWER(dest)=LOWER(?)
                 ORDER BY booked_at DESC"
            );
            $stmt->bind_param('ss', $source, $dest);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $stmt->close();

        // Aggregate stats for this route
        if (count($rows) > 0) {
            $fares     = array_column($rows, 'total_fare');
            $rates     = array_column($rows, 'rate_per_km');
            $stats = [
                'cnt'     => count($rows),
                'total'   => array_sum($fares),
                'avg'     => array_sum($fares) / count($fares),
                'avgrate' => array_sum($rates)  / count($rates),
            ];
        }
        $con->close();
    } else {
        $errors[] = "Database connection failed.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Search Results – RideEase</title>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet"/>
  <style>
    :root{--bg:#0a0a0f;--card:#13131c;--border:#222234;--accent:#f5c542;--accent2:#ff6b35;--text:#f0f0f0;--muted:#7a7a9a;--red:#ff5c5c;}
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}
    nav{display:flex;align-items:center;justify-content:space-between;padding:1.2rem 2.5rem;border-bottom:1px solid var(--border);background:rgba(10,10,15,.92);backdrop-filter:blur(12px);}
    .logo{font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:var(--accent);text-decoration:none;}
    .logo span{color:var(--accent2);}
    nav a{color:var(--muted);text-decoration:none;font-size:.88rem;margin-left:1.5rem;}
    nav a:hover{color:var(--text);}
    .page{max-width:1000px;margin:3rem auto;padding:0 1.5rem 4rem;}
    h1{font-family:'Syne',sans-serif;font-size:1.8rem;font-weight:800;margin-bottom:.5rem;}
    h1 span{color:var(--accent);}
    .subtitle{color:var(--muted);font-size:.88rem;margin-bottom:2rem;}
    .route-badge{display:inline-flex;align-items:center;gap:.5rem;background:var(--card);border:1px solid var(--border);border-radius:8px;padding:.5rem 1rem;font-size:.88rem;margin-bottom:2rem;}
    .route-badge span{color:var(--accent);font-weight:600;}
    .summary{display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:2rem;}
    .sum-box{background:var(--card);border:1px solid var(--border);border-radius:10px;padding:.8rem 1.2rem;}
    .sum-box .n{font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:var(--accent);}
    .sum-box .l{font-size:.75rem;color:var(--muted);}
    .table-wrap{overflow-x:auto;}
    table{width:100%;border-collapse:collapse;}
    thead{background:var(--card);}
    th{padding:.75rem 1rem;text-align:left;font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);white-space:nowrap;}
    td{padding:.8rem 1rem;font-size:.88rem;border-bottom:1px solid var(--border);}
    tr:hover td{background:rgba(255,255,255,.02);}
    .badge{display:inline-block;padding:.2rem .65rem;border-radius:4px;font-size:.75rem;font-weight:600;}
    .badge-auto{background:rgba(61,220,132,.15);color:#3ddc84;}
    .badge-mini{background:rgba(100,160,255,.15);color:#64a0ff;}
    .badge-sedan{background:rgba(245,197,66,.15);color:#f5c542;}
    .badge-suv{background:rgba(255,107,53,.15);color:#ff6b35;}
    .empty{text-align:center;padding:4rem 2rem;color:var(--muted);}
    .empty .e{font-size:3rem;margin-bottom:1rem;}
    .back-btn{display:inline-block;margin-bottom:1.5rem;color:var(--muted);text-decoration:none;font-size:.88rem;}
    .back-btn:hover{color:var(--accent);}
    .error-box{background:rgba(255,92,92,.08);border:1px solid rgba(255,92,92,.3);border-radius:10px;padding:1rem 1.4rem;margin-bottom:1.5rem;}
    .error-box p{font-size:.88rem;color:var(--red);}
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

<div class="page">
  <a href="search.html" class="back-btn">← Back to Search</a>
  <h1>Search <span>Results</span></h1>

  <?php if (!empty($errors)): ?>
    <div class="error-box">
      <?php foreach ($errors as $e): ?>
        <p>✕ &nbsp;<?= htmlspecialchars($e) ?></p>
      <?php endforeach; ?>
    </div>
    <a href="search.html" style="color:var(--accent);font-size:.9rem;">← Try again</a>

  <?php else: ?>

  <div class="route-badge">
    <span><?= htmlspecialchars(ucfirst($source)) ?></span>
    →
    <span><?= htmlspecialchars(ucfirst($dest)) ?></span>
    <?php if ($cab_type): ?>· <span><?= htmlspecialchars($cab_type) ?></span><?php endif; ?>
  </div>

  <?php if ($stats): ?>
  <div class="summary">
    <div class="sum-box"><div class="n"><?= $stats['cnt'] ?></div><div class="l">Rides Found</div></div>
    <div class="sum-box"><div class="n">₹<?= number_format($stats['total'],0) ?></div><div class="l">Total Revenue</div></div>
    <div class="sum-box"><div class="n">₹<?= number_format($stats['avg'],0) ?></div><div class="l">Avg Fare</div></div>
    <div class="sum-box"><div class="n">₹<?= number_format($stats['avgrate'],1) ?></div><div class="l">Avg Rate/km</div></div>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th><th>Passenger</th><th>Phone</th><th>Cab</th>
          <th>Vehicle No</th><th>Distance</th><th>Rate/km</th><th>Fare</th><th>Booked At</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): $cls = strtolower($r['cab_type']); ?>
        <tr>
          <td style="color:var(--muted)"><?= $r['id'] ?></td>
          <td><?= htmlspecialchars($r['pname']) ?></td>
          <td style="color:var(--muted)"><?= htmlspecialchars($r['phone']) ?></td>
          <td><span class="badge badge-<?= $cls ?>"><?= htmlspecialchars($r['cab_type']) ?></span></td>
          <td style="color:var(--accent);font-weight:600;letter-spacing:.5px;white-space:nowrap;"><?= htmlspecialchars($r['vehicle_no'] ?? 'KL-00-AA-0000') ?></td>
          <td><?= $r['distance'] ?> km</td>
          <td>₹<?= $r['rate_per_km'] ?></td>
          <td style="color:var(--accent);font-weight:600;">₹<?= number_format($r['total_fare'],0) ?></td>
          <td style="color:var(--muted);font-size:.78rem;"><?= date('d M Y, h:i A', strtotime($r['booked_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php else: ?>
  <div class="empty">
    <div class="e">🔍</div>
    <p>No bookings found for <strong><?= htmlspecialchars($source) ?> → <?= htmlspecialchars($dest) ?></strong></p>
    <p style="margin-top:.5rem;font-size:.82rem;">
      Try a different route or <a href="book.html" style="color:var(--accent)">book a new ride</a>.
    </p>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
