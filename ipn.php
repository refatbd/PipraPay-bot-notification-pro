<?php

// Standard PipraPay loader to ensure the environment is ready
if (file_exists(__DIR__."/../../../../pp-config.php")) {
    if (file_exists(__DIR__.'/../../../../maintenance.lock')) {
        if (file_exists(__DIR__.'/../../../../pp-include/pp-maintenance.php')) {
           include(__DIR__."/../../../../pp-include/pp-maintenance.php");
        } else {
            die('System is under maintenance. Please try again later.');
        }
        exit();
    } else {
        if (file_exists(__DIR__.'/../../../../pp-include/pp-controller.php')) { include(__DIR__."/../../../../pp-include/pp-controller.php"); } 
        else { exit(); }
        
        if (file_exists(__DIR__.'/../../../../pp-include/pp-model.php')) { include(__DIR__."/../../../../pp-include/pp-model.php"); } 
        else { exit(); }

        if (file_exists(__DIR__.'/../../../../pp-include/pp-view.php')) { include(__DIR__."/../../../../pp-include/pp-view.php"); } 
        else { exit(); }
    }
} else {
    exit();
}

if (!defined('pp_allowed_access')) {
    die('Direct access not allowed');
}

// Ensure functions are loaded
$func_file = __DIR__ . '/functions.php';
if (file_exists($func_file)) {
    require_once $func_file;
} else {
    die('Functions file missing');
}

// ----------------------------------------------------------------------
// 1. API REQUEST HANDLER (Inter-Site Communication)
// ----------------------------------------------------------------------
if (isset($_POST['telegram-bot-notification-pro-api'])) {
    $plugin_slug = 'telegram-bot-notification-pro';
    $settings = pp_get_plugin_setting($plugin_slug);
    $my_secret = $settings['api_secret'] ?? '';

    if (empty($my_secret)) {
        echo json_encode(['status' => false, 'message' => 'Server misconfigured']);
        exit();
    }

    $payload_json = $_POST['payload'] ?? '';
    $signature = $_POST['signature'] ?? '';
    $payload = json_decode($payload_json, true);

    if (!$payload || !tgnp_verify_signature($payload, $signature, $my_secret)) {
        echo json_encode(['status' => false, 'message' => 'Invalid Signature']);
        exit();
    }

    $action = $payload['action'] ?? '';

    // --- Action: Register Node (Hub Side) ---
    if ($action === 'register_node') {
        $nodes = json_decode($settings['connected_nodes_json'] ?? '[]', true);
        if (!is_array($nodes)) $nodes = [];

        $new_node = [
            'site_name' => $payload['site_name'],
            'site_url' => $payload['site_url'],
            'site_identifier' => $payload['site_identifier'],
            'connected_at' => date('Y-m-d H:i:s')
        ];

        // Update if exists, else add
        $found = false;
        foreach ($nodes as &$node) {
            if ($node['site_identifier'] === $new_node['site_identifier']) {
                $node = $new_node;
                $found = true;
                break;
            }
        }
        if (!$found) $nodes[] = $new_node;

        $settings['connected_nodes_json'] = json_encode($nodes);
        
        if (tgnp_save_settings($plugin_slug, $settings)) {
            echo json_encode(['status' => true, 'message' => 'Node Registered Successfully']);
        } else {
            echo json_encode(['status' => false, 'message' => 'Database Save Failed']);
        }
        exit();
    }

    // --- Action: Remote Command (Node Side) ---
    if ($action === 'remote_command') {
        $command = $payload['command'] ?? '';
        
        // Execute the logic locally and return the text string
        $response_text = tgnp_execute_local_command($command); 
        echo json_encode(['status' => true, 'text' => $response_text]);
        exit();
    }

    // --- Action: Confirm Transaction (Node Side) ---
    if ($action === 'confirm_transaction') {
        $transaction_id = $payload['transaction_id'];

        if (pp_set_transaction_status($transaction_id, 'completed')) {
            if (function_exists('pp_trigger_hook')) {
                pp_trigger_hook('pp_transaction_ipn', $transaction_id);
            }
            echo json_encode(['status' => true, 'message' => 'Confirmed']);
        } else {
            echo json_encode(['status' => false, 'message' => 'Failed']);
        }
        exit();
    }

    exit(); // End API handling
}

// ----------------------------------------------------------------------
// 2. TELEGRAM WEBHOOK HANDLER (Hub Side Only)
// ----------------------------------------------------------------------

