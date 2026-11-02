<?php
// backend/andon_api.php
require_once 'config.php';
$pdo = getDBConnection();

try {
    // 1. Read the active Work Order ID saved by the Dashboard's Real-Time Monitor
    // The @ suppresses warnings if the file hasn't been created yet
    $active_wo_id = @file_get_contents('active_dashboard_wo.txt');
    
    if (!$active_wo_id) { 
        echo "OFF"; 
        exit; 
    }

    // 2. Safely get the internal WO ID and Target Qty (Handles if txt file stores string 'FIN25...' or numeric ID)
    $stmtWO = $pdo->prepare("SELECT id, target_qty FROM work_orders WHERE id = ? OR wo_number = ? LIMIT 1");
    $stmtWO->execute([$active_wo_id, $active_wo_id]);
    $woData = $stmtWO->fetch(PDO::FETCH_ASSOC);

    if (!$woData) {
        echo "OFF";
        exit;
    }

    $wo_id = $woData['id'];
    $target_qty = (int)$woData['target_qty'];

    // 3. Count ACTUAL Passed units (Distinct SNs that reached DUST_PLUG with PASS)
    $stmtPass = $pdo->prepare("SELECT COUNT(DISTINCT t.sn) FROM transactions t JOIN stations s ON t.station_id = s.id WHERE t.wo_id = ? AND s.station_name = 'FIN#CS-DUST_PLUG' AND t.result = 'PASS'");
    $stmtPass->execute([$wo_id]);
    $pass = (int)$stmtPass->fetchColumn();

    // 4. Count ACTUAL Failed units (Distinct SNs that FAILED anywhere)
    $stmtFail = $pdo->prepare("SELECT COUNT(DISTINCT sn) FROM transactions WHERE wo_id = ? AND result = 'FAIL'");
    $stmtFail->execute([$wo_id]);
    $fail = (int)$stmtFail->fetchColumn();

    $total = $pass + $fail;

    // 5. If no terminal units have finished the line yet, default to Green (Ready)
    if ($total == 0) { 
        echo "GREEN"; 
        exit; 
    }

    // 6. Calculate accurate Terminal Yield % and Allowed Fails
    $yield = ($pass / $total) * 100;
    $targetYield = 99.5;
    $maxAllowedFails = floor($target_qty * ((100 - $targetYield) / 100));

    // 7. Output the command for the physical light tower based on Smart Logic
    if ($fail > $maxAllowedFails) {
        // CRITICAL RED: Allowed failures exceeded.
        echo "RED";
    } elseif ($yield < $targetYield && $fail > 0) {
        // WARNING YELLOW: Underperforming, but theoretically recoverable.
        echo "YELLOW";
    } else {
        // GOOD GREEN: Yield is on track.
        echo "GREEN";
    }

} catch (Exception $e) {
    echo "ERROR";
}
?>