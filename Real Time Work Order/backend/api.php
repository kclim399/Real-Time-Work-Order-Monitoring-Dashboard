<?php
// backend/api.php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

require_once 'config.php';
$pdo = getDBConnection();

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($action == 'get_work_orders') {
        $stmt = $pdo->query("SELECT wo_number as id, target_qty as qty, created_at, 
            (SELECT COUNT(DISTINCT sn) FROM transactions t1 WHERE t1.wo_id = work_orders.id AND 
                (t1.result = 'FAIL' OR t1.station_id = (SELECT id FROM stations WHERE station_name='FIN#CS-DUST_PLUG'))
            ) as produced,
            'Running' as status 
            FROM work_orders ORDER BY created_at DESC, id DESC");
        echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    
    elseif ($action == 'get_monitor_data') {
        $wo = $_GET['wo'] ?? '';
        
        // NEW LOGIC: Use __DIR__ to guarantee the file saves exactly in the /backend/ folder!
        // We default to 'false' so that if the JS stops actively confirming it is monitoring, the lights turn OFF safely.
        $txt_file = __DIR__ . '/active_dashboard_wo.txt';
        $active_tab = $_GET['active_tab'] ?? 'false'; 
        
        if ($active_tab === 'true' || $active_tab === '1') {
            @file_put_contents($txt_file, $wo);
        } else {
            @file_put_contents($txt_file, '');
        }
        
        $stmtTarget = $pdo->prepare("SELECT id, target_qty FROM work_orders WHERE wo_number = ?");
        $stmtTarget->execute([$wo]);
        $woData = $stmtTarget->fetch(PDO::FETCH_ASSOC);
        
        if (!$woData) throw new Exception("WO not found");
        $wo_id = $woData['id'];

        // Get Passed
        $stmtPass = $pdo->prepare("SELECT COUNT(DISTINCT t.sn) FROM transactions t JOIN stations s ON t.station_id = s.id WHERE t.wo_id = ? AND s.station_name = 'FIN#CS-DUST_PLUG' AND t.result = 'PASS'");
        $stmtPass->execute([$wo_id]);
        $passed = (int)$stmtPass->fetchColumn();

        // Get Failed
        $stmtFail = $pdo->prepare("SELECT COUNT(DISTINCT sn) FROM transactions WHERE wo_id = ? AND result = 'FAIL'");
        $stmtFail->execute([$wo_id]);
        $failed = (int)$stmtFail->fetchColumn();

        // Safely fetch defects
        $stmtDefects = $pdo->prepare("
            SELECT d.defect_name, COUNT(t.id) as defect_count 
            FROM transactions t
            JOIN defects d ON t.defect_id = d.id
            WHERE t.wo_id = ? AND t.result = 'FAIL'
            GROUP BY d.id, d.defect_name 
            ORDER BY defect_count DESC
        ");
        $stmtDefects->execute([$wo_id]);
        $defects = $stmtDefects->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["status" => "success", "data" => [
            "counters" => [
                'target' => (int)$woData['target_qty'], 
                'total' => ($passed + $failed), 
                'passed' => $passed, 
                'failed' => $failed
            ],
            "defects" => $defects
        ]]);
        exit;
    }
    
    elseif ($action == 'get_analytics_data') {
        $wo = $_GET['wo'] ?? '';
        
        // Fetch ALL raw transactions securely. The JS will do the advanced math.
        $stmtRaw = $pdo->prepare("
            SELECT t.*, s.station_name, e.emp_name 
            FROM transactions t 
            JOIN stations s ON t.station_id = s.id 
            JOIN employees e ON t.emp_id = e.id
            JOIN work_orders w ON t.wo_id = w.id 
            WHERE w.wo_number = ? 
            ORDER BY t.date_time ASC
        ");
        $stmtRaw->execute([$wo]);

        echo json_encode(["status" => "success", "data" => [
            "raw_transactions" => $stmtRaw->fetchAll(PDO::FETCH_ASSOC)
        ]]);
        exit;
    }
    
    // NEW ENDPOINT: Explicitly kill the hardware lights instantly when leaving the page
    elseif ($action == 'clear_active_wo') {
        @file_put_contents(__DIR__ . '/active_dashboard_wo.txt', '');
        echo json_encode(["status" => "success"]);
        exit;
    }
    
    else {
        echo json_encode(["status" => "error", "message" => "Invalid Action"]);
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>