if (isset($_GET['telegram-bot-notification-pro'])) {

    $plugin_slug = 'telegram-bot-notification-pro';
    $settings = pp_get_plugin_setting($plugin_slug);
    $bot_token = $settings['bot_token'] ?? '';

    if (empty($bot_token)) {
        exit();
    }

    $update = json_decode(file_get_contents('php://input'), true);
    if (!$update) exit();

    // --- HELPER: Auth Check ---
    function is_chat_id_authorized($chat_id, $settings) {
        if (empty($settings['chat_ids_json'])) return false;
        $chat_ids = json_decode($settings['chat_ids_json'], true);
        if (empty($chat_ids) || !is_array($chat_ids)) return false;
        foreach ($chat_ids as $chat) {
            if (($chat['enabled'] ?? 'false') === 'true' && $chat['id'] == $chat_id) {
                return true;
            }
        }
        return false;
    }

    // ==================================================================
    // A. CALLBACK QUERY HANDLER (Button Clicks)
    // ==================================================================
    if (isset($update['callback_query'])) {
        $cq = $update['callback_query'];
        $chat_id = $cq['message']['chat']['id'];
        $message_id = $cq['message']['message_id'];
        $data = $cq['data'];
        $callback_id = $cq['id'];

        if (!is_chat_id_authorized($chat_id, $settings)) {
            tgnp_call_telegram_api($bot_token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => '🚫 Authorization Failed']);
            exit();
        }

        // 1. Transaction Confirmation Logic: cp|{site_id}|{trx_id}
        //    Legacy support: confirm_pending_{trx_id} (Assume local)
        if (strpos($data, 'cp|') === 0 || strpos($data, 'confirm_pending_') === 0) {
            
            // Parse Data
            $site_identifier = 'local';
            $transaction_id = 0;
            
            if (strpos($data, 'cp|') === 0) {
                $parts = explode('|', $data);
                $site_identifier = $parts[1] ?? 'local';
                $transaction_id = $parts[2] ?? 0;
            } else {
                $transaction_id = str_replace('confirm_pending_', '', $data); // Legacy
            }

            // Ask for Final Confirmation
            $reply_markup = [
                'inline_keyboard' => [[
                    ['text' => '✅ Yes, Confirm', 'callback_data' => "cf|{$site_identifier}|{$transaction_id}"],
                    ['text' => '❌ Cancel', 'callback_data' => "cx|{$site_identifier}|{$transaction_id}"]
                ]]
            ];
            
            tgnp_call_telegram_api($bot_token, 'editMessageText', [
                'chat_id' => $chat_id, 
                'message_id' => $message_id, 
                'text' => $cq['message']['text'] . "\n\n*⚠️ Are you sure?*", 
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode($reply_markup)
            ]);
            tgnp_call_telegram_api($bot_token, 'answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }

        // 2. Final Confirmation: cf|{site_id}|{trx_id}
        elseif (strpos($data, 'cf|') === 0) {
            $parts = explode('|', $data);
            $site_id = $parts[1];
            $trx_id = $parts[2];

            $success = false;
            $fail_msg = "Failed";

            if ($site_id === 'local' || $site_id === ($settings['site_identifier'] ?? '')) {
                // Local Execution
                if (pp_set_transaction_status($trx_id, 'completed')) {
                    if (function_exists('pp_trigger_hook')) pp_trigger_hook('pp_transaction_ipn', $trx_id);
                    $success = true;
                }
            } else {
                // Remote Execution via API
                $node = tgnp_get_node_by_id($site_id, $settings);
                if ($node) {
                    $res = tgnp_send_remote_request($node['site_url'] . '/pp-content/plugins/modules/telegram-bot-notification-pro/ipn.php', 'confirm_transaction', ['transaction_id' => $trx_id], $settings['api_secret']);
                    if ($res['status'] ?? false) $success = true;
                    else $fail_msg = $res['message'] ?? 'Remote Error';
                } else {
                    $fail_msg = "Node not found";
                }
            }

            if ($success) {
                $new_text = preg_replace("/\n\n\*⚠️ Are you sure.*\*/", "", $cq['message']['text']);
                $new_text = str_replace("⚪️ *New Transaction: Pending*", "✅ *Transaction Confirmed: Completed*", $new_text);
                $new_text .= "\n\n✅ *Confirmed via Telegram*";
                
                tgnp_call_telegram_api($bot_token, 'editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $new_text, 'parse_mode' => 'Markdown']);
                tgnp_call_telegram_api($bot_token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => 'Success!']);
            } else {
                tgnp_call_telegram_api($bot_token, 'answerCallbackQuery', ['callback_query_id' => $callback_id, 'text' => "Error: $fail_msg", 'show_alert' => true]);
            }
        }

        // 3. Cancel Confirmation: cx|...
        elseif (strpos($data, 'cx|') === 0) {
             $parts = explode('|', $data);
             $site_id = $parts[1];
             $trx_id = $parts[2];
             
             // Revert text
             $original_text = preg_replace("/\n\n\*⚠️ Are you sure.*\*/", "", $cq['message']['text']);
             $markup = ['inline_keyboard' => [[['text' => 'Confirm Pending Transaction', 'callback_data' => "cp|{$site_id}|{$trx_id}"]]]];
             
             tgnp_call_telegram_api($bot_token, 'editMessageText', ['chat_id' => $chat_id, 'message_id' => $message_id, 'text' => $original_text, 'parse_mode' => 'Markdown', 'reply_markup' => json_encode($markup)]);
             tgnp_call_telegram_api($bot_token, 'answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }

        // 4. Command Menu Selection: cmd|{site_id}|{command}
        elseif (strpos($data, 'cmd|') === 0) {
            $parts = explode('|', $data);
            $site_id = $parts[1];
            $command = $parts[2]; // e.g., /sales_today

            $reply_text = "Processing...";
            
            if ($site_id === 'local' || $site_id === ($settings['site_identifier'] ?? '')) {
                $reply_text = tgnp_execute_local_command($command);
            } else {
                $node = tgnp_get_node_by_id($site_id, $settings);
                if ($node) {
                    $res = tgnp_send_remote_request($node['site_url'] . '/pp-content/plugins/modules/telegram-bot-notification-pro/ipn.php', 'remote_command', ['command' => $command], $settings['api_secret']);
                    $reply_text = $res['text'] ?? "❌ Failed to fetch data from node.";
                } else {
                    $reply_text = "❌ Site not found.";
                }
            }

            // Edit the "Choose Site" message with the result
            tgnp_call_telegram_api($bot_token, 'editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => $reply_text,
                'parse_mode' => 'Markdown'
            ]);
            tgnp_call_telegram_api($bot_token, 'answerCallbackQuery', ['callback_query_id' => $callback_id]);
        }

        exit();
    }


    // ==================================================================
    // B. TEXT COMMAND HANDLER
    // ==================================================================
    $message_text = $update['message']['text'] ?? '';
    $chat_id = $update['message']['chat']['id'] ?? null;

    if (!$chat_id) exit();

    $is_authorized = is_chat_id_authorized($chat_id, $settings);
    
    // Commands that require DB access
    $restricted_commands = [
        '/last_transaction', '/sales_today', '/sales_yesterday', '/sales_this_month',
        '/pending_transactions', '/failed_transactions', '/completed_transactions'
    ];

    if ($message_text === "/start") {
        $reply = "👋 Chat ID: `{$chat_id}`\n\nCopy this to your PipraPay settings.";
        tgnp_call_telegram_api($bot_token, 'sendMessage', ['chat_id' => $chat_id, 'text' => $reply, 'parse_mode' => 'Markdown']);
    } 
    elseif ($message_text === "/help") {
         $reply = "🤖 *Available Commands:*\n\n" .
             "🔹 `/start` - Get Chat ID\n" .
             "🔹 `/last_transaction` - Recent Transaction\n" .
             "🔹 `/sales_today` - Today's Sales\n" .
             "🔹 `/sales_yesterday` - Yesterday's Sales\n" .
             "🔹 `/sales_this_month` - This Month's Sales\n" .
             "🔹 `/pending_transactions` - Count Pending\n" .
             "🔹 `/failed_transactions` - Count Failed\n" .
             "🔹 `/completed_transactions` - Count Completed\n" .
             "🔹 `/help` - Show this list";
        tgnp_call_telegram_api($bot_token, 'sendMessage', ['chat_id' => $chat_id, 'text' => $reply, 'parse_mode' => 'Markdown']);
    }
    elseif (in_array($message_text, $restricted_commands)) {
        if (!$is_authorized) {
            tgnp_call_telegram_api($bot_token, 'sendMessage', ['chat_id' => $chat_id, 'text' => "🚫 Unauthorized Chat ID."]);
            exit();
        }

        // Check if there are connected nodes
        $nodes = json_decode($settings['connected_nodes_json'] ?? '[]', true);
        
        // Use case: Single Site (Standalone or Hub-only) -> Execute Immediately
        if (empty($nodes) || !is_array($nodes) || count($nodes) === 0) {
            $reply = tgnp_execute_local_command($message_text);
            tgnp_call_telegram_api($bot_token, 'sendMessage', ['chat_id' => $chat_id, 'text' => $reply, 'parse_mode' => 'Markdown']);
        } 
        // Use case: Multi-Site -> Show Menu
        else {
            $keyboard = [];
            
            // Add Local Site Button
            $local_name = $settings['site_name'] ?? 'Hub Site';
            $keyboard[] = [['text' => "🏢 $local_name (This Site)", 'callback_data' => "cmd|local|$message_text"]];

            // Add Remote Node Buttons
            foreach ($nodes as $node) {
                $node_name = $node['site_name'] ?? 'Unknown Site';
                $node_id = $node['site_identifier'];
                $keyboard[] = [['text' => "🏪 $node_name", 'callback_data' => "cmd|$node_id|$message_text"]];
            }

            tgnp_call_telegram_api($bot_token, 'sendMessage', [
                'chat_id' => $chat_id, 
                'text' => "🔢 *Select Site for Data:*", 
                'parse_mode' => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
        }
    }
}

// ----------------------------------------------------------------------
// 3. INTERNAL HELPER: Execute SQL Commands Locally
// ----------------------------------------------------------------------

function tgnp_execute_local_command($command) {
    global $conn, $db_host, $db_user, $db_pass, $db_name, $db_prefix;

    if (!isset($conn)) {
        $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($conn->connect_error) { return "Database Error"; }
    }

    $reply = "";

    try {
        switch ($command) {
            case '/last_transaction':
                $sql = "SELECT * FROM {$db_prefix}transaction ORDER BY id DESC LIMIT 1";
                $result = $conn->query($sql);
                if ($result && $result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    $reply = "*Last Transaction:*\n" .
                             "💰 `{$row['transaction_amount']} {$row['transaction_currency']}`\n" .
                             "👤 `{$row['c_name']}`\n" .
                             "📊 `{$row['transaction_status']}`\n" .
                             "🗓️ `{$row['created_at']}`";
                } else { $reply = "No transactions found."; }
                break;

            case '/sales_today':
                $start = date('Y-m-d 00:00:00'); $end = date('Y-m-d 23:59:59');
                $sql = "SELECT COALESCE(SUM(transaction_amount), 0) as total FROM {$db_prefix}transaction WHERE created_at >= '{$start}' AND created_at <= '{$end}' AND transaction_status = 'completed'";
                $reply = "📈 *Today:* `" . number_format($conn->query($sql)->fetch_assoc()['total'], 2) . "`";
                break;

            case '/sales_yesterday':
                $start = date('Y-m-d 00:00:00', strtotime('-1 day')); $end = date('Y-m-d 23:59:59', strtotime('-1 day'));
                $sql = "SELECT COALESCE(SUM(transaction_amount), 0) as total FROM {$db_prefix}transaction WHERE created_at >= '{$start}' AND created_at <= '{$end}' AND transaction_status = 'completed'";
                $reply = "📈 *Yesterday:* `" . number_format($conn->query($sql)->fetch_assoc()['total'], 2) . "`";
                break;

            case '/sales_this_month':
                $start = date('Y-m-01 00:00:00'); $end = date('Y-m-t 23:59:59');
                $sql = "SELECT COALESCE(SUM(transaction_amount), 0) as total FROM {$db_prefix}transaction WHERE created_at >= '{$start}' AND created_at <= '{$end}' AND transaction_status = 'completed'";
                $reply = "📅 *Month:* `" . number_format($conn->query($sql)->fetch_assoc()['total'], 2) . "`";
                break;
            
            case '/pending_transactions':
                $sql = "SELECT COUNT(*) as count FROM {$db_prefix}transaction WHERE transaction_status = 'pending'";
                $reply = "⚪️ *Pending:* `" . $conn->query($sql)->fetch_assoc()['count'] . "`";
                break;
            
            case '/failed_transactions':
                $sql = "SELECT COUNT(*) as count FROM {$db_prefix}transaction WHERE transaction_status = 'failed'";
                $reply = "❌ *Failed:* `" . $conn->query($sql)->fetch_assoc()['count'] . "`";
                break;

            case '/completed_transactions':
                $sql = "SELECT COUNT(*) as count FROM {$db_prefix}transaction WHERE transaction_status = 'completed'";
                $reply = "✅ *Completed:* `" . $conn->query($sql)->fetch_assoc()['count'] . "`";
                break;

            default:
                $reply = "Invalid command.";
        }
    } catch (Exception $e) {
        $reply = "Error: " . $e->getMessage();
    }
    
    return $reply;
}

function tgnp_get_node_by_id($id, $settings) {
    $nodes = json_decode($settings['connected_nodes_json'] ?? '[]', true);
    foreach ($nodes as $node) {
        if (($node['site_identifier'] ?? '') === $id) return $node;
    }
    return null;
}
?>