<?php
/**
 * Validation Script for Traffic Source Auto-Detect Migration
 * 
 * This script validates that the migration was successful and checks
 * backward compatibility.
 * 
 * Run this after migration 033 to verify data integrity.
 */

require_once __DIR__ . '/../../config/config.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);

if ($db->connect_error) {
    die("Connection failed: " . $db->connect_error);
}

echo "=== Traffic Source Auto-Detect Migration Validation ===\n\n";

// 1. Check campaigns table structure
echo "1. Checking campaigns table structure...\n";
$result = $db->query("SHOW COLUMNS FROM campaigns WHERE Field = 'traffic_source_id'");
$column = $result->fetch_assoc();
if ($column && $column['Null'] === 'YES') {
    echo "   ✓ campaigns.traffic_source_id allows NULL\n";
} else {
    echo "   ✗ ERROR: campaigns.traffic_source_id does NOT allow NULL\n";
    exit(1);
}

// 2. Check clicks table structure
echo "\n2. Checking clicks table structure...\n";
$result = $db->query("SHOW COLUMNS FROM clicks WHERE Field = 'traffic_source_id'");
$column = $result->fetch_assoc();
if ($column) {
    echo "   ✓ clicks.traffic_source_id column exists\n";
    if ($column['Null'] === 'YES') {
        echo "   ✓ clicks.traffic_source_id allows NULL\n";
    }
} else {
    echo "   ✗ ERROR: clicks.traffic_source_id column does NOT exist\n";
    exit(1);
}

// 3. Check index exists
echo "\n3. Checking index...\n";
$result = $db->query("SHOW INDEXES FROM clicks WHERE Key_name = 'idx_clicks_traffic_source'");
if ($result->num_rows > 0) {
    echo "   ✓ idx_clicks_traffic_source index exists\n";
} else {
    echo "   ⚠ WARNING: idx_clicks_traffic_source index does NOT exist\n";
}

// 4. Check foreign key constraint
echo "\n4. Checking foreign key constraint...\n";
$result = $db->query("
    SELECT CONSTRAINT_NAME 
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'clicks' 
    AND COLUMN_NAME = 'traffic_source_id'
    AND REFERENCED_TABLE_NAME IS NOT NULL
");
if ($result->num_rows > 0) {
    echo "   ✓ Foreign key constraint exists\n";
} else {
    echo "   ⚠ WARNING: Foreign key constraint does NOT exist\n";
}

// 5. Check data migration
echo "\n5. Checking data migration...\n";
$result = $db->query("
    SELECT 
        COUNT(*) as total_clicks,
        SUM(CASE WHEN traffic_source_id IS NULL THEN 1 ELSE 0 END) as null_clicks,
        SUM(CASE WHEN traffic_source_id IS NOT NULL THEN 1 ELSE 0 END) as migrated_clicks
    FROM clicks
");
$stats = $result->fetch_assoc();
echo "   Total clicks: " . number_format($stats['total_clicks']) . "\n";
echo "   Clicks with NULL traffic_source_id: " . number_format($stats['null_clicks']) . "\n";
echo "   Clicks with traffic_source_id set: " . number_format($stats['migrated_clicks']) . "\n";

// Check for clicks that should have been migrated
$result = $db->query("
    SELECT COUNT(*) as unmigrated
    FROM clicks c
    INNER JOIN campaigns camp ON c.campaign_id = camp.id
    WHERE c.traffic_source_id IS NULL 
    AND camp.traffic_source_id IS NOT NULL
");
$unmigrated = $result->fetch_assoc()['unmigrated'];
if ($unmigrated == 0) {
    echo "   ✓ All migratable clicks have been migrated\n";
} else {
    echo "   ⚠ WARNING: $unmigrated clicks should have traffic_source_id but don't\n";
    echo "   This is OK if they're from auto-detect campaigns\n";
}

// 6. Check campaigns with NULL traffic_source_id (auto-detect mode)
echo "\n6. Checking auto-detect campaigns...\n";
$result = $db->query("
    SELECT COUNT(*) as auto_detect_count
    FROM campaigns
    WHERE traffic_source_id IS NULL
");
$autoDetectCount = $result->fetch_assoc()['auto_detect_count'];
echo "   Campaigns in auto-detect mode: $autoDetectCount\n";

$result = $db->query("
    SELECT COUNT(*) as auto_detect_clicks
    FROM clicks c
    INNER JOIN campaigns camp ON c.campaign_id = camp.id
    WHERE camp.traffic_source_id IS NULL
");
$autoDetectClicks = $result->fetch_assoc()['auto_detect_clicks'];
echo "   Clicks from auto-detect campaigns: " . number_format($autoDetectClicks) . "\n";

// 7. Check data integrity
echo "\n7. Checking data integrity...\n";
$result = $db->query("
    SELECT COUNT(*) as invalid
    FROM clicks c
    WHERE c.traffic_source_id IS NOT NULL
    AND NOT EXISTS (
        SELECT 1 FROM traffic_sources ts WHERE ts.id = c.traffic_source_id
    )
");
$invalid = $result->fetch_assoc()['invalid'];
if ($invalid == 0) {
    echo "   ✓ All traffic_source_id values reference valid traffic sources\n";
} else {
    echo "   ✗ ERROR: $invalid clicks have invalid traffic_source_id values\n";
    exit(1);
}

// 8. Check for orphaned clicks (clicks without campaigns)
echo "\n8. Checking for orphaned clicks...\n";
$result = $db->query("
    SELECT COUNT(*) as orphaned
    FROM clicks c
    WHERE NOT EXISTS (
        SELECT 1 FROM campaigns camp WHERE camp.id = c.campaign_id
    )
");
$orphaned = $result->fetch_assoc()['orphaned'];
if ($orphaned == 0) {
    echo "   ✓ No orphaned clicks found\n";
} else {
    echo "   ⚠ WARNING: $orphaned clicks reference non-existent campaigns\n";
}

echo "\n=== Validation Complete ===\n";
echo "\nIf all checks passed, your migration was successful!\n";
echo "If warnings appear, review them but they may be acceptable.\n";
echo "If errors appear, investigate and fix before proceeding.\n";

$db->close();
