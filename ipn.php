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

// Check for the correct GET parameter from the webhook URL
if (isset($_GET['telegram-bot-notification-pro'])) {

    if (!function_exists('pp_get_plugin_setting')) {
        exit();
    }

    $plugin_slug = 'telegram-bot-notification-pro';
    $settings = pp_get_plugin_setting($plugin_slug);
    
    $update = json_decode(file_get_contents('php://input'), true);

    if (!$update || !isset($update['message'])) {
        exit();
    }

    $message_text = $update['message']['text'] ?? '';
    $chat_id = $update['message']['chat']['id'] ?? null;

    if (!$chat_id || empty($settings['bot_token'])) {
        exit();
    }
    
    $bot_token = $settings['bot_token'];
    $reply = '';

    if ($message_text === "/start") {
        $reply = "Here's your Chat ID: `{$chat_id}`\n\nCopy this and paste it into the Chat ID section in your PipraPay settings.";
    } else {
        global $conn, $db_host, $db_user, $db_pass, $db_name, $db_prefix;

        if (!isset($conn)) {
            $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
            if ($conn->connect_error) {
                // Optionally log the connection error
                exit('Database connection failed.');
            }
        }
        
        try {
            switch ($message_text) {
                case '/last_transaction':
                    $sql = "SELECT * FROM {$db_prefix}transaction ORDER BY id DESC LIMIT 1";
                    $result = $conn->query($sql);
                    if ($result && $result->num_rows > 0) {
                        $row = $result->fetch_assoc();
                        $reply = "*Last Transaction Details:*\n\n" .
                                 "💰 *Amount:* `{$row['transaction_amount']} {$row['transaction_currency']}`\n" .
                                 "👤 *From:* `{$row['c_name']}`\n" .
                                 "💳 *Method:* `{$row['payment_method']}`\n" .
                                 "📊 *Status:* `{$row['transaction_status']}`\n" .
                                 "🗓️ *Date:* `{$row['created_at']}`";
                    } else {
                        $reply = "No transactions found.";
                    }
                    break;

                case '/sales_today':
                    $today_start = date('Y-m-d 00:00:00');
                    $today_end = date('Y-m-d 23:59:59');
                    $sql = "SELECT COALESCE(SUM(transaction_amount), 0) as total_sales FROM {$db_prefix}transaction WHERE created_at >= '{$today_start}' AND created_at <= '{$today_end}' AND transaction_status = 'completed'";
                    $result = $conn->query($sql);
                    $total_sales = $result->fetch_assoc()['total_sales'];
                    $reply = "📈 *Today's Sales:* `" . number_format($total_sales, 2) . "`";
                    break;

                case '/sales_yesterday':
                    $yesterday_start = date('Y-m-d 00:00:00', strtotime('-1 day'));
                    $yesterday_end = date('Y-m-d 23:59:59', strtotime('-1 day'));
                    $sql = "SELECT COALESCE(SUM(transaction_amount), 0) as total_sales FROM {$db_prefix}transaction WHERE created_at >= '{$yesterday_start}' AND created_at <= '{$yesterday_end}' AND transaction_status = 'completed'";
                    $result = $conn->query($sql);
                    $total_sales = $result->fetch_assoc()['total_sales'];
                    $reply = "📈 *Yesterday's Sales:* `" . number_format($total_sales, 2) . "`";
                    break;

                case '/sales_this_month':
                    $month_start = date('Y-m-01 00:00:00');
                    $month_end = date('Y-m-t 23:59:59');
                    $sql = "SELECT COALESCE(SUM(transaction_amount), 0) as total_sales FROM {$db_prefix}transaction WHERE created_at >= '{$month_start}' AND created_at <= '{$month_end}' AND transaction_status = 'completed'";
                    $result = $conn->query($sql);
                    $total_sales = $result->fetch_assoc()['total_sales'];
                    $reply = "📅 *This Month's Sales:* `" . number_format($total_sales, 2) . "`";
                    break;
                
                case '/pending_transactions':
                    $sql = "SELECT COUNT(*) as count FROM {$db_prefix}transaction WHERE transaction_status = 'pending'";
                    $result = $conn->query($sql);
                    $count = $result->fetch_assoc()['count'];
                    $reply = "⚪️ *Pending Transactions:* `{$count}`";
                    break;
                
                case '/failed_transactions':
                    $sql = "SELECT COUNT(*) as count FROM {$db_prefix}transaction WHERE transaction_status = 'failed'";
                    $result = $conn->query($sql);
                    $count = $result->fetch_assoc()['count'];
                    $reply = "❌ *Failed Transactions:* `{$count}`";
                    break;

                case '/completed_transactions':
                    $sql = "SELECT COUNT(*) as count FROM {$db_prefix}transaction WHERE transaction_status = 'completed'";
                    $result = $conn->query($sql);
                    $count = $result->fetch_assoc()['count'];
                    $reply = "✅ *Completed Transactions:* `{$count}`";
                    break;

                case '/help':
                    $reply = "*Available Commands:*\n\n" .
                             "`/start` - Get your Chat ID.\n" .
                             "`/last_transaction` - Details of the most recent transaction.\n" .
                             "`/sales_today` - Total sales for today.\n" .
                             "`/sales_yesterday` - Total sales for yesterday.\n" .
                             "`/sales_this_month` - Total sales for the current month.\n" .
                             "`/pending_transactions` - Count of pending transactions.\n" .
                             "`/failed_transactions` - Count of failed transactions.\n" .
                             "`/completed_transactions` - Count of completed transactions.\n" .
                             "`/help` - Show all available commands.";
                    break;

                default:
                    $reply = "Invalid command. To get your chat ID, type `/start` or type `/help` to see all available commands.";
                    break;
            }
        } catch (Exception $e) {
            $reply = "An error occurred while processing your command. Please try again later.";
            // You can also log the error message for debugging: error_log($e->getMessage());
        } finally {
            if (isset($conn)) {
                $conn->close();
            }
        }
    }
    
    $params = ['chat_id' => $chat_id, 'text' => $reply, 'parse_mode' => 'Markdown'];
    $url = "https://api.telegram.org/bot{$bot_token}/sendMessage?" . http_build_query($params);
    @file_get_contents($url);
}
?>