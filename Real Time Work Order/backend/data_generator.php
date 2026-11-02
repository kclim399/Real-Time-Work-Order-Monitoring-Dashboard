<?php
// backend/data_generator.php
require_once 'config.php';
date_default_timezone_set('Asia/Kuala_Lumpur');

$station_sequence = [
    'FIN#CS-ENTRY',
    'FIN#CS-INSPECTION_ASSEMBLY',
    'FIN#CS-IPQC',
    'FIN#CS-SEMI_AUTO_SWAGING',
    'FIN#CS-VMI_SWAGING',
    'FIN#CS-DUST_PLUG'
];

$defect_pool = [
    "DAMAGE COMPONENT", "EPOXY VOID/HOLE", "EPOXY INSUFFICIENT", "EXCESS EPOXY",
    "CRACK COMPONENT", "INCOMPLETE SWAGING/SWAGING MARK", "INSUFF/MISSING EMI PASTE I&J",
    "SHELL/COVER BLISTER/SHELL BEND/GAP/EXPOSE", "MISSING EMI PLATE", "EMI PLATE BEND",
    "LABEL ILLEGIBLE", "PLASTIC PORT CHIPPING", "ROUTER OVERCUT",
    "GOLD FINGER SCRATCHES/CONTAMINATION", "SILICON PAD DAMAGE/MISSING",
    "MISSING COMPONENT", "THERMAL PAD DISLOGED/MISPLACED", "COMPONENT OVERHANG",
    "FOREIGN MATERIAL"
];

$emp_names = ['RAJESH', 'SARAH', 'AHMAD', 'JOHN', 'MEI LING', 'SIVA', 'INDA.SUSANTI', 'BABU.RANA', 'HOSSAIN.SABUJ'];

