<?php
// backend/inserter.php
require_once 'config.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

$defect_pool = [
    "DAMAGE COMPONENT", "EPOXY VOID/HOLE", "EPOXY INSUFFICIENT", "EXCESS EPOXY",
    "CRACK COMPONENT", "INCOMPLETE SWAGING/SWAGING MARK", "INSUFF/MISSING EMI PASTE I&J",
    "SHELL/COVER BLISTER/SHELL BEND/GAP/EXPOSE", "MISSING EMI PLATE", "EMI PLATE BEND",
    "LABEL ILLEGIBLE", "PLASTIC PORT CHIPPING", "ROUTER OVERCUT",
    "GOLD FINGER SCRATCHES/CONTAMINATION", "SILICON PAD DAMAGE/MISSING",
    "MISSING COMPONENT", "THERMAL PAD DISLOGED/MISPLACED", "COMPONENT OVERHANG",
    "FOREIGN MATERIAL"
];

// STRICT STATION SEQUENCE ROUTING
$station_sequence = [
    'FIN#CS-ENTRY',
    'FIN#CS-INSPECTION_ASSEMBLY',
    'FIN#CS-IPQC',
    'FIN#CS-SEMI_AUTO_SWAGING',
    'FIN#CS-VMI_SWAGING',
    'FIN#CS-DUST_PLUG'
];

