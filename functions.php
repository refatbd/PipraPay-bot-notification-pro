<?php
if (!defined('pp_allowed_access')) {
    die('Direct access not allowed');
}

// Hooks
add_action('pp_transaction_ipn', 'telegram_bot_notification_pro_transaction_admin_ipn');
add_action('pp_invoice_ipn', 'telegram_bot_notification_pro_invoice_admin_ipn');

// --- Helper Functions ---

function tgnp_call_telegram_api($bot_token, $method, $params = []) {
    $url = "https://api.telegram.org/bot{$bot_token}/{$method}";
    if (!empty($params)) {
        $url .= "?" . http_build_query($params);
    }
    $response = @file_get_contents($url);
    return json_decode($response, true);
}

function tgnp_save_settings(string $plugin_slug, array $data_to_save) {
    $targetUrl = pp_get_site_url().'/admin/dashboard';
    $data = array_merge(['action' => 'plugin_update-submit', 'plugin_slug' => $plugin_slug], $data_to_save);

    $ch = curl_init($targetUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return in_array($http_code, [200, 302]);
}

function tgnp_get_default_templates() {
    return [
        'completed' => "✅ *New Transaction: Completed*\n\n💰 *Amount:* `{amount} {currency}`\n👤 *From:* {customer_name}\n💳 *Method:* {payment_method}\n📱 *Sender:* `{sender_number}`\n🗓️ *Date:* {date}\n📄 *Payment ID:* `{payment_id}`\n🔗 *Transaction ID:* `{gateway_trx_id}`",
        'pending'   => "⚪️ *New Transaction: Pending*\n\n💰 *Amount:* `{amount} {currency}`\n👤 *From:* {customer_name}\n💳 *Method:* {payment_method}\n📱 *Sender:* `{sender_number}`\n🗓️ *Date:* {date}\n📄 *Payment ID:* `{payment_id}`\n🔗 *Transaction ID:* `{gateway_trx_id}`",
        'failed'    => "❌ *New Transaction: Failed*\n\n💰 *Amount:* `{amount} {currency}`\n👤 *From:* {customer_name}\n💳 *Method:* {payment_method}\n📱 *Sender:* `{sender_number}`\n🗓️ *Date:* {date}\n📄 *Payment ID:* `{payment_id}`\n🔗 *Transaction ID:* `{gateway_trx_id}`",
    ];
}

function tgnp_parse_template(string $template, array $data) {
    foreach ($data as $key => $value) {
        $template = str_replace("{{$key}}", $value, $template);
    }
    return $template;
}

// --- AJAX Handlers ---

if (isset($_POST['telegram-bot-notification-pro-action'])) {
    header('Content-Type: application/json');
    $plugin_slug = 'telegram-bot-notification-pro';
    $action = $_POST['telegram-bot-notification-pro-action'];

    if ($action === 'save_bot_token') {
        $bot_token = escape_string($_POST['bot_token']);
        if (empty($bot_token)) {
            echo json_encode(['status' => false, 'message' => 'Bot Token cannot be empty.']);
            exit();
        }

        $getMe = tgnp_call_telegram_api($bot_token, 'getMe');
        if (!($getMe['ok'] ?? false)) {
            echo json_encode(['status' => false, 'message' => 'Invalid Bot Token.']);
            exit();
        }

        $webhook_url = pp_get_site_url() . "/pp-content/plugins/modules/telegram-bot-notification-pro/ipn.php?telegram-bot-notification-pro";
        $setWebhook = tgnp_call_telegram_api($bot_token, 'setWebhook', ['url' => $webhook_url]);
        
        $webhook_status_message = "Webhook set successfully!";
        if (!($setWebhook['ok'] ?? false)) {
            $webhook_status_message = "Error: " . ($setWebhook['description'] ?? 'Could not set webhook.');
        }

        $settings = pp_get_plugin_setting($plugin_slug);
        if (!is_array($settings)) {
            $settings = [];
        }
        $settings_to_save = array_merge($settings, [
            'bot_token' => $bot_token,
            'bot_username' => $getMe['result']['username'],
            'webhook_status' => $webhook_status_message
        ]);

        if (tgnp_save_settings($plugin_slug, $settings_to_save)) {
            echo json_encode(['status' => true, 'message' => 'Bot connected! ' . $webhook_status_message]);
        } else {
            echo json_encode(['status' => false, 'message' => 'Could not save settings.']);
        }
        exit();
    }

    if ($action === 'delete_bot_token') {
        $settings = pp_get_plugin_setting($plugin_slug);
        $settings['bot_token'] = '';
        $settings['bot_username'] = '';
        $settings['webhook_status'] = '';
        
        if(tgnp_save_settings($plugin_slug, $settings)) {
            echo json_encode(['status' => true, 'message' => 'Bot disconnected successfully.']);
        } else {
            echo json_encode(['status' => false, 'message' => 'Failed to disconnect bot.']);
        }
        exit();
    }
    
    if ($action === 'save_settings') {
        $settings = pp_get_plugin_setting($plugin_slug);
        if (!is_array($settings)) $settings = [];

        $chat_ids = [];
        if (isset($_POST['chat_ids'])) {
            foreach ($_POST['chat_ids'] as $chat_id_data) {
                if (empty($chat_id_data['id'])) continue;
                $chat_ids[] = [
                    'id' => escape_string($chat_id_data['id']),
                    'name' => escape_string($chat_id_data['name']),
                    'enabled' => isset($chat_id_data['enabled']) ? 'true' : 'false'
                ];
            }
        }
        
        $default_templates = tgnp_get_default_templates();

        $new_settings = [
            'notifications_enabled' => isset($_POST['notifications_enabled']) ? 'true' : 'false',
            'notify_pending' => isset($_POST['notify_pending']) ? 'true' : 'false',
            'notify_completed' => isset($_POST['notify_completed']) ? 'true' : 'false',
            'notify_failed' => isset($_POST['notify_failed']) ? 'true' : 'false',
            'chat_ids_json' => json_encode($chat_ids),
            'template_completed' => !empty($_POST['template_completed']) ? $_POST['template_completed'] : $default_templates['completed'],
            'template_pending'   => !empty($_POST['template_pending']) ? $_POST['template_pending'] : $default_templates['pending'],
            'template_failed'    => !empty($_POST['template_failed']) ? $_POST['template_failed'] : $default_templates['failed'],
        ];

        $settings_to_save = array_merge($settings, $new_settings);
        
        if(tgnp_save_settings($plugin_slug, $settings_to_save)) {
             echo json_encode(['status' => true, 'message' => 'Settings saved successfully.']);
        } else {
             echo json_encode(['status' => false, 'message' => 'Failed to save settings.']);
        }
        exit();
    }
}

// --- Notification Functions ---

function send_telegram_bot_notification_pro($message) {
    $plugin_slug = 'telegram-bot-notification-pro';
    $settings = pp_get_plugin_setting($plugin_slug);

    if (($settings['notifications_enabled'] ?? 'false') !== 'true' || empty($settings['bot_token']) || empty($settings['chat_ids_json'])) {
        return;
    }

    $chat_ids = json_decode($settings['chat_ids_json'], true);
    if (empty($chat_ids) || !is_array($chat_ids)) return;

    foreach ($chat_ids as $chat) {
        if (($chat['enabled'] ?? 'false') === 'true') {
            tgnp_call_telegram_api($settings['bot_token'], 'sendMessage', [
                'chat_id' => $chat['id'],
                'text' => $message,
                'parse_mode' => 'Markdown'
            ]);
        }
    }
}

function telegram_bot_notification_pro_transaction_admin_ipn($transaction_id) {
    $plugin_slug = 'telegram-bot-notification-pro';
    $settings = pp_get_plugin_setting($plugin_slug);
    
    $transaction = pp_get_transation($transaction_id);
    if (!isset($transaction['response'][0])) return;
    $t = $transaction['response'][0];

    $status = ucfirst($t['transaction_status']);
    $status_lower = strtolower($status);
    $notify_enabled_key = 'notify_' . $status_lower;
    if(($settings[$notify_enabled_key] ?? 'false') !== 'true') {
        return;
    }

    $metadata = isset($t['transaction_metadata']) ? json_decode($t['transaction_metadata'], true) : [];
    if (!is_array($metadata)) $metadata = [];

    $payment_method = !empty($t['payment_method']) && $t['payment_method'] !== '--' ? $t['payment_method'] : ($metadata['payment_method'] ?? 'N/A');
    $sender_number = !empty($t['payment_sender_number']) && $t['payment_sender_number'] !== '--' ? $t['payment_sender_number'] : ($metadata['sender_number'] ?? $metadata['phone'] ?? 'N/A');
    
    $payment_id = $t['pp_id'] ?? 'N/A';
    $transaction_id_from_gateway = $t['payment_verify_id'] ?? 'N/A'; 
    $created_at = $t['created_at'] ? date("d M Y, h:i A", strtotime($t['created_at'])) : 'N/A';

    $template_key = "template_{$status_lower}";
    $default_templates = tgnp_get_default_templates();
    $message_template = !empty($settings[$template_key]) ? $settings[$template_key] : $default_templates[$status_lower];

    $placeholders = [
        'amount'          => $t['transaction_amount'],
        'currency'        => $t['transaction_currency'],
        'customer_name'   => $t['c_name'],
        'payment_method'  => $payment_method,
        'sender_number'   => $sender_number,
        'date'            => $created_at,
        'payment_id'      => $payment_id,
        'gateway_trx_id'  => $transaction_id_from_gateway,
        'status'          => $status,
    ];

    $message = tgnp_parse_template($message_template, $placeholders);
    
    send_telegram_bot_notification_pro($message);
}

function telegram_bot_notification_pro_invoice_admin_ipn($invoice_id) {
    // I will develop this in future
}