<?php

function cabBookingTableMap() {
    return [
        'Auto'  => 'cab_bookings_auto',
        'Mini'  => 'cab_bookings_mini',
        'Sedan' => 'cab_bookings_sedan',
        'SUV'   => 'cab_bookings_suv',
    ];
}

function cabBookingTableForType($cab_type) {
    $map = cabBookingTableMap();
    return $map[$cab_type] ?? null;
}

function cabBookingTableSql($tableName) {
    return "`" . str_replace("`", "``", $tableName) . "`";
}

function ensureCabBookingTables($con) {
    ensureMainCabBookingTable($con);

    $tables = [
        'cab_bookings_auto' => 'Auto',
        'cab_bookings_mini' => 'Mini',
        'cab_bookings_sedan' => 'Sedan',
        'cab_bookings_suv' => 'SUV',
    ];

    foreach ($tables as $tableName => $cabType) {
        $con->query("CREATE TABLE IF NOT EXISTS " . cabBookingTableSql($tableName) . " (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            pname       VARCHAR(100) NOT NULL,
            phone       VARCHAR(15)  NOT NULL,
            source      VARCHAR(100) NOT NULL,
            dest        VARCHAR(100) NOT NULL,
            distance    FLOAT        NOT NULL,
            cab_type    VARCHAR(20)  NOT NULL DEFAULT '" . $con->real_escape_string($cabType) . "',
            vehicle_no  VARCHAR(20)  NOT NULL,
            rate_per_km INT          NOT NULL,
            total_fare  FLOAT        NOT NULL,
            booked_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    }
}

function ensureMainCabBookingTable($con) {
    $con->query("CREATE TABLE IF NOT EXISTS `cab_bookings` (
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
}

function cabBookingUnionSelectSql() {
    $parts = [];
    foreach (cabBookingTableMap() as $tableName) {
        $parts[] = "SELECT * FROM " . cabBookingTableSql($tableName);
    }

    return implode(" UNION ALL ", $parts);
}