function getOrCreate($pdo, $table, $col, $val) {
    $stmt = $pdo->prepare("SELECT id FROM $table WHERE $col = ?");
    $stmt->execute([$val]);
    $id = $stmt->fetchColumn();
    if (!$id) {
        $pdo->prepare("INSERT INTO $table ($col) VALUES (?)")->execute([$val]);
        $id = $pdo->lastInsertId();
    }
    return $id;
}

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $pdo = getDBConnection();

    if ($_GET['action'] == 'create_wo') {
        $input = json_decode(file_get_contents('php://input'), true);
        $wo = $input['wo_number'];
        $qty = $input['qty'];
        
        $start_time_str = date('Y-m-d H:i:s', strtotime(str_replace('T', ' ', $input['start_time'])));

        try {
            $stmt = $pdo->prepare("INSERT INTO work_orders (wo_number, target_qty, created_at) VALUES (?, ?, ?)");
            $stmt->execute([$wo, $qty, $start_time_str]);
            echo json_encode(["status" => "success", "wo_id" => $pdo->lastInsertId()]);
        } catch (Exception $e) {
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        exit;
    }

    if ($_GET['action'] == 'generate_chunk') {
        $input = json_decode(file_get_contents('php://input'), true);
        $wo_id = $input['wo_id'];
        $current_time = $input['start_time']; 
        $chunk = $input['chunk']; 

        try {
            $st_ids = []; foreach($station_sequence as $st) $st_ids[] = getOrCreate($pdo, 'stations', 'station_name', $st);
            $em_ids = []; foreach($emp_names as $em) $em_ids[] = getOrCreate($pdo, 'employees', 'emp_name', $em);
            $df_ids = []; foreach($defect_pool as $df) $df_ids[] = getOrCreate($pdo, 'defects', 'defect_name', $df);
            $none_id = getOrCreate($pdo, 'defects', 'defect_name', 'NONE');

            session_start();
            if (!isset($_SESSION['wip_pipeline_'.$wo_id])) {
                $_SESSION['wip_pipeline_'.$wo_id] = array_fill(0, 6, []);
                $_SESSION['sn_counter_'.$wo_id] = hexdec('BAAA'); 
            }
            $wip = $_SESSION['wip_pipeline_'.$wo_id];

            if (!empty($chunk)) {
                foreach ($chunk as $index => $unit_target_result) {
                    $sn = "NE3" . strtoupper(dechex($_SESSION['sn_counter_'.$wo_id]++));
                    $fail_at = ($unit_target_result === 'FAIL') ? rand(1, 5) : 99; 
                    
                    $wip[0][] = [
                        'sn' => $sn,
                        'target' => $unit_target_result,
                        'fail_at' => $fail_at,
                        'last_time' => $current_time + ($index * rand(1, 4)) 
                    ];
                }
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO transactions (date_time, sn, result, wo_id, emp_id, station_id, defect_id) VALUES (?, ?, ?, ?, ?, ?, ?)");

            $capacities = [
                0 => 40, 
                1 => 35, 
                2 => 30, 
                3 => 25, 
                4 => 20, 
                5 => 15  
            ];

            $station_emps = [];
            for ($s = 0; $s <= 5; $s++) {
                $station_emps[$s] = $em_ids[array_rand($em_ids)];
            }

            for ($s = 5; $s >= 0; $s--) {
                $can_process = $capacities[$s];
                $available = count($wip[$s]);
                $to_process = min($can_process, $available);
                
                $emp = $station_emps[$s];

                for ($i = 0; $i < $to_process; $i++) {
                    $unit = array_shift($wip[$s]); 
                    
                    $step_result = ($s == $unit['fail_at']) ? 'FAIL' : 'PASS';
                    $step_defect = ($step_result === 'FAIL') ? $df_ids[array_rand($df_ids)] : $none_id;
                    $step_station = $st_ids[$s];
                    
                    if ($s > 0) {
                        $unit['last_time'] += rand(3600, 7200); 
                    }
                    
                    $step_dt = date('Y-m-d H:i:s', $unit['last_time']);

                    $stmt->execute([$step_dt, $unit['sn'], $step_result, $wo_id, $emp, $step_station, $step_defect]);

                    if ($step_result === 'PASS' && $s < 5) {
                        $wip[$s+1][] = $unit;
                    }
                }
            }

            $is_pipeline_empty = true;
            for ($s = 0; $s <= 5; $s++) {
                if (!empty($wip[$s])) {
                    $is_pipeline_empty = false;
                    break;
                }
            }

            $stmtCount = $pdo->prepare("SELECT COUNT(DISTINCT sn) FROM transactions WHERE wo_id = ? AND (result = 'FAIL' OR station_id = ?)");
            $stmtCount->execute([$wo_id, $st_ids[5]]);
            $completed_count = (int)$stmtCount->fetchColumn();

            if ($is_pipeline_empty) {
                unset($_SESSION['wip_pipeline_'.$wo_id]);
                unset($_SESSION['sn_counter_'.$wo_id]);
            } else {
                $_SESSION['wip_pipeline_'.$wo_id] = $wip;
            }

            $pdo->commit();
            
            $next_time = $current_time + rand(900, 1500); 
            
            echo json_encode([
                "status" => "success", 
                "next_time" => $next_time,
                "pipeline_empty" => $is_pipeline_empty,
                "completed" => $completed_count
            ]);
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
    <title>FYP Automated Data Generator</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 text-slate-800 h-screen flex overflow-hidden font-sans">

    <aside class="w-80 bg-white border-r border-slate-200 flex flex-col shadow-lg z-20 shrink-0">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between">
            <h2 class="text-lg font-black text-slate-700">Simulations Queue</h2>
            <span class="bg-blue-100 text-blue-700 text-[10px] font-bold px-2 py-1 rounded-full" id="queue-count">0 Active</span>
        </div>
        <div id="wo-sidebar-list" class="flex-1 overflow-y-auto p-4 space-y-4">
            <div class="text-center p-6 text-slate-400 text-sm font-bold mt-10">
                <i class="fa-solid fa-inbox text-3xl mb-3 opacity-50 block"></i>
                No work orders created yet.<br>Create one using the form.
            </div>
        </div>
    </aside>

    <main class="flex-1 flex flex-col items-center justify-center p-8 overflow-y-auto relative">
        <div class="bg-white p-8 rounded-3xl shadow-xl w-full max-w-2xl border border-slate-200">
            
            <div class="border-b border-slate-200 pb-6 mb-6">
                <h1 class="text-3xl font-black text-blue-600 flex items-center gap-3">
                    <i class="fa-solid fa-rocket"></i> MES Data Generator
                </h1>
            </div>

            <div class="mb-6">
                <div>
                    <div class="flex justify-between items-end mb-2">
                        <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest">Work Order Number</label>
                    </div>
                    <input type="text" id="wo-number" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-4 py-3 text-slate-800 font-bold outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition">
                </div>
            </div>

            <div class="grid grid-cols-3 gap-6 mb-8">
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-2 uppercase tracking-widest">Target Output</label>
                    <input type="number" id="qty" value="2000" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-4 py-3 text-blue-600 font-bold outline-none text-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-2 uppercase tracking-widest">Final Yield (%)</label>
                    <input type="number" id="yield" value="99.5" step="0.1" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-4 py-3 text-green-600 font-bold outline-none text-xl focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 mb-2 uppercase tracking-widest">Start Time</label>
                    <input type="datetime-local" id="start-time" class="w-full bg-slate-50 border border-slate-300 rounded-lg px-4 py-3 text-slate-800 font-bold outline-none text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 transition">
                </div>
            </div>

            <div class="flex flex-col gap-3">
                <button id="btn-create" onclick="createWorkOrder()" class="w-full bg-blue-600 hover:bg-blue-700 text-white text-lg font-black py-4 rounded-xl transition shadow-md flex justify-center items-center gap-3">
                    <i class="fa-solid fa-plus-circle"></i> CREATE WORK ORDER
                </button>
                
                <button onclick="resetUI()" class="w-full bg-slate-50 hover:bg-slate-100 text-slate-600 hover:text-slate-900 border border-slate-200 text-sm font-bold py-3 rounded-xl transition shadow-sm flex justify-center items-center gap-2">
                    <i class="fa-solid fa-rotate-right"></i> RESET FORM
                </button>
            </div>

            <div class="mt-6 bg-slate-800 p-4 rounded-lg h-32 overflow-y-auto font-mono text-[11px] text-green-400 border border-slate-700 shadow-inner" id="console-log">
                > Initialized system. Waiting for work order creation...
            </div>

        </div>
    </main>

    <script>
        // State Management for Concurrent Work Orders
        // CHANGED: Changed from const to let so we can overwrite it on load
        let woTasks = {};

        // ADDED: LocalStorage Save Function
        function saveState() {
            localStorage.setItem('woTasksState', JSON.stringify(woTasks));
        }

        // ADDED: LocalStorage Load Function
        function loadState() {
            const saved = localStorage.getItem('woTasksState');
            if (saved) {
                try {
                    woTasks = JSON.parse(saved);
                    // Force any previously running tasks to pause so they don't run invisibly
                    Object.values(woTasks).forEach(task => {
                        if (task.isRunning) task.isRunning = false;
                    });
                    renderSidebar();
                    logMsg("Previous Work Order sessions restored from memory.", false);
                } catch (e) {
                    console.error("Failed to parse saved state", e);
                }
            }
        }

        function logMsg(msg, isError = false) {
            const consoleEl = document.getElementById('console-log');
            const color = isError ? 'text-red-400' : 'text-green-400';
            consoleEl.innerHTML = `<div class="${color}">> ${msg}</div>` + consoleEl.innerHTML;
        }

        function resetUI() {
            document.getElementById('wo-number').value = "FIN25" + Math.floor(Math.random() * 90000 + 10000);
            const now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            document.getElementById('start-time').value = now.toISOString().slice(0,16);
            logMsg("Form reset. Ready for a new Work Order.", false);
        }

        async function createWorkOrder() {
            const btn = document.getElementById('btn-create');
            const wo = document.getElementById('wo-number').value;
            const qty = parseInt(document.getElementById('qty').value);
            const yieldVal = parseFloat(document.getElementById('yield').value);
            const startTimeStr = document.getElementById('start-time').value;

            if(!wo || qty <= 0 || yieldVal <= 0) { logMsg("Invalid inputs.", true); return; }

            btn.disabled = true;
            btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> CREATING...`;

            const totalFails = Math.round(qty * ((100 - yieldVal) / 100));
            logMsg(`Calculated Map for ${wo}: ${qty} Total Units -> ${qty - totalFails} Pass, ${totalFails} Fail`);

            let unitMap = Array(qty).fill('PASS');
            let failIndices = new Set();
            
            if (totalFails > 0) {
                const spacing = Math.floor(qty / totalFails);
                for (let i = 1; i <= totalFails; i++) {
                    let failIdx = (i * spacing) - Math.floor(Math.random() * 5) - 1;
                    if (failIdx >= qty) failIdx = qty - 1;
                    failIndices.add(failIdx);
                }
            }
            failIndices.forEach(idx => unitMap[idx] = 'FAIL');

            try {
                const res = await fetch('data_generator.php?action=create_wo', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ wo_number: wo, qty: qty, start_time: startTimeStr }) 
                });
                const data = await res.json();
                
                if(data.status !== 'success') throw new Error(data.message);
                
                // Add new task to memory
                woTasks[data.wo_id] = {
                    id: data.wo_id,
                    wo: wo,
                    qty: qty,
                    unitMap: unitMap,
                    currentTimestamp: new Date(startTimeStr).getTime() / 1000,
                    i: 0,
                    completed: 0,
                    isRunning: false,
                    isFinished: false
                };

                saveState(); // CHANGED: Save immediately after creation

                logMsg(`WO [${wo}] successfully created and added to queue. Click Play to start.`);
                renderSidebar();
                resetUI(); // Prepare for next creation

            } catch(e) { 
                logMsg("WO Creation failed: " + e.message, true); 
            } finally {
                btn.disabled = false;
                btn.innerHTML = `<i class="fa-solid fa-plus-circle"></i> CREATE WORK ORDER`;
            }
        }

        // Sidebar Rendering Logic
        function renderSidebar() {
            const container = document.getElementById('wo-sidebar-list');
            const tasksArray = Object.values(woTasks).reverse(); // Newest at the top
            
            document.getElementById('queue-count').innerText = `${tasksArray.length} Orders`;
            
            if (tasksArray.length === 0) return;
            container.innerHTML = '';

            tasksArray.forEach(task => {
                const pct = Math.min(Math.round((task.completed / task.qty) * 100), 100);
                
                // Determine icon and color based on state
                let actionBtn = '';
                let statusText = '';
                
                if (task.isFinished) {
                    statusText = `<span class="text-green-500 font-bold"><i class="fa-solid fa-check"></i> Completed</span>`;
                    actionBtn = `<button disabled class="text-green-500 opacity-50 cursor-not-allowed"><i class="fa-solid fa-circle-check text-3xl"></i></button>`;
                } else if (task.isRunning) {
                    statusText = `<span class="text-blue-500 font-bold animate-pulse">Running...</span>`;
                    actionBtn = `<button onclick="toggleWO('${task.id}')" class="text-orange-500 hover:text-orange-600 transition shadow-sm rounded-full bg-white"><i class="fa-solid fa-circle-pause text-3xl"></i></button>`;
                } else {
                    statusText = `<span class="text-slate-400 font-bold">Paused</span>`;
                    actionBtn = `<button onclick="toggleWO('${task.id}')" class="text-blue-500 hover:text-blue-600 transition shadow-sm rounded-full bg-white"><i class="fa-solid fa-circle-play text-3xl"></i></button>`;
                }

                container.innerHTML += `
                    <div class="bg-slate-50 border border-slate-200 rounded-xl p-4 shadow-sm transition-all relative overflow-hidden group hover:border-blue-300">
                        <div class="absolute top-0 left-0 h-1 bg-blue-500 transition-all duration-300" style="width: ${pct}%"></div>
                        
                        <div class="flex justify-between items-center mb-1 mt-1">
                            <div>
                                <h4 class="font-black text-slate-700 text-lg">${task.wo}</h4>
                                <p class="text-[10px] uppercase tracking-wider">${statusText}</p>
                            </div>
                            <div>
                                ${actionBtn}
                            </div>
                        </div>
                        <div class="flex justify-between items-center mt-3 pt-3 border-t border-slate-200">
                            <span class="text-xs font-bold text-slate-500">Progress</span>
                            <span class="text-xs font-black text-blue-600">${task.completed} / ${task.qty}</span>
                        </div>
                    </div>
                `;
            });
        }

        // Play/Pause Toggle Logic
        function toggleWO(wo_id) {
            const task = woTasks[wo_id];
            if (!task || task.isFinished) return;

            task.isRunning = !task.isRunning;
            saveState(); // CHANGED: Save state when toggled

            renderSidebar();

            if (task.isRunning) {
                logMsg(`[${task.wo}] Generating Simulation Data...`);
                processWOLoop(wo_id); // Trigger the async loop
            } else {
                logMsg(`[${task.wo}] Generation Paused.`);
            }
        }

        // Asynchronous Loop for a specific Work Order
        async function processWOLoop(wo_id) {
            const task = woTasks[wo_id];
            
            // Break loop condition if paused, finished, or deleted
            if (!task || !task.isRunning || task.isFinished) return;

            const chunkSize = 40; 
            let chunk = [];
            
            if (task.i < task.qty) {
                chunk = task.unitMap.slice(task.i, task.i + chunkSize);
                task.i += chunkSize;
            }
            
            try {
                const res = await fetch('data_generator.php?action=generate_chunk', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ wo_id: task.id, start_time: task.currentTimestamp, chunk })
                });
                const data = await res.json();
                
                if(data.status !== 'success') throw new Error(data.message);
                
                // Update State
                task.currentTimestamp = data.next_time; 
                task.completed = data.completed;
                
                if (data.pipeline_empty) {
                    task.isFinished = true;
                    task.isRunning = false;
                    logMsg(`[${task.wo}] GENERATION COMPLETE!`, false);
                }

                saveState(); // CHANGED: Save the new progress

                // Refresh UI to show updated progress bar
                renderSidebar();

                // If still running, schedule the next chunk
                if (task.isRunning && !task.isFinished) {
                    setTimeout(() => processWOLoop(wo_id), 300); // 300ms delay between API calls
                }

            } catch(e) { 
                logMsg(`[${task.wo}] Chunk Error: ` + e.message, true); 
                task.isRunning = false; // Auto-pause on error
                saveState(); // CHANGED: Save the paused error state
                renderSidebar();
            }
        }

        // Initialize UI
        resetUI();
        loadState(); // CHANGED: Run our load function on startup
    </script>
</body>
</html>