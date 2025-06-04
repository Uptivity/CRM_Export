<?php
session_start();

// Function to load .env file
function loadEnv($filePath) {
    if (!file_exists($filePath)) {
        die("Error: .env file not found at $filePath");
    }
    
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '//') === 0 || strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parse key=value pairs
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes if present
            $value = trim($value, '"\'');
            
            // Set as environment variable
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// Load environment variables from .env file
loadEnv(__DIR__ . '/.env');

// Configuration from .env file
$ZOHO_CLIENT_ID = $_ENV['ZOHO_CLIENT_ID'] ?? '';
$ZOHO_CLIENT_SECRET = $_ENV['ZOHO_CLIENT_SECRET'] ?? '';
$ZOHO_ACCOUNTS_URL = $_ENV['ZOHO_ACCOUNTS_URL'] ?? '';
$ZOHO_REFRESH_TOKEN = $_ENV['ZOHO_REFRESH_TOKEN'] ?? '';
$ORG_ID = $_ENV['ORG_ID'] ?? '';
$ZOHO_OAUTH_TOKEN = $_ENV['ZOHO_OAUTH_TOKEN'] ?? '';
$ZOHO_API_BASE_URL = $_ENV['ZOHO_API_BASE_URL'] ?? '';

$VALID_USERNAME = $_ENV['CRM_USERNAME'] ?? '';
$VALID_PASSWORD = $_ENV['CRM_PASSWORD'] ?? '';

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($username === $VALID_USERNAME && $password === $VALID_PASSWORD) {
        $_SESSION['logged_in'] = true;
        header("Location: " . $_SERVER['PHP_SELF']);
        exit;
    } else {
        $login_error = "Invalid username or password";
    }
}

// Check if user is logged in
$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

// Helper Functions
function getAccessToken() {
    global $ZOHO_ACCOUNTS_URL, $ZOHO_CLIENT_ID, $ZOHO_CLIENT_SECRET, $ZOHO_REFRESH_TOKEN;
    
    $url = $ZOHO_ACCOUNTS_URL . "/oauth/v2/token";
    $data = [
        'refresh_token' => $ZOHO_REFRESH_TOKEN,
        'client_id' => $ZOHO_CLIENT_ID,
        'client_secret' => $ZOHO_CLIENT_SECRET,
        'grant_type' => 'refresh_token',
        'scope' => 'ZohoCRM.modules.ALL'
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $responseData = json_decode($response, true);
        if (isset($responseData['access_token'])) {
            return $responseData['access_token'];
        }
    }
    
    throw new Exception("Failed to get access token: " . $response);
}

function fetchAllLeads($accessToken) {
    global $ZOHO_API_BASE_URL;
    
    $headers = [
        "Authorization: Zoho-oauthtoken " . $accessToken,
        "Content-Type: application/json"
    ];
    
    $allLeads = [];
    $page = 1;
    $perPage = 200;
    
    while (true) {
        $url = $ZOHO_API_BASE_URL . "/Leads?" . http_build_query([
            'page' => $page,
            'per_page' => $perPage
        ]);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data['data'])) {
                $leads = $data['data'];
                $allLeads = array_merge($allLeads, $leads);
                
                if (count($leads) === $perPage) {
                    $page++;
                    continue;
                } else {
                    break;
                }
            } else {
                break;
            }
        } else {
            throw new Exception("Failed to fetch leads: " . $response);
        }
    }
    
    // Filter leads that have meaningful data for follow-up
    $filteredLeads = [];
    foreach ($allLeads as $lead) {
        $nextActionDate = $lead['Next_Action_Date'] ?? '';
        $leadStatusRaw = $lead['Lead_Status'] ?? '';
        $leadStatus = strtolower($leadStatusRaw);
        
        if ($nextActionDate || 
            strpos($leadStatus, 'follow') !== false || 
            strpos($leadStatus, 'demo') !== false || 
            strpos($leadStatus, 'proposal') !== false ||
            strpos($leadStatus, 'contact') !== false) {
            $filteredLeads[] = $lead;
        }
    }
    
    return $filteredLeads;
}