$emp_names = ['RAJESH', 'SARAH', 'AHMAD', 'JOHN', 'MEI LING', 'SIVA', 'INDA.SUSANTI', 'BABU.RANA', 'HOSSAIN.SABUJ'];

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $pdo = getDBConnection();

    if ($_GET['action'] == 'reset') {
        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
            $pdo->exec("TRUNCATE TABLE transactions;");
            $pdo->exec("TRUNCATE TABLE work_orders;"); 
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
            $pdo->exec("INSERT INTO work_orders (wo_number, target_qty) VALUES ('7654321', 2000)");
            echo json_encode(["status" => "success", "message" => "Database cleared. Default WO (7654321) created."]);
        } catch (Exception $e) { echo json_encode(["status" => "error", "message" => $e->getMessage()]); }
        exit;
    }

    // --- NEW FEATURE: DELETE MULTIPLE SPECIFIC WORK ORDERS ---
    if ($_GET['action'] == 'delete_wo') {
        $input = json_decode(file_get_contents('php://input'), true);
        $wo_numbers = $input['wo_numbers'] ?? [];

        if (empty($wo_numbers)) {
            echo json_encode(['status' => 'error', 'message' => 'No work orders selected for deletion.']);
            exit;
        }

        try {
            $pdo->beginTransaction();
            $deleted_count = 0;
            
            foreach ($wo_numbers as $wo_number) {
                $stmt = $pdo->prepare("SELECT id FROM work_orders WHERE wo_number = ?");
                $stmt->execute([$wo_number]);
                $wo_id = $stmt->fetchColumn();

                if ($wo_id) {
                    // Delete transactions associated with this WO
                    $stmt1 = $pdo->prepare("DELETE FROM transactions WHERE wo_id = ?");
                    $stmt1->execute([$wo_id]);
                    
                    // Delete the WO itself
                    $stmt2 = $pdo->prepare("DELETE FROM work_orders WHERE id = ?");
                    $stmt2->execute([$wo_id]);
                    
                    $deleted_count++;
                }
            }
            
            $pdo->commit();
            echo json_encode(['status' => 'success', 'message' => "Deleted $deleted_count Work Order(s) and all their transactions."]);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'Database Error: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['action'] == 'get_wos') {
        try {
            $stmt = $pdo->query("SELECT wo_number, target_qty FROM work_orders ORDER BY id DESC");
            echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } catch (Exception $e) { echo json_encode(["status" => "error", "message" => $e->getMessage()]); }
        exit;
    }

    if ($_GET['action'] == 'create_wo') {
        try {
            // Modified to accept custom inputs, or fallback to random generation if blank
            $wo_number = !empty($_GET['wo_number']) ? $_GET['wo_number'] : (string)rand(1000000, 9999999);
            $target_qty = isset($_GET['target_qty']) ? (int)$_GET['target_qty'] : 2000;
            if ($target_qty < 1) $target_qty = 2000;
            
            $created_at = !empty($_GET['created_at']) ? date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $_GET['created_at']))) : date('Y-m-d H:i:s');

            $stmt = $pdo->prepare("INSERT INTO work_orders (wo_number, target_qty, created_at) VALUES (?, ?, ?)");
            $stmt->execute([$wo_number, $target_qty, $created_at]);
            echo json_encode(["status" => "success", "wo_number" => $wo_number, "message" => "Created new WO: $wo_number (Target: $target_qty)"]);
        } catch (Exception $e) { echo json_encode(["status" => "error", "message" => $e->getMessage()]); }
        exit;
    }

    if ($_GET['action'] == 'undo') {
        $input = json_decode(file_get_contents('php://input'), true);
        $wo_number = $input['wo'] ?? '';
        $selected_emp = $input['emp'] ?? '';
        $selected_station = $input['station'] ?? '';

        try {
            $stmt = $pdo->prepare("SELECT t.id, t.sn FROM transactions t
                JOIN work_orders w ON t.wo_id = w.id
                JOIN employees e ON t.emp_id = e.id
                JOIN stations s ON t.station_id = s.id
                WHERE w.wo_number = ? AND e.emp_name = ? AND s.station_name = ?
                AND t.date_time >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                ORDER BY t.date_time DESC LIMIT 1");
            $stmt->execute([$wo_number, $selected_emp, $selected_station]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $del = $pdo->prepare("DELETE FROM transactions WHERE id = ?");
                $del->execute([$row['id']]);
                echo json_encode(["status" => "success", "message" => "UNDONE: Successfully deleted scan for " . $row['sn']]);
            } else {
                throw new Exception("No recent scan found in the last 5 minutes to UNDO.");
            }
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['action'] == 'validate_sn') {
        $input = json_decode(file_get_contents('php://input'), true);
        $wo_number = $input['wo'] ?? '';
        $selected_station = $input['station'] ?? '';
        $sn = $input['sn'] ?? '';

        try {
            $current_idx = array_search($selected_station, $station_sequence);
            if ($current_idx === false) throw new Exception("Invalid Station Configuration.");

            $stmt = $pdo->prepare("SELECT id FROM stations WHERE station_name = ?");
            $stmt->execute([$selected_station]);
            $station_id = $stmt->fetchColumn();

            if ($station_id) {
                $checkDup = $pdo->prepare("SELECT id FROM transactions WHERE sn = ? AND station_id = ?");
                $checkDup->execute([$sn, $station_id]);
                if ($checkDup->fetchColumn()) throw new Exception("ERROR: $sn already processed at $selected_station.");

                $checkDead = $pdo->prepare("SELECT id FROM transactions WHERE sn = ? AND result = 'FAIL'");
                $checkDead->execute([$sn]);
                if ($checkDead->fetchColumn()) throw new Exception("ERROR: $sn FAILED at a previous station (Scrapped).");
            }

            if ($current_idx > 0) {
                $prev_station_name = $station_sequence[$current_idx - 1];
                $checkPrev = $pdo->prepare("SELECT result FROM transactions JOIN stations ON transactions.station_id = stations.id WHERE sn = ? AND station_name = ?");
                $checkPrev->execute([$sn, $prev_station_name]);
                $prev_result = $checkPrev->fetchColumn();

                if (!$prev_result) throw new Exception("ERROR: $sn has not completed $prev_station_name.");
                if ($prev_result !== 'PASS') throw new Exception("ERROR: $sn did not PASS $prev_station_name.");
            }

            echo json_encode(["status" => "success", "message" => "Valid SN"]);
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['action'] == 'insert') {
        $input = json_decode(file_get_contents('php://input'), true);
        $wo_number = isset($input['wo']) ? $input['wo'] : '';
        $qty = isset($input['qty']) ? (int)$input['qty'] : 1;
        $result = isset($input['result']) ? strtoupper($input['result']) : 'PASS';
        $remark = ($result === 'FAIL') ? $input['defect'] : 'NONE';
        $selected_station = isset($input['station']) ? $input['station'] : 'FIN#CS-ENTRY';
        $selected_emp = isset($input['emp']) ? $input['emp'] : 'RAJESH';
        $custom_time = isset($input['datetime']) && !empty($input['datetime']) ? $input['datetime'] : null;
        $isScanned = isset($input['scanned']) && $input['scanned'];
        $provided_sn = isset($input['sn']) ? trim(strtoupper($input['sn'])) : '';
        
        if (empty($wo_number)) { echo json_encode(["status" => "error", "message" => "You must select a Work Order first."]); exit; }
        if ($qty < 1) $qty = 1;

        try {
            $current_idx = array_search($selected_station, $station_sequence);
            if ($current_idx === false) throw new Exception("Invalid Station Routing Configuration.");

            $stmt = $pdo->prepare("SELECT id, target_qty FROM work_orders WHERE wo_number = ?");
            $stmt->execute([$wo_number]);
            $wo_data = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$wo_data) throw new Exception("Work Order not found.");
            $wo_id = $wo_data['id'];
            $target_qty = (int)$wo_data['target_qty'];

            $stmt = $pdo->prepare("SELECT id FROM employees WHERE emp_name = ?");
            $stmt->execute([$selected_emp]);
            $emp_id = $stmt->fetchColumn() ?: ($pdo->prepare("INSERT INTO employees (emp_name) VALUES (?)")->execute([$selected_emp]) ? $pdo->lastInsertId() : null);

            $stmt = $pdo->prepare("SELECT id FROM stations WHERE station_name = ?");
            $stmt->execute([$selected_station]);
            $station_id = $stmt->fetchColumn() ?: ($pdo->prepare("INSERT INTO stations (station_name) VALUES (?)")->execute([$selected_station]) ? $pdo->lastInsertId() : null);

            $stmt = $pdo->prepare("SELECT id FROM defects WHERE defect_name = ?");
            $stmt->execute([$remark]);
            $defect_id = $stmt->fetchColumn() ?: ($pdo->prepare("INSERT INTO defects (defect_name) VALUES (?)")->execute([$remark]) ? $pdo->lastInsertId() : null);

            if ($current_idx === 0) {
                $stmtCheck = $pdo->prepare("SELECT COUNT(DISTINCT sn) FROM transactions WHERE wo_id = ? AND station_id = ?");
                $stmtCheck->execute([$wo_id, $station_id]);
                $entered_count = (int)$stmtCheck->fetchColumn();

                if (($entered_count + $qty) > $target_qty) {
                    throw new Exception("Target reached! You cannot start more units than the Work Order target ($target_qty).");
                }
            }

            $pdo->beginTransaction();
            $stmtInsert = $pdo->prepare("INSERT INTO transactions (date_time, sn, result, wo_id, emp_id, station_id, defect_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            
            for ($i = 0; $i < $qty; $i++) {
                $sn = (!empty($provided_sn)) ? $provided_sn : "SN-" . strtoupper(uniqid()); 

                $checkDead = $pdo->prepare("SELECT id FROM transactions WHERE sn = ? AND result = 'FAIL'");
                $checkDead->execute([$sn]);
                if ($checkDead->fetchColumn()) throw new Exception("SN: $sn has already FAILED at a previous station and is scrapped.");

                $checkDup = $pdo->prepare("SELECT id FROM transactions WHERE sn = ? AND station_id = ?");
                $checkDup->execute([$sn, $station_id]);
                if ($checkDup->fetchColumn()) throw new Exception("SN: $sn has already been processed at $selected_station.");

                if ($current_idx > 0) {
                    $prev_station_name = $station_sequence[$current_idx - 1];
                    $checkPrev = $pdo->prepare("SELECT result FROM transactions JOIN stations ON transactions.station_id = stations.id WHERE sn = ? AND station_name = ?");
                    $checkPrev->execute([$sn, $prev_station_name]);
                    $prev_result = $checkPrev->fetchColumn();

                    if (!$prev_result) throw new Exception("Routing Error: SN $sn has not completed $prev_station_name yet.");
                    if ($prev_result !== 'PASS') throw new Exception("Routing Error: SN $sn did not PASS $prev_station_name.");
                }

                if ($custom_time) {
                    $clean_time = str_replace('T', ' ', $custom_time);
                    $ts = strtotime($clean_time) + $i; 
                } else {
                    $ts = time();
                }
                $date_time = date('Y-m-d H:i:s', $ts);
                
                $stmtInsert->execute([$date_time, $sn, $result, $wo_id, $emp_id, $station_id, $defect_id]);
            }
            $pdo->commit();

            echo json_encode(["status" => "success", "message" => "Logged " . ($isScanned ? $provided_sn : "$qty units") . " -> $result."]);
        } catch (Exception $e) { 
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(["status" => "error", "message" => $e->getMessage()]); 
        }
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Data Entry Simulator</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <style>
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex flex-col items-center justify-center font-sans p-4 lg:p-8">

    <div class="w-full max-w-2xl space-y-6">
        
        <!-- Header -->
        <div class="flex justify-between items-center border-b border-slate-200 pb-4">
            <div>
                <h1 class="text-3xl font-black text-blue-600"><i class="fa-solid fa-mobile-screen-button mr-2"></i> Data Entry Simulator</h1>
            </div>
        </div>

        <!-- STEP 1: CONFIGURATION CARD -->
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
            <h2 class="text-xs font-black text-slate-500 uppercase tracking-widest mb-4 flex items-center gap-2">
                <span class="bg-slate-100 text-slate-600 w-5 h-5 rounded-full flex items-center justify-center border border-slate-200">1</span> Session Setup
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <!-- Work Order Setup -->
                <div class="md:col-span-2 bg-slate-50 p-4 rounded-xl border border-slate-200">
                    <label class="block text-xs font-bold text-slate-500 mb-2 uppercase tracking-wider">Target Work Order</label>
                    <div class="flex flex-wrap sm:flex-nowrap gap-3">
                        <select id="input-wo" class="flex-1 bg-white border border-slate-200 rounded-lg px-3 py-2 text-slate-800 font-bold text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition cursor-pointer">
                            <option value="">Loading Work Orders...</option>
                        </select>
                        <button onclick="openCreateWOModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-bold transition flex items-center gap-2 shadow-sm" title="Create Custom Work Order">
                            <i class="fa-solid fa-plus"></i> Custom
                        </button>
                        <button onclick="openDeleteModal()" class="bg-red-50 hover:bg-red-600 text-red-600 hover:text-white px-4 py-2 rounded-lg font-bold transition flex items-center gap-2 shadow-sm border border-red-200" title="Delete Work Orders">
                            <i class="fa-solid fa-trash-can"></i>
                        </button>
                    </div>
                </div>

                <!-- Operator & Station -->
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-2">Operator ID</label>
                    <select id="input-emp" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2.5 text-slate-800 font-bold text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 cursor-pointer">
                        <?php foreach($emp_names as $emp): ?>
                            <option value="<?php echo $emp; ?>"><?php echo ucfirst(strtolower($emp)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-2">Station ID</label>
                    <select id="input-station" class="w-full bg-white border border-slate-200 rounded-lg px-3 py-2.5 text-slate-800 font-bold text-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 cursor-pointer">
                        <option value="FIN#CS-ENTRY">FIN#CS-ENTRY</option>
                        <option value="FIN#CS-INSPECTION_ASSEMBLY">FIN#CS-INSPECTION_ASSEMBLY</option>
                        <option value="FIN#CS-IPQC">FIN#CS-IPQC</option>
                        <option value="FIN#CS-SEMI_AUTO_SWAGING">FIN#CS-SEMI_AUTO_SWAGING</option>
                        <option value="FIN#CS-VMI_SWAGING">FIN#CS-VMI_SWAGING</option>
                        <option value="FIN#CS-DUST_PLUG">FIN#CS-DUST_PLUG</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- STEP 2: INPUT METHOD CARD -->
        <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
            <h2 class="text-xs font-black text-slate-500 uppercase tracking-widest mb-4 flex items-center gap-2">
                <span class="bg-slate-100 text-slate-600 w-5 h-5 rounded-full flex items-center justify-center border border-slate-200">2</span> Data Entry
            </h2>

            <!-- Mode Tabs -->
            <div class="flex bg-slate-100 rounded-lg p-1 mb-5 border border-slate-200">
                <button onclick="toggleMode('scanner')" id="tab-scanner" class="flex-1 py-2 rounded-md font-bold text-sm bg-blue-600 text-white shadow transition">Camera Scanner</button>
                <button onclick="toggleMode('manual')" id="tab-manual" class="flex-1 py-2 rounded-md font-bold text-sm text-slate-500 hover:text-slate-800 transition">Manual Entry</button>
            </div>

            <!-- Scanner Mode -->
            <div id="section-scanner" class="bg-slate-50 border-2 border-dashed border-blue-300 rounded-xl p-4 text-center relative overflow-hidden transition-all duration-300">
                <div id="scanner-state-indicator" class="bg-blue-600 text-white text-xs font-bold py-1.5 px-4 rounded-full inline-block mb-4 shadow">STEP 1: SCAN SERIAL NUMBER</div>
                <div class="max-w-sm mx-auto">
                    <div id="qr-reader" class="w-full overflow-hidden rounded-xl bg-slate-100 text-slate-800 border-2 border-transparent transition-all duration-300"></div>
                </div>
                <div id="scan-feedback" class="mt-4 hidden p-3 rounded-lg font-black text-sm transition-all shadow-lg mx-auto max-w-sm"></div>
                <button onclick="undoLastScan()" class="mt-4 text-xs text-slate-500 hover:text-slate-800 underline"><i class="fa-solid fa-rotate-left mr-1"></i> Tap here or Scan "UNDO" QR to remove last entry</button>
            </div>

            <!-- Manual Mode -->
            <div id="section-manual" class="hidden">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                    
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-bold text-slate-500 mb-2">Specific Serial Number (Optional)</label>
                        <input type="text" id="input-sn" placeholder="Leave blank to auto-generate at ENTRY" class="w-full bg-white border border-slate-200 rounded-lg px-4 py-3 text-slate-800 outline-none font-bold placeholder:text-slate-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition">
                    </div>
                    
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-2">Batch Qty (Must be 1 for SN)</label>
                        <input type="number" id="input-qty" value="1" min="1" max="2000" class="w-full bg-white border border-slate-200 rounded-lg px-4 py-3 text-slate-800 outline-none font-bold focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition">
                    </div>
                    
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-2">Custom Time (Optional)</label>
                        <input type="datetime-local" id="input-datetime" class="w-full bg-white border border-slate-200 rounded-lg px-4 py-3 text-slate-800 outline-none font-bold text-xs focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition">
                    </div>

                    <div class="sm:col-span-2 mt-2">
                        <label class="block text-sm font-bold text-slate-600 mb-3">Result Status</label>
                        <div class="grid grid-cols-2 gap-4">
                            <label class="cursor-pointer group">
                                <input type="radio" name="input-result" value="PASS" class="peer sr-only" checked onchange="toggleDefectDropdown()">
                                <div class="text-center bg-white border border-slate-200 rounded-xl py-4 text-slate-500 font-bold peer-checked:bg-green-50 peer-checked:border-green-500 peer-checked:text-green-600 transition group-hover:bg-slate-50 text-lg shadow-sm">
                                    <i class="fa-solid fa-check-circle mr-2"></i> PASS
                                </div>
                            </label>
                            <label class="cursor-pointer group">
                                <input type="radio" name="input-result" value="FAIL" class="peer sr-only" onchange="toggleDefectDropdown()">
                                <div class="text-center bg-white border border-slate-200 rounded-xl py-4 text-slate-500 font-bold peer-checked:bg-red-50 peer-checked:border-red-500 peer-checked:text-red-600 transition group-hover:bg-slate-50 text-lg shadow-sm">
                                    <i class="fa-solid fa-times-circle mr-2"></i> FAIL
                                </div>
                            </label>
                        </div>
                    </div>

                    <div id="defect-container" class="sm:col-span-2 opacity-50 pointer-events-none transition-opacity mt-2">
                        <label class="block text-sm font-bold text-slate-600 mb-2">Defect Type</label>
                        <select id="input-defect" class="w-full bg-white border border-slate-200 rounded-lg px-4 py-3 text-slate-800 focus:outline-none focus:border-red-500 focus:ring-2 focus:ring-red-100 font-bold text-sm cursor-pointer">
                            <?php foreach($defect_pool as $defect): ?><option value="<?php echo $defect; ?>"><?php echo $defect; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="flex gap-3 mt-6 pt-6 border-t border-slate-200">
                    <button onclick="undoLastScan()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold py-4 px-5 rounded-xl transition shadow-sm border border-slate-200 shrink-0" title="Undo Last Entry">
                        <i class="fa-solid fa-rotate-left"></i>
                    </button>
                    <button onclick="injectData(false)" id="btn-inject" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-black py-4 rounded-xl transition shadow-md flex justify-center items-center gap-2 text-lg">
                        <i class="fa-solid fa-keyboard"></i> MANUAL INJECT
                    </button>
                </div>
            </div>
        </div>

        <!-- STEP 3: CONSOLE LOG -->
        <div class="bg-slate-800 p-4 rounded-xl h-32 overflow-y-auto font-mono text-[11px] text-emerald-400 border border-slate-700 shadow-inner custom-scrollbar relative" id="console-log">
            <div class="absolute top-2 right-2 text-slate-500 text-[10px] font-bold tracking-widest"><i class="fa-solid fa-terminal mr-1"></i> LOG</div>
            > Strict Routing Engine Online. Wait for WO...
        </div>
        
    </div> <!-- End Main Wrapper -->

    <!-- CREATE WO MODAL -->
    <div id="create-wo-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] hidden flex-col items-center justify-center p-4 transition-all">
        <div class="bg-white border border-slate-200 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
            <div class="p-6 border-b border-slate-200 flex justify-between items-center">
                <h2 class="text-xl font-black text-blue-600"><i class="fa-solid fa-plus-circle mr-2"></i>Create Work Order</h2>
                <button onclick="closeCreateWOModal()" class="text-slate-400 hover:text-slate-800 transition"><i class="fa-solid fa-xmark text-xl"></i></button>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1">Work Order Number (Leave blank for random)</label>
                    <input type="text" id="new-wo-number" placeholder="e.g. FIN2530099" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-800 outline-none font-bold focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1">Target Quantity</label>
                    <input type="number" id="new-wo-qty-modal" value="2000" min="1" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-800 outline-none font-bold focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-1">Start Time / Date Created</label>
                    <input type="datetime-local" id="new-wo-date-modal" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-800 outline-none font-bold focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition">
                </div>
            </div>
            <div class="p-5 border-t border-slate-200 flex gap-3 justify-end bg-slate-50">
                <button onclick="closeCreateWOModal()" class="px-5 py-2 rounded-lg font-bold text-slate-600 hover:bg-slate-200 transition border border-slate-200">Cancel</button>
                <button onclick="submitNewWO()" class="px-5 py-2 rounded-lg font-bold bg-blue-600 hover:bg-blue-700 text-white shadow-md transition flex items-center gap-2">
                    <i class="fa-solid fa-check"></i> Create
                </button>
            </div>
        </div>
    </div>

    <!-- DELETE WORK ORDERS MODAL -->
    <div id="delete-wo-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] hidden flex-col items-center justify-center p-4 transition-all">
        <div class="bg-white border border-slate-200 rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
            <div class="p-6 border-b border-slate-200 flex justify-between items-center">
                <h2 class="text-xl font-black text-red-600"><i class="fa-solid fa-trash-can mr-2"></i>Delete Work Orders</h2>
                <button onclick="closeDeleteModal()" class="text-slate-400 hover:text-slate-800 transition"><i class="fa-solid fa-xmark text-xl"></i></button>
            </div>
            
            <div class="p-6">
                <label class="flex items-center gap-3 mb-4 p-3 bg-slate-100 rounded-lg border border-slate-200 cursor-pointer hover:bg-slate-200 transition">
                    <input type="checkbox" id="delete-select-all" onchange="toggleSelectAllWOs()" class="w-5 h-5 accent-red-500 rounded cursor-pointer border-slate-300">
                    <span class="font-bold text-slate-800 text-sm">Select All Work Orders</span>
                </label>
                
                <div id="delete-wo-checklist" class="max-h-60 overflow-y-auto bg-slate-50 rounded-lg border border-slate-200 p-2 space-y-1 custom-scrollbar">
                    <!-- Populated via JS -->
                </div>
                <p class="text-[10px] text-slate-500 mt-3 font-bold"><i class="fa-solid fa-circle-info mr-1"></i> Checked items and all associated transactions will be permanently deleted.</p>
            </div>
            
            <div class="p-5 border-t border-slate-200 flex gap-3 justify-end bg-slate-50">
                <button onclick="closeDeleteModal()" class="px-5 py-2 rounded-lg font-bold text-slate-600 hover:bg-slate-200 transition border border-slate-200">Cancel</button>
                <button onclick="confirmDeleteWOs()" class="px-5 py-2 rounded-lg font-bold bg-red-600 hover:bg-red-700 text-white shadow-md transition flex items-center gap-2">
                    <i class="fa-solid fa-triangle-exclamation"></i> OK (Delete)
                </button>
            </div>
        </div>
    </div>

    <script>
        // --- PASS THE DEFECT POOL TO JAVASCRIPT ---
        const DEFECT_LIST = <?php echo json_encode($defect_pool); ?>;
        const RESULT_KEYWORDS = ["PASS", ...DEFECT_LIST];

        let html5QrcodeScanner = null;
        let isProcessingScan = false;
        let scanState = 'WAITING_SN';
        let currentScannedSN = null;

        function logMsg(msg, isError = false) {
            const consoleEl = document.getElementById('console-log');
            const color = isError ? 'text-red-400' : 'text-emerald-400';
            const time = new Date().toLocaleTimeString();
            consoleEl.innerHTML = `<div class="${color}">[${time}] > ${msg}</div>` + consoleEl.innerHTML;
        }

        function playBeep(isFail) {
            try {
                const AudioContext = window.AudioContext || window.webkitAudioContext;
                if(!AudioContext) return; 
                const ctx = new AudioContext();
                const osc = ctx.createOscillator();
                const gainNode = ctx.createGain();
                osc.type = isFail ? 'sawtooth' : 'sine';
                osc.frequency.value = isFail ? 200 : 1200; 
                gainNode.gain.setValueAtTime(0.1, ctx.currentTime);
                osc.connect(gainNode);
                gainNode.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + (isFail ? 0.4 : 0.15));
            } catch (e) { }
        }

        function toggleDefectDropdown() {
            const result = document.querySelector('input[name="input-result"]:checked').value;
            const container = document.getElementById('defect-container');
            if (result === 'FAIL') container.classList.remove('opacity-50', 'pointer-events-none');
            else container.classList.add('opacity-50', 'pointer-events-none');
        }

        function toggleMode(mode) {
            const tabMan = document.getElementById('tab-manual');
            const tabScan = document.getElementById('tab-scanner');
            const secMan = document.getElementById('section-manual');
            const secScan = document.getElementById('section-scanner');
            if (mode === 'manual') {
                tabMan.className = "flex-1 py-2 rounded-md font-bold text-sm bg-blue-600 text-white shadow transition";
                tabScan.className = "flex-1 py-2 rounded-md font-bold text-sm text-slate-500 hover:text-slate-800 transition";
                secMan.classList.remove('hidden');
                secScan.classList.add('hidden');
                stopScanner();
            } else {
                tabScan.className = "flex-1 py-2 rounded-md font-bold text-sm bg-blue-600 text-white shadow transition";
                tabMan.className = "flex-1 py-2 rounded-md font-bold text-sm text-slate-500 hover:text-slate-800 transition";
                secScan.classList.remove('hidden');
                secMan.classList.add('hidden');
                startScanner();
            }
        }

        function startScanner() {
            if (!html5QrcodeScanner) {
                html5QrcodeScanner = new Html5QrcodeScanner("qr-reader", { fps: 10, qrbox: {width: 250, height: 250} }, false);
                html5QrcodeScanner.render(onScanSuccess, () => {});
                resetScannerState();
            }
        }

        function stopScanner() {
            if (html5QrcodeScanner) {
                html5QrcodeScanner.clear();
                html5QrcodeScanner = null;
            }
        }

        function resetScannerState() {
            scanState = 'WAITING_SN';
            currentScannedSN = null;
            const indicator = document.getElementById('scanner-state-indicator');
            const container = document.getElementById('section-scanner');
            const reader = document.getElementById('qr-reader');
            indicator.innerText = "STEP 1: SCAN SERIAL NUMBER";
            indicator.className = "bg-blue-600 text-white text-xs font-bold py-1.5 px-4 rounded-full inline-block mb-4 shadow transition-all";
            container.className = "bg-slate-50 border-2 border-dashed border-blue-300 rounded-xl p-4 text-center relative overflow-hidden transition-all duration-300";
            reader.className = "w-full overflow-hidden rounded-xl bg-slate-100 text-slate-800 border-2 border-transparent transition-all duration-300";
        }

        async function onScanSuccess(decodedText) {
            if (isProcessingScan) return; 
            const wo = document.getElementById('input-wo').value;
            const station = document.getElementById('input-station').value;
            if(!wo) { logMsg("SCAN ERROR: Select WO first!", true); playBeep(true); return; }

            const textUpper = decodedText.trim().toUpperCase();

            // UNDO HOTKEY
            if (textUpper === 'UNDO') {
                isProcessingScan = true;
                html5QrcodeScanner.pause(true);
                playBeep(false);
                await undoLastScan();
                resetScannerState();
                setTimeout(() => { isProcessingScan = false; html5QrcodeScanner.resume(); }, 3000);
                return;
            }

            // STEP 1: WAITING FOR SN
            if (scanState === 'WAITING_SN') {
                // FIXED LOGIC: If scanned text is in the defect list or is "PASS", reject it as an SN
                if (RESULT_KEYWORDS.includes(textUpper) || textUpper.startsWith('FAIL:')) {
                    logMsg(`ERROR: You scanned a Result/Defect (${textUpper}). Scan SN first!`, true);
                    playBeep(true);
                    isProcessingScan = true; 
                    html5QrcodeScanner.pause(true);
                    showScanFeedback("ERROR: Scan SN First!", "bg-red-600", "text-white");
                    setTimeout(() => { 
                        document.getElementById('scan-feedback').classList.add('hidden');
                        isProcessingScan = false; 
                        html5QrcodeScanner.resume(); 
                    }, 1500);
                    return;
                }

                currentScannedSN = textUpper;
                isProcessingScan = true; 
                html5QrcodeScanner.pause(true);

                try {
                    const res = await fetch('inserter.php?action=validate_sn', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ wo, station, sn: currentScannedSN })
                    });
                    const data = await res.json();
                    if (data.status === 'error') {
                        logMsg(data.message, true);
                        playBeep(true);
                        showScanFeedback(data.message, 'bg-red-600', 'text-white text-sm');
                        setTimeout(() => { 
                            document.getElementById('scan-feedback').classList.add('hidden');
                            resetScannerState();
                            isProcessingScan = false; 
                            html5QrcodeScanner.resume(); 
                        }, 2500);
                        return;
                    }
                } catch (e) { logMsg("Network Validation Error", true); }

                scanState = 'WAITING_RESULT';
                playBeep(false);
                document.getElementById('scanner-state-indicator').innerText = `STEP 2: SCAN RESULT FOR ${currentScannedSN}`;
                document.getElementById('scanner-state-indicator').className = "bg-yellow-500 text-black text-xs font-bold py-1.5 px-4 rounded-full inline-block mb-4 shadow transition-all";
                document.getElementById('qr-reader').classList.add('border-yellow-500');
                setTimeout(() => { isProcessingScan = false; html5QrcodeScanner.resume(); }, 800);
                return;
            }

            // STEP 2: WAITING FOR RESULT
            if (scanState === 'WAITING_RESULT') {
                // If they scan another string that is NOT a defect and NOT pass, it might be a new SN
                if (!RESULT_KEYWORDS.includes(textUpper) && !textUpper.startsWith('FAIL:')) {
                    logMsg("Swapping to new Serial Number...", false);
                    resetScannerState();
                    isProcessingScan = false;
                    onScanSuccess(decodedText); 
                    return;
                }

                isProcessingScan = true;
                html5QrcodeScanner.pause(true);
                let resultStatus = 'PASS';
                let defectType = 'NONE';
                if (textUpper !== 'PASS') {
                    resultStatus = 'FAIL';
                    // Strip prefix if exists, else use raw text
                    defectType = textUpper.startsWith('FAIL:') ? textUpper.replace('FAIL:', '').trim() : textUpper;
                }

                await injectData(true, resultStatus, defectType, currentScannedSN);
                resetScannerState();
                setTimeout(() => {
                    document.getElementById('scan-feedback').classList.add('hidden');
                    isProcessingScan = false;
                    html5QrcodeScanner.resume();
                }, 3000);
            }
        }

        function showScanFeedback(msg, bgClass, textClass) {
            const fb = document.getElementById('scan-feedback');
            fb.className = `mt-4 p-3 rounded-lg font-black ${bgClass} ${textClass} animate-pulse shadow-lg mx-auto max-w-sm`;
            fb.innerText = msg;
            fb.classList.remove('hidden');
        }

        async function undoLastScan() {
            const wo = document.getElementById('input-wo').value;
            const emp = document.getElementById('input-emp').value;
            const station = document.getElementById('input-station').value;
            if(!wo) return;
            try {
                const res = await fetch('inserter.php?action=undo', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ wo, emp, station })
                });
                const data = await res.json();
                logMsg(data.message, data.status !== 'success');
                if (data.status === 'success') {
                    showScanFeedback(`⎌ UNDONE RECENT SCAN`, 'bg-blue-600', 'text-white text-lg');
                } else {
                    showScanFeedback(`CANNOT UNDO`, 'bg-red-500', 'text-white text-lg');
                }
            } catch (e) { logMsg("Undo Network Error", true); }
        }

        async function loadWorkOrders(autoSelectWO = null) {
            try {
                const res = await fetch('inserter.php?action=get_wos');
                const json = await res.json();
                const select = document.getElementById('input-wo');
                select.innerHTML = '';
                if(json.data.length === 0) { select.innerHTML = '<option value="">No Work Orders</option>'; return; }
                json.data.forEach(wo => {
                    select.innerHTML += `<option value="${wo.wo_number}">${wo.wo_number} (Target: ${wo.target_qty})</option>`;
                });
                if(autoSelectWO) select.value = autoSelectWO;
            } catch(e) { logMsg("Failed to load work orders.", true); }
        }

        // --- NEW CREATE WO MODAL FUNCTIONS ---
        function openCreateWOModal() {
            document.getElementById('new-wo-number').value = '';
            document.getElementById('new-wo-qty-modal').value = '2000';
            
            // Set default to current date and time
            const now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            document.getElementById('new-wo-date-modal').value = now.toISOString().slice(0,16);

            const modal = document.getElementById('create-wo-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeCreateWOModal() {
            const modal = document.getElementById('create-wo-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        async function submitNewWO() {
            const woNumber = document.getElementById('new-wo-number').value.trim();
            const targetQty = document.getElementById('new-wo-qty-modal').value || 2000;
            const woDate = document.getElementById('new-wo-date-modal').value;

            logMsg(`Creating new Work Order...`);
            closeCreateWOModal();

            try {
                let url = `inserter.php?action=create_wo&target_qty=${targetQty}`;
                if (woNumber) url += `&wo_number=${encodeURIComponent(woNumber)}`;
                if (woDate) url += `&created_at=${encodeURIComponent(woDate)}`;

                const res = await fetch(url);
                const data = await res.json();
                if(data.status === 'success') {
                    logMsg(data.message); 
                    await loadWorkOrders(data.wo_number); 
                } else { 
                    logMsg("Error: " + data.message, true); 
                }
            } catch(e) { 
                logMsg("Failed to create work order.", true); 
            }
        }

        // --- MULTI-SELECT DELETE FUNCTIONS ---
        async function openDeleteModal() {
            const checklist = document.getElementById('delete-wo-checklist');
            document.getElementById('delete-select-all').checked = false;
            checklist.innerHTML = '<div class="p-4 text-center text-slate-500"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>';
            
            const modal = document.getElementById('delete-wo-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');

            try {
                const res = await fetch('inserter.php?action=get_wos');
                const json = await res.json();
                checklist.innerHTML = '';
                
                if(json.data.length === 0) { 
                    checklist.innerHTML = '<div class="p-4 text-center text-slate-500 text-sm font-bold">No Work Orders found.</div>';
                    return; 
                }
                
                json.data.forEach(wo => {
                    checklist.innerHTML += `
                        <label class="flex items-center gap-3 p-3 rounded-lg hover:bg-white border border-transparent hover:border-slate-300 cursor-pointer transition">
                            <input type="checkbox" value="${wo.wo_number}" class="wo-delete-checkbox w-4 h-4 accent-red-500 rounded cursor-pointer">
                            <span class="text-sm font-bold text-slate-800 flex-1">${wo.wo_number}</span>
                            <span class="text-xs text-slate-500 font-bold bg-white border border-slate-200 px-2 py-1 rounded">Target: ${wo.target_qty}</span>
                        </label>
                    `;
                });
            } catch(e) { 
                checklist.innerHTML = '<div class="p-4 text-center text-red-500 text-sm font-bold">Failed to load Work Orders.</div>';
            }
        }

        function closeDeleteModal() {
            const modal = document.getElementById('delete-wo-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        function toggleSelectAllWOs() {
            const isChecked = document.getElementById('delete-select-all').checked;
            const checkboxes = document.querySelectorAll('.wo-delete-checkbox');
            checkboxes.forEach(cb => cb.checked = isChecked);
        }

        async function confirmDeleteWOs() {
            const checkboxes = document.querySelectorAll('.wo-delete-checkbox:checked');
            const wo_numbers = Array.from(checkboxes).map(cb => cb.value);

            if (wo_numbers.length === 0) {
                alert("Please select at least one Work Order to delete.");
                return;
            }

            if(!confirm(`Are you sure you want to PERMANENTLY delete ${wo_numbers.length} Work Order(s) and ALL their associated transactions?`)) return;

            logMsg(`Deleting ${wo_numbers.length} Work Order(s)...`, true);
            closeDeleteModal();

            try {
                const res = await fetch('inserter.php?action=delete_wo', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ wo_numbers: wo_numbers })
                });
                const data = await res.json();
                
                logMsg(data.message, data.status !== 'success');
                if (data.status === 'success') {
                    loadWorkOrders(); // Refresh the list
                }
            } catch(e) {
                logMsg("Network error during deletion.", true);
            }
        }

        async function resetDB() {
            if(!confirm("Are you sure you want to delete all transactions AND all Work Orders?")) return;
            try {
                const res = await fetch('inserter.php?action=reset');
                const data = await res.json();
                logMsg(data.message, data.status !== 'success');
                loadWorkOrders(); 
            } catch (e) { }
        }

        async function injectData(isScanned = false, scanResult = null, scanDefect = null, scannedSN = null) {
            const wo = document.getElementById('input-wo').value;
            const emp = document.getElementById('input-emp').value;
            const station = document.getElementById('input-station').value;
            let qty = 1;
            let datetime = null;
            let result = scanResult;
            let defect = scanDefect;
            let sn = scannedSN;
            if (!isScanned) {
                qty = document.getElementById('input-qty').value;
                datetime = document.getElementById('input-datetime').value;
                result = document.querySelector('input[name="input-result"]:checked').value;
                defect = document.getElementById('input-defect').value;
                sn = document.getElementById('input-sn').value;
                const btn = document.getElementById('btn-inject');
                btn.disabled = true;
                btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> INJECTING...`;
            }
            if(!wo) { logMsg("ERROR: Select a Work Order first!", true); return; }
            try {
                const res = await fetch('inserter.php?action=insert', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ wo, qty, result, defect, emp, station, datetime, scanned: isScanned, sn })
                });
                const data = await res.json();
                const isError = data.status !== 'success';
                logMsg(data.message, isError);
                if (isScanned) {
                     if (isError) {
                         playBeep(true); 
                         showScanFeedback(`ERROR: Routing Rejected!`, 'bg-red-600', 'text-white text-sm');
                     } else {
                         const isFailItem = result === 'FAIL';
                         playBeep(isFailItem);
                         showScanFeedback(`SAVED: ${sn} -> ${result}`, isFailItem ? 'bg-orange-500' : 'bg-green-500', 'text-white text-lg');
                     }
                } else if (isError) {
                     playBeep(true); 
                }
            } catch (e) { logMsg("Network error.", true); }
            if (!isScanned) {
                const btn = document.getElementById('btn-inject');
                btn.disabled = false;
                btn.innerHTML = `<i class="fa-solid fa-keyboard"></i> MANUAL INJECT`;
            }
        }

        window.onload = () => {
            loadWorkOrders();
            toggleMode('scanner');
        };
    </script>
</body>
</html>