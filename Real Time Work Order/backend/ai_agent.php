<?php
set_time_limit(300); // Allows the script to run for up to 5 minutes
// backend/ai_agent.php
require_once 'config.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");


// Load API key securely from untracked config.secret.php or environment variable
$secretConfigPath = __DIR__ . '/config.secret.php';
$secretConfig = file_exists($secretConfigPath) ? require $secretConfigPath : [];
$GEMINI_API_KEY = trim($secretConfig['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?: '');


$input = json_decode(file_get_contents('php://input'), true);
$user_question = $input['question'] ?? '';
$action = $input['action'] ?? 'chat'; 

// ==============================================================================
// SCENARIO A: AUTONOMOUS COMPREHENSIVE REPORT GENERATION
// ==============================================================================
if ($action === 'generate_report') {
    // Autonomously gather database insights
    try {
        $pdo = getDBConnection();
        $report_data = [];

        // 1. Overall Pass/Fail
        $stmt = $pdo->query("SELECT result, COUNT(*) as count FROM transactions GROUP BY result");
        $report_data['overall_production'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2. Top Defects
        $stmt = $pdo->query("SELECT d.defect_name, COUNT(*) as count FROM transactions t JOIN defects d ON t.defect_id = d.id WHERE t.result = 'FAIL' GROUP BY d.defect_name ORDER BY count DESC LIMIT 5");
        $report_data['top_defects'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3. Station Failures
        $stmt = $pdo->query("SELECT s.station_name, COUNT(*) as failures FROM transactions t JOIN stations s ON t.station_id = s.id WHERE t.result = 'FAIL' GROUP BY s.station_name ORDER BY failures DESC LIMIT 5");
        $report_data['station_failures'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Work Order Progress
        $stmt = $pdo->query("SELECT w.wo_number, w.target_qty, COUNT(CASE WHEN t.result='PASS' THEN 1 END) as passed_units FROM work_orders w LEFT JOIN transactions t ON w.id = t.wo_id GROUP BY w.id LIMIT 5");
        $report_data['active_work_orders'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $report_data = ["error" => "Could not fetch database metrics: " . $e->getMessage()];
    }

    $prompt = "You are a Senior Manufacturing Data Analyst (Six Sigma Black Belt). I am providing you with an autonomously gathered dataset directly from our smart factory MySQL database.
    Your task is to write a highly comprehensive, professional, and insightful Executive Report based on this data. Do NOT ask the user for more info, analyze what is provided.
    
    CRITICAL INSTRUCTIONS:
    1. Output the response in purely raw HTML. Do NOT wrap it in markdown blocks.
    2. Use inline CSS for styling. Make it look like a professional corporate document (fonts like Arial, clean padding, dark blue headers, light gray borders, shadow effects).
    3. The report MUST include:
       - A professional header (e.g., 'Factory Intelligence & Yield Report').
       - An 'Executive Summary' explaining the overall health of production based on the numbers.
       - A 'Detailed Findings' section. Create beautiful HTML tables to display the top defects and station failures.
       - A 'Strategic Recommendations' section with bullet points on how to improve yield based on the defects/stations.
    4. Be highly analytical. Formulate insights on where the bottlenecks are.
    
    Here is the database extract: " . json_encode($report_data);

    $payload = [
        "contents" => [ [ "parts" => [ ["text" => $prompt] ] ] ],
        "generationConfig" => [ "temperature" => 0.5 ]
    ];
} 
// ==============================================================================
// SCENARIO B: NORMAL CHAT / SQL GENERATION
// ==============================================================================
else {
    if (empty($user_question)) {
        echo json_encode(["status" => "error", "message" => "Question cannot be empty."]);
        exit;
    }

    // 2. DEFINE THE SMART HYBRID SCHEMA & BUSINESS LOGIC
    $db_schema = "
You are the NationGate Smart Assistant. Your job is to help managers analyze manufacturing data and answer general questions politely.

Here is the MySQL database schema for the factory:
Table: work_orders (id, wo_number, project, target_qty)
Table: transactions (id, date_time, sn, result, aging, wo_id, emp_id, station_id, defect_id)
Table: employees (id, emp_name)
Table: stations (id, station_name)
Table: defects (id, defect_name)

CRITICAL BUSINESS LOGIC FOR SQL GENERATION:
A single product (sn) creates multiple rows in the 'transactions' table. You MUST use the following logic to perfectly match the dashboard's charts:
- 'Total Output' (or 'Produced'): ONLY count a unit if it is completely finished. A unit is finished if result = 'FAIL' OR the station is 'FIN#CS-DUST_PLUG'.
  Formula: COUNT(DISTINCT CASE WHEN t.result = 'FAIL' OR s.station_name = 'FIN#CS-DUST_PLUG' THEN t.sn END) (Note: Requires JOIN stations s ON t.station_id = s.id)
- 'Total Fail': COUNT(DISTINCT CASE WHEN t.result = 'FAIL' THEN t.sn END)
- 'Total Pass': COUNT(DISTINCT CASE WHEN t.result = 'PASS' AND s.station_name = 'FIN#CS-DUST_PLUG' THEN t.sn END)
- WARNING: If the user DOES NOT explicitly specify a Work Order number, DO NOT filter by wo_number. Calculate the metrics across all work orders. ONLY add 'w.wo_number = '...'' if the user actually types the specific WO number.
- 'First X Hours': The dashboard charts bucket data by CLOCK HOUR (e.g., 19:00:00). To calculate the first X hours, you MUST group ONLY terminal/finished units by clock hour, order ascending, LIMIT by X, and SUM the result using a subquery.
  Example to get total output for first 2 hours (assuming the user explicitly asked for WO 1212121):
  SELECT SUM(output) AS total_output FROM (
      SELECT COUNT(DISTINCT t.sn) as output
      FROM transactions t JOIN stations s ON t.station_id = s.id JOIN work_orders w ON t.wo_id = w.id
      WHERE w.wo_number = '1212121' AND (t.result = 'FAIL' OR s.station_name = 'FIN#CS-DUST_PLUG')
      GROUP BY DATE_FORMAT(t.date_time, '%Y-%m-%d %H:00:00')
      ORDER BY DATE_FORMAT(t.date_time, '%Y-%m-%d %H:00:00') ASC
      LIMIT 2
  ) subquery;
- If a user asks for 'today' or 'current' metrics, DO NOT add a DATE filter (like CURDATE()), because the database contains simulated historical data. Just calculate the overall totals.

CRITICAL RULES:
1. You MUST output your response in strict JSON format.
2. If the user asks for data, counts, or lists, return: {\"type\": \"sql\", \"query\": \"SELECT ... LIMIT 10\", \"message\": \"A friendly natural language sentence explaining what data you are showing.\"}
   - Always JOIN tables to get readable names. Never UPDATE/DELETE.
3. If the user says hello, asks an irrelevant question, or asks for general advice, act like a polite human and return: {\"type\": \"chat\", \"message\": \"Your conversational response here.\"}
   - If it's off-topic, politely pivot the conversation back to the factory dashboard.
";

    $prompt = $db_schema . "\n\nUser Question: " . $user_question;

    $payload = [
        "contents" => [ [ "parts" => [ ["text" => $prompt] ] ] ],
        "generationConfig" => [ 
            "temperature" => 0.0,
            "responseMimeType" => "application/json" 
        ]
    ];
}

// 3. AUTO-FALLBACK & RETRY ENGINE
$models_to_try = ['gemini-1.5-flash', 'gemini-1.5-pro', 'gemini-1.0-pro','gemini-2.5-flash','gemini-2.5-pro','gemini-3.1-pro','gemini-2.5-flash-lite', 'gemini-3.1-flash-lite'];
$response_data = null;
$api_error_msg = '';

foreach ($models_to_try as $model) {
    $url = "https://" . "generativelanguage.googleapis.com/v1beta/models/" . $model . ":generateContent?key=" . $GEMINI_API_KEY;
    
    $max_retries = 3;
    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); 
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Gives the API 2 minutes to stream the long HTML report
        
        $response = curl_exec($ch);
        $curl_err = curl_errno($ch);
        curl_close($ch);

        if ($curl_err) { $api_error_msg = "Network Error."; break; }
        
        $res_data = json_decode($response, true);
        
        if (isset($res_data['error'])) {
            $api_error_msg = $res_data['error']['message'];
            if (stripos($api_error_msg, 'high demand') !== false || stripos($api_error_msg, 'quota') !== false || stripos($api_error_msg, '503') !== false) {
                sleep(2); continue;
            }
            if (stripos($api_error_msg, 'not found') !== false) { break; }
        } else {
            $response_data = $res_data;
            break 2;
        }
    }
}

// 4. ROUTE THE RESPONSE
try {
    if (!$response_data) {
        throw new Exception("Google servers are extremely busy right now. Please try again in 1 minute. (Error: " . $api_error_msg . ")");
    }

    $json_string = $response_data['candidates'][0]['content']['parts'][0]['text'] ?? '';

    // ==============================================================================
    // INTERCEPT IF WE ARE GENERATING A REPORT (Return HTML)
    // ==============================================================================
    if ($action === 'generate_report') {
        $html_content = preg_replace('/^```html/i', '', $json_string);
        $html_content = preg_replace('/```$/i', '', $html_content);
        
        echo json_encode([
            "status" => "success", 
            "type" => "report", 
            "html" => trim($html_content)
        ]);
        exit;
    }

    // NORMAL CHAT ROUTING
    $ai_response = json_decode(trim($json_string), true);

    if (!$ai_response || !isset($ai_response['type'])) {
        throw new Exception("AI returned an invalid format.");
    }

    // SCENARIO A: CHAT MODE
    if ($ai_response['type'] === 'chat') {
        echo json_encode([
            "status" => "success", 
            "type" => "chat", 
            "message" => $ai_response['message']
        ]);
        exit;
    }

    // SCENARIO B: SQL MODE
    if ($ai_response['type'] === 'sql') {
        $generated_sql = $ai_response['query'];

        if (stripos(strtoupper($generated_sql), 'SELECT') !== 0) {
            throw new Exception("Security Violation: Only SELECT queries are permitted.");
        }

        $pdo = getDBConnection(); 
        $stmt = $pdo->query($generated_sql);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "status" => "success", 
            "type" => "sql",
            "message" => $ai_response['message'] ?? "Here are the results:",
            "sql" => $generated_sql, 
            "data" => $results
        ]);
        exit;
    }

} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}
?>