function calculatePriorityScore($lead) {
    $closeness = $lead['Closeness'] ?? 0;
    $monthlyValue = $lead['Lead_Value_Monthly'] ?? 0;
    
    // If no monthly value, estimate based on interested product (simplified, no PRODUCT_VALUES)
    if (!$monthlyValue) {
        $monthlyValue = 0; // Default to 0 as requested
    }
    
    // Scale value to 0-40 points (40% of total score)
    $valueScore = $monthlyValue > 0 ? min($monthlyValue / 2000, 40) : 0;
    
    // Scale closeness to 0-60 points (60% of total score)
    $closenessScore = $closeness * 6; // closeness is 0-10, so this gives 0-60
    
    // Combine scores and ensure it's between 1-100
    $priorityScore = $closenessScore + $valueScore;
    
    // Ensure minimum of 1 and maximum of 100
    $priorityScore = max(1, min(100, $priorityScore));
    
    return round($priorityScore);
}

function mapStatusToDisplay($status) {
    if (!$status) {
        return 'Not Contacted';
    }
    
    $status = strtolower(trim($status));
    $mapping = [
        'attempted to contact' => ['attempted to contact', 'tried to contact', 'call attempt'],
        'contacted dm' => ['contacted dm', 'spoke to dm', 'dm spoken'],
        'contacted gk' => ['contacted gk', 'spoke to gk', 'gk spoken'],
        'converted' => ['converted', 'closed won', 'deal confirmed'],
        "don't know" => ["don't know", 'unknown', 'uncertain'],
        'going nowhere' => ['going nowhere', 'dead', 'lost', 'no interest'],
        'not contacted' => ['not contacted', 'no contact', 'uncontacted'],
        'on/had trial' => ['on trial', 'had trial', 'trial'],
        'partner or government contact' => ['partner', 'government', 'govt'],
        'pre-qualified' => ['pre-qualified', 'prequalified', 'qualified'],
        'sent email (mass or generic)' => ['sent email', 'mass email', 'generic email'],
        'sent personal email' => ['sent personal email', 'personal email'],
    ];
    
    foreach ($mapping as $display => $keywords) {
        foreach ($keywords as $kw) {
            if (strpos($status, $kw) !== false) {
                return $display !== "don't know" ? ucwords($display) : "Don't know";
            }
        }
    }
    
    return ucwords($status);
}

function mapStatusToStage($presentStatus) {
    if (!$presentStatus) {
        return "notContacted";
    }
    
    $displayStatus = strtolower(mapStatusToDisplay($presentStatus));
    
    if (in_array($displayStatus, ["attempted to contact", "not contacted", "going nowhere", "don't know"])) {
        return "notContacted";
    } elseif (in_array($displayStatus, ["contacted dm", "contacted gk", "sent personal email", "sent email (mass or generic)", "pre-qualified"])) {
        return "discovery";
    } elseif (in_array($displayStatus, ["on/had trial", "partner or government contact"]) || strpos($displayStatus, "demo") !== false) {
        return "demo";
    } elseif (in_array($displayStatus, ["proposal sent", "in pricing discussion"]) || strpos($displayStatus, "proposal") !== false) {
        return "proposal";
    } elseif (in_array($displayStatus, ["converted"])) {
        return "won";
    }
    
    return "notContacted";
}

function countContactAttempts($lead) {
    return [
        'email' => $lead['Email_Count'] ?? 0,
        'phone' => $lead['Phone_Count'] ?? 0,
        'whatsapp' => $lead['WhatsApp_Count'] ?? 0
    ];
}

function formatDateForDisplay($dateStr) {
    if (!$dateStr) {
        return "";
    }
    
    try {
        if (strpos($dateStr, 'T') !== false) {
            $dateStr = explode('T', $dateStr)[0];
        }
        
        $dt = new DateTime($dateStr);
        return $dt->format('d/m/Y');
    } catch (Exception $e) {
        return $dateStr;
    }
}

function getLastContactedDate($lead) {
    return $lead['Last_Contacted'] ?? '';
}

// Main dashboard logic
$dashboardLeads = [];
$error = null;

if ($is_logged_in) {
    try {
        $accessToken = getAccessToken();
        $leads = fetchAllLeads($accessToken);
        
        foreach ($leads as $lead) {
            // Extract basic info with correct field mapping
            $firstName = $lead['First_Name'] ?? '';
            if (!$firstName) {
                $fullName = $lead['Full_Name'] ?? '';
                if ($fullName && strpos($fullName, '@') === false) {
                    $firstName = explode(' ', $fullName)[0] ?: "Unknown";
                } else {
                    $email = $lead['Email'] ?? '';
                    if ($email) {
                        $firstName = ucwords(str_replace('.', ' ', explode('@', $email)[0]));
                    } else {
                        $firstName = "Unknown";
                    }
                }
            }
            
            $company = $lead['Company'] ?? $lead['Account_Name'] ?? "No Company";
            $nextAction = $lead['Next_Action'] ?? "Follow up required";
            $nextActionDate = $lead['Next_Action_Date'] ?? "";
              // Extract owner name from Owner dict
            $ownerInfo = $lead['Owner'] ?? [];
            $allocatedTo = is_array($ownerInfo) ? ($ownerInfo['name'] ?? "Unassigned") : ((string)$ownerInfo ?: "Unassigned");
            
            // Use Products_of_interest field
            $productsList = $lead['Products_of_interest'] ?? [];
            if (is_array($productsList) && !empty($productsList)) {
                $interestedProduct = (string)$productsList[0];
            } else {
                $interestedProduct = $lead['Product_Interest'] ?? $lead['Product'] ?? "";
            }
            
            $presentStatus = $lead['Lead_Status'] ?? $lead['Present_Status'] ?? "";
            $displayStatus = mapStatusToDisplay($presentStatus);
            
            $priorityScore = calculatePriorityScore($lead);
            $stage = mapStatusToStage($presentStatus);
            
            $estimatedValue = $lead['Lead_Value_Monthly'] ?? 0;
            if (!$estimatedValue) {
                $estimatedValue = "N/A";
            }
            
            $contactCounts = countContactAttempts($lead);
            $lastContacted = getLastContactedDate($lead);
            $leadClassification = $lead['Lead_Classification'] ?? "Lead";
            $vertical = $lead['Vertical'] ?? "Unknown";
            $industry = $lead['Industry'] ?? "Unknown";
            
            $dashboardLeads[] = [
                'id' => $lead['id'] ?? '',
                'first_name' => $firstName,
                'company' => $company,
                'next_action' => $nextAction,
                'next_action_date' => $nextActionDate,
                'next_action_date_formatted' => formatDateForDisplay($nextActionDate),
                'allocated_to' => $allocatedTo,
                'present_status' => $presentStatus,
                'display_status' => $displayStatus,
                'stage' => $stage,
                'priority_score' => $priorityScore,
                'estimated_value' => $estimatedValue,
                'interested_product' => $interestedProduct,
                'contact_counts' => $contactCounts,
                'last_contacted' => formatDateForDisplay($lastContacted),
                'lead_classification' => $leadClassification,
                'vertical' => $vertical,
                'industry' => $industry,
                'closeness' => $lead['Closeness'] ?? 0
            ];
        }
        
        // Sort leads by priority within each stage
        usort($dashboardLeads, function($a, $b) {
            if ($a['priority_score'] === $b['priority_score']) {
                return strcmp($a['stage'], $b['stage']);
            }
            return $b['priority_score'] - $a['priority_score'];
        });
        
    } catch (Exception $e) {
        $error = "Error fetching data: " . $e->getMessage();
    }
}

// Prepare data for JavaScript
$jsLeadsData = json_encode($dashboardLeads);

// Calculate totals
$totalAll = count($dashboardLeads);
$totalNotContacted = count(array_filter($dashboardLeads, function($lead) { return $lead['stage'] === 'notContacted'; }));
$totalDiscovery = count(array_filter($dashboardLeads, function($lead) { return $lead['stage'] === 'discovery'; }));
$totalDemo = count(array_filter($dashboardLeads, function($lead) { return $lead['stage'] === 'demo'; }));
$totalProposal = count(array_filter($dashboardLeads, function($lead) { return $lead['stage'] === 'proposal'; }));
$totalWon = count(array_filter($dashboardLeads, function($lead) { return $lead['stage'] === 'won'; }));

// Collect unique values for filters
$uniqueProducts = array_unique(array_filter(array_column($dashboardLeads, 'interested_product')));
$uniqueIndustries = array_unique(array_filter(array_column($dashboardLeads, 'industry'), function($industry) { return $industry !== 'Unknown'; }));
$uniqueSectors = array_unique(array_filter(array_column($dashboardLeads, 'vertical'), function($vertical) { return $vertical !== 'Unknown'; }));

sort($uniqueProducts);
sort($uniqueIndustries);
sort($uniqueSectors);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_logged_in ? 'CRM Follow-Up Dashboard' : 'CRM Dashboard Login'; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f5f7f9;
            color: #333;
        }

        <?php if (!$is_logged_in): ?>
        .login-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .login-box {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }
        
        .login-box h1 {
            text-align: center;
            margin-bottom: 30px;
            color: #333;
            font-size: 24px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
        }
        
        .form-group input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 2px rgba(102, 126, 234, 0.2);
        }
        
        .login-btn {
            width: 100%;
            padding: 12px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .login-btn:hover {
            background: #5a6fd8;
        }
        
        .error-message {
            background: #fee;
            color: #c33;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #fcc;
        }
        <?php else: ?>
        .header {
            background: white;
            padding: 16px 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
        }
        
        .header-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .header h1 {
            font-size: 18px;
            font-weight: 600;
            color: #1a1a1a;
            margin: 0;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .total-badge {
            background: #f97316;
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .logout-btn {
            background: #dc2626;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
        }
        
        .logout-btn:hover {
            background: #b91c1c;
        }
        
        .filters-row {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }
        
        .filter-item {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        select, input {
            padding: 6px 10px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            font-size: 13px;
            background-color: white;
            color: #333;
            min-width: 100px;
            cursor: pointer;
        }
        
        select:focus, input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 1px #3b82f6;
        }
        
        .date-input {
            width: 120px;
        }
        
        .content {
            display: flex;
            flex-direction: column;
            gap: 24px;
            padding: 24px;
            height: calc(100vh - 140px);
        }
        
        .main-kanban {
            flex: 1;
            display: flex;
            gap: 20px;
            overflow-x: auto;
        }
        
        .sidebar {
            background: white;
            border-radius: 8px;
            padding: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            height: fit-content;
        }
        
        .sidebar h3 {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 12px;
            color: #374151;
        }
        
        #starContacts {
            display: flex;
            gap: 16px;
            overflow-x: auto;
            padding-bottom: 8px;
        }
        
        .star-contact {
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: #f9fafb;
            cursor: pointer;
            transition: background 0.2s;
            min-width: 200px;
            flex-shrink: 0;
        }
        
        .star-contact:hover {
            background: #f3f4f6;
        }
        
        .star-name {
            font-weight: 600;
            font-size: 13px;
            color: #111827;
            margin-bottom: 4px;
        }
        
        .star-company {
            font-size: 12px;
            color: #6b7280;
            margin-bottom: 8px;
        }
        
        .star-details {
            font-size: 11px;
            color: #6b7280;
            line-height: 1.4;
        }
        
        .star-icons {
            display: flex;
            gap: 6px;
            margin-top: 8px;
        }
        
        .star-icon {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: bold;
            color: white;
        }
        
        .star-icon.email { background: #3b82f6; }
        .star-icon.phone { background: #10b981; }
        .star-icon.whatsapp { background: #25d366; }
        
        .column {
            flex: 1;
            min-width: 280px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            display: flex;
            flex-direction: column;
        }
        
        .column-header {
            padding: 12px 16px;
            font-weight: 600;
            font-size: 14px;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .column:nth-child(1) .column-header {
            color: #64748b;
            background-color: #f8fafc;
        }
        
        .column:nth-child(2) .column-header {
            color: #2563eb;
            background-color: #eff6ff;
        }
        
        .column:nth-child(3) .column-header {
            color: #7c3aed;
            background-color: #faf5ff;
        }
        
        .column:nth-child(4) .column-header {
            color: #059669;
            background-color: #ecfdf5;
        }
        
        .column:nth-child(5) .column-header {
            color: #dc2626;
            background-color: #fef2f2;
        }
        
        .column-count {
            background: rgba(0,0,0,0.08);
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .cards-container {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
        }
        
        .card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
        }
        
        .card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            transform: translateY(-1px);
        }
        
        .card.expanded {
            background: #fafbfc;
            border-color: #3b82f6;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
        }
        
        .priority-dot {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 22px;
            height: 22px;
            background: #dc2626;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: bold;
            color: white;
            z-index: 10;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .priority-dot.high {
            background: #dc2626;
        }
        
        .priority-dot.medium {
            background: #f97316;
        }
        
        .priority-dot.low {
            background: #65a30d;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }
        
        .card-name {
            font-weight: 600;
            font-size: 14px;
            color: #111827;
            line-height: 1.3;
        }
        
        .card-company {
            color: #6b7280;
            font-size: 12px;
            margin-bottom: 10px;
        }
        
        .card-action {
            font-size: 11px;
            color: #374151;
            margin-bottom: 8px;
        }
        
        .card-contact-icons {
            display: flex;
            gap: 6px;
            margin-bottom: 8px;
        }
        
        .contact-icon {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: bold;
            color: white;
            cursor: pointer;
            position: relative;
        }
        
        .contact-icon.email { background: #3b82f6; }
        .contact-icon.phone { background: #10b981; }
        .contact-icon.whatsapp { background: #25d366; }
        
        .estimated-value {
            color: #059669;
            font-weight: 600;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .card-details {
            display: none;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #e5e7eb;
            font-size: 11px;
        }
        
        .card.expanded .card-details {
            display: block;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
        }
        
        .detail-label {
            font-weight: 500;
            color: #6b7280;
        }
        
        .detail-value {
            color: #374151;
            text-align: right;
            max-width: 60%;
            word-wrap: break-word;
        }
        
        .tooltip {
            position: absolute;
            bottom: 25px;
            left: 50%;
            transform: translateX(-50%);
            background: #111827;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 10px;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s;
            z-index: 1000;
        }
        
        .contact-icon:hover .tooltip {
            opacity: 1;
        }
        
        .hidden {
            display: none !important;
        }
        
        .refresh-btn {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            margin-left: 12px;
        }
        
        .refresh-btn:hover {
            background: #2563eb;
        }
        
        .error-banner {
            background: #fee;
            color: #c33;
            padding: 16px;
            margin: 20px;
            border-radius: 8px;
            border: 1px solid #fcc;
        }
        
        @media (max-width: 1200px) {
            .content {
                flex-direction: column;
                height: auto;
            }
            
            .sidebar {
                width: 100%;
                margin-bottom: 20px;
            }
            
            .main-kanban {
                flex-direction: column;
                height: auto;
            }
            
            .column {
                min-width: auto;
                max-height: 400px;
            }
        }
        <?php endif; ?>
    </style>
</head>
<body>
    <?php if (!$is_logged_in): ?>
    <div class="login-container">
        <div class="login-box">
            <h1>🔄 CRM Dashboard Login</h1>
            <?php if (isset($login_error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($login_error); ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label for="username">Username:</label>
                    <input type="text" id="username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="password">Password:</label>
                    <input type="password" id="password" name="password" required>
                </div>
                <button type="submit" name="login" class="login-btn">Login</button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <?php if ($error): ?>
        <div class="error-banner"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    
    <div class="header">
        <div class="header-top">
            <h1>🔄 CRM Follow-Up Dashboard</h1>
            <div class="header-right">
                <div class="total-badge">Total <?php echo $totalAll; ?></div>
                <a href="?logout=1" class="logout-btn">Logout</a>
            </div>
        </div>
        
        <div class="filters-row">
            <div class="filter-item">
                <label for="dateFrom">From Date:</label>
                <input type="date" id="dateFrom" class="date-input">
            </div>
            <div class="filter-item">
                <label for="dateTo">To Date:</label>
                <input type="date" id="dateTo" class="date-input">
            </div>
            <div class="filter-item">
                <select id="timeFilter">
                    <option value="thisWeek">This Week</option>
                    <option value="today">Today</option>
                    <option value="thisMonth">This Month</option>
                    <option value="all">All Time</option>
                </select>
            </div>
            <div class="filter-item">
                <select id="ownerFilter">
                    <option value="all">All Industries</option>
                </select>
            </div>
            <div class="filter-item">
                <select id="productFilter">
                    <option value="all">All Products</option>
                    <?php foreach ($uniqueProducts as $product): ?>
                        <option value="<?php echo htmlspecialchars($product); ?>"><?php echo htmlspecialchars($product); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-item">
                <select id="sectorFilter">
                    <option value="all">All Sectors</option>
                    <?php foreach ($uniqueSectors as $sector): ?>
                        <option value="<?php echo htmlspecialchars($sector); ?>"><?php echo htmlspecialchars($sector); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="refresh-btn" onclick="refreshDashboard()">Refresh</button>
        </div>
    </div>

    <div class="content">
        <div class="main-kanban">
            <div class="column">
                <div class="column-header">
                    Not Contacted
                    <span class="column-count" id="notContactedColumnCount">0</span>
                </div>
                <div class="cards-container" id="notContactedCards"></div>
            </div>
            
            <div class="column">
                <div class="column-header">
                    Discovery
                    <span class="column-count" id="discoveryColumnCount">0</span>
                </div>
                <div class="cards-container" id="discoveryCards"></div>
            </div>
            
            <div class="column">
                <div class="column-header">
                    Demo
                    <span class="column-count" id="demoColumnCount">0</span>
                </div>
                <div class="cards-container" id="demoCards"></div>
            </div>
            
            <div class="column">
                <div class="column-header">
                    Proposal
                    <span class="column-count" id="proposalColumnCount">0</span>
                </div>
                <div class="cards-container" id="proposalCards"></div>
            </div>
            
            <div class="column">
                <div class="column-header">
                    Won
                    <span class="column-count" id="wonColumnCount">0</span>
                </div>
                <div class="cards-container" id="wonCards"></div>
            </div>
        </div>
        
        <div class="sidebar">
            <h3>⭐ Star Contacts</h3>
            <div id="starContacts">
                <!-- Star contacts will be populated by JavaScript -->
            </div>
        </div>
    </div>

    <script>
        // Lead data from PHP
        const leadsData = <?php echo $jsLeadsData; ?>;
        let filteredLeads = [...leadsData];
        let expandedCards = new Set();
        
        document.addEventListener('DOMContentLoaded', function() {
            setupEventListeners();
            populateStarContacts();
            renderCards();
        });
        
        function setupEventListeners() {
            document.getElementById('timeFilter').addEventListener('change', applyFilters);
            document.getElementById('ownerFilter').addEventListener('change', applyFilters);
            document.getElementById('dateFrom').addEventListener('change', applyFilters);
            document.getElementById('dateTo').addEventListener('change', applyFilters);
            document.getElementById('productFilter').addEventListener('change', applyFilters);
            document.getElementById('sectorFilter').addEventListener('change', applyFilters);
        }
        
        function populateStarContacts() {
            const starContacts = leadsData
                .filter(lead => lead.priority_score > 50 || lead.closeness >= 8)
                .sort((a, b) => b.priority_score - a.priority_score)
                .slice(0, 5);
            
            const container = document.getElementById('starContacts');
            container.innerHTML = '';
            
            if (starContacts.length === 0) {
                container.innerHTML = '<div style="color: #6b7280; font-size: 12px; text-align: center; padding: 20px;">No star contacts found</div>';
                return;
            }
            
            starContacts.forEach(lead => {
                const starElement = document.createElement('div');
                starElement.className = 'star-contact';
                starElement.innerHTML = `
                    <div class="star-name">${lead.first_name}</div>
                    <div class="star-company">${lead.company}</div>
                    <div class="star-details">
                        ${lead.next_action}
                    </div>
                    <div class="star-icons">
                        <div class="star-icon email">${lead.contact_counts.email || 0}</div>
                        <div class="star-icon phone">${lead.contact_counts.phone || 0}</div>
                        <div class="star-icon whatsapp">${lead.contact_counts.whatsapp || 0}</div>
                    </div>
                `;
                starElement.addEventListener('click', () => scrollToLead(lead.id));
                container.appendChild(starElement);
            });
        }
        
        function scrollToLead(leadId) {
            const cardElement = document.querySelector(`[data-lead-id="${leadId}"]`);
            if (cardElement) {
                cardElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                cardElement.style.border = '2px solid #3b82f6';
                setTimeout(() => {
                    cardElement.style.border = '1px solid #e5e7eb';
                }, 3000);
            }
        }
        
        function applyFilters() {
            const timeFilter = document.getElementById('timeFilter').value;
            const ownerFilter = document.getElementById('ownerFilter').value;
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;
            const productFilter = document.getElementById('productFilter').value;
            const sectorFilter = document.getElementById('sectorFilter').value;

            filteredLeads = [...leadsData];

            // Apply date range filter first (takes precedence over time filter)
            if (dateFrom || dateTo) {
                filteredLeads = filteredLeads.filter(lead => {
                    if (!lead.next_action_date) return true;
                    const nextActionDateStr = lead.next_action_date.split('T')[0];
                    
                    let withinRange = true;
                    
                    if (dateFrom) {
                        withinRange = withinRange && nextActionDateStr >= dateFrom;
                    }
                    
                    if (dateTo) {
                        withinRange = withinRange && nextActionDateStr <= dateTo;
                    }
                    
                    return withinRange;
                });
            } else if (timeFilter && timeFilter !== 'all') {
                // Only apply time filter if no date range is specified
                filteredLeads = filteredLeads.filter(lead => {
                    if (!lead.next_action_date) return true;
                    const nextActionDateStr = lead.next_action_date.split('T')[0];
                    const today = new Date();
                    const todayStr = today.toISOString().split('T')[0];
                    
                    if (timeFilter === 'today') {
                        return nextActionDateStr === todayStr;
                    } else if (timeFilter === 'thisWeek') {
                        const weekStart = new Date(today);
                        weekStart.setDate(today.getDate() - today.getDay());
                        const weekStartStr = weekStart.toISOString().split('T')[0];
                        const weekEnd = new Date(weekStart);
                        weekEnd.setDate(weekStart.getDate() + 6);
                        const weekEndStr = weekEnd.toISOString().split('T')[0];
                        return nextActionDateStr >= weekStartStr && nextActionDateStr <= weekEndStr;
                    } else if (timeFilter === 'thisMonth') {
                        const monthStart = new Date(today.getFullYear(), today.getMonth(), 1);
                        const monthEnd = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                        const monthStartStr = monthStart.toISOString().split('T')[0];
                        const monthEndStr = monthEnd.toISOString().split('T')[0];
                        return nextActionDateStr >= monthStartStr && nextActionDateStr <= monthEndStr;
                    }
                    return true;
                });
            }
            
            if (ownerFilter && ownerFilter !== 'all') {
                filteredLeads = filteredLeads.filter(lead => lead.allocated_to === ownerFilter);
            }
            
            if (productFilter && productFilter !== 'all') {
                filteredLeads = filteredLeads.filter(lead => lead.interested_product === productFilter);
            }
            
            if (sectorFilter && sectorFilter !== 'all') {
                filteredLeads = filteredLeads.filter(lead => lead.vertical === sectorFilter);
            }
            
            renderCards();
        }
        
        function renderCards() {
            const stages = ['notContacted', 'discovery', 'demo', 'proposal', 'won'];
            const stageCounts = { notContacted: 0, discovery: 0, demo: 0, proposal: 0, won: 0 };
            
            stages.forEach(stage => {
                document.getElementById(stage + 'Cards').innerHTML = '';
            });
            
            const leadsByStage = {
                notContacted: [],
                discovery: [],
                demo: [],
                proposal: [],
                won: []
            };
            
            filteredLeads.forEach(lead => {
                const stage = lead.stage || 'notContacted';
                if (!leadsByStage[stage]) {
                    leadsByStage.notContacted.push(lead);
                    stageCounts.notContacted++;
                } else {
                    leadsByStage[stage].push(lead);
                    stageCounts[stage]++;
                }
            });
            
            Object.keys(leadsByStage).forEach(stage => {
                leadsByStage[stage].sort((a, b) => b.priority_score - a.priority_score);
            });
            
            stages.forEach(stage => {
                const container = document.getElementById(stage + 'Cards');
                leadsByStage[stage].forEach(lead => {
                    container.appendChild(createCardElement(lead));
                });
            });
            
            document.getElementById('notContactedColumnCount').textContent = stageCounts.notContacted;
            document.getElementById('discoveryColumnCount').textContent = stageCounts.discovery;
            document.getElementById('demoColumnCount').textContent = stageCounts.demo;
            document.getElementById('proposalColumnCount').textContent = stageCounts.proposal;
            document.getElementById('wonColumnCount').textContent = stageCounts.won;
        }
        
        function createCardElement(lead) {
            const card = document.createElement('div');
            card.className = 'card';
            card.dataset.leadId = lead.id;
            
            if (expandedCards.has(lead.id)) {
                card.classList.add('expanded');
            }
            
            const formattedValue = lead.estimated_value && lead.estimated_value !== 'N/A' 
                ? `£${typeof lead.estimated_value === 'number' ? lead.estimated_value.toLocaleString() : lead.estimated_value}`
                : 'N/A';
            
            // Determine priority dot color based on score
            let priorityClass = 'low';
            if (lead.priority_score >= 70) {
                priorityClass = 'high';
            } else if (lead.priority_score >= 40) {
                priorityClass = 'medium';
            }
            
            card.innerHTML = `
                <div class="priority-dot ${priorityClass}" title="Priority Score: ${lead.priority_score}/100">
                    ${lead.priority_score}
                </div>
                <div class="card-header">
                    <div class="card-name">${lead.first_name}</div>
                </div>
                <div class="card-company">${lead.company}</div>
                <div class="card-action">${lead.next_action || 'No action set'}</div>
                <div class="card-contact-icons">
                    <div class="contact-icon email" title="Email contacts">
                        ${lead.contact_counts.email || 0}
                        <div class="tooltip">${lead.contact_counts.email || 0} emails</div>
                    </div>
                    <div class="contact-icon phone" title="Phone contacts">
                        ${lead.contact_counts.phone || 0}
                        <div class="tooltip">${lead.contact_counts.phone || 0} calls</div>
                    </div>
                    <div class="contact-icon whatsapp" title="WhatsApp contacts">
                        ${lead.contact_counts.whatsapp || 0}
                        <div class="tooltip">${lead.contact_counts.whatsapp || 0} messages</div>
                    </div>
                </div>
                <div class="estimated-value">
                    💰 ${formattedValue} ${lead.interested_product ? `(${lead.interested_product})` : ''}
                </div>
                
                <div class="card-details">
                    <div class="detail-row">
                        <span class="detail-label">Next Action Date:</span>
                        <span class="detail-value">${lead.next_action_date_formatted || 'Not set'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Allocated To:</span>
                        <span class="detail-value">${lead.allocated_to}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Status:</span>
                        <span class="detail-value">${lead.present_status || 'Not set'}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Priority Score:</span>
                        <span class="detail-value">${lead.priority_score}/100</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Classification:</span>
                        <span class="detail-value">${lead.lead_classification}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Vertical:</span>
                        <span class="detail-value">${lead.vertical}</span>
                    </div>
                    <div class="detail-row">
                        <span class="detail-label">Closeness:</span>
                        <span class="detail-value">${lead.closeness}/10</span>
                    </div>
                </div>
            `;
            
            card.addEventListener('click', function() {
                const isExpanded = expandedCards.has(lead.id);
                if (isExpanded) {
                    expandedCards.delete(lead.id);
                    card.classList.remove('expanded');
                } else {
                    expandedCards.add(lead.id);
                    card.classList.add('expanded');
                }
            });
            
            return card;
        }
        
        function refreshDashboard() {
            console.log('Refreshing dashboard...');
            location.reload();
        }
    </script>
    <?php endif; ?>
</body>
</html>
