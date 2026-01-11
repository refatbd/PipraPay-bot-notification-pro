<?php
if (!defined('pp_allowed_access')) {
    die('Direct access not allowed');
}

// Hooks
add_action('pp_transaction_ipn', 'telegram_bot_notification_pro_transaction_admin_ipn');
add_action('pp_invoice_ipn', 'telegram_bot_notification_pro_invoice_admin_ipn');

// --- Constants for Update Process ---
define('TGNP_CURRENT_VERSION', '2.2.1');
define('TGNP_GITHUB_REPO', 'refatbd/PipraPay-bot-notification-pro');
define('TGNP_REFAT_SERVER_URL', 'https://wordpress.refat.ovh/api/update.php');
define('TGNP_PLUGIN_SLUG', 'telegram-bot-notification-pro');

// --- Security & Networking Helpers ---

function tgnp_sign_payload($payload, $secret) {
    ksort($payload);
    return hash_hmac('sha256', json_encode($payload), $secret);
}

function tgnp_verify_signature($payload, $signature, $secret) {
    ksort($payload);
    $calculated = hash_hmac('sha256', json_encode($payload), $secret);
    return hash_equals($calculated, $signature);
}

function tgnp_send_remote_request($url, $action, $data, $secret) {
    $payload = array_merge(['action' => $action], $data);
    $signature = tgnp_sign_payload($payload, $secret);
    
    $post_data = [
        'payload' => json_encode($payload),
        'signature' => $signature,
        'telegram-bot-notification-pro-api' => 'true'
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post_data),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true 
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        curl_close($ch);
        return ['status' => false, 'message' => 'Connection error: ' . curl_error($ch)];
    }
    
    curl_close($ch);
    
    // Try to decode JSON
    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        if (strpos($response, '<html') !== false || strpos($response, '<script') !== false) {
             return ['status' => false, 'message' => 'Remote Error: Target site redirected to login page. Check URL.'];
        }
        return ['status' => false, 'message' => 'Invalid JSON response from Hub.'];
    }
    
    return $decoded;
}

// --- Telegram API Helpers ---

function tgnp_call_telegram_api($bot_token, $method, $params = []) {
    $url = "https://api.telegram.org/bot{$bot_token}/{$method}";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    $response = curl_exec($ch);
    
    curl_close($ch);
    $result = json_decode($response, true);

    return $result;
}

function tgnp_set_bot_commands($bot_token) {
    $commands = [
        ['command' => '/start', 'description' => 'Get your Chat ID.'],
        ['command' => '/last_transaction', 'description' => 'Details of the most recent transaction.'],
        ['command' => '/sales_today', 'description' => 'Total sales for today.'],
        ['command' => '/sales_yesterday', 'description' => 'Total sales for yesterday.'],
        ['command' => '/sales_this_month', 'description' => 'Total sales for the current month.'],
        ['command' => '/pending_transactions', 'description' => 'Count of pending transactions.'],
        ['command' => '/failed_transactions', 'description' => 'Count of failed transactions.'],
        ['command' => '/completed_transactions', 'description' => 'Count of completed transactions.'],
        ['command' => '/help', 'description' => 'Show all available commands.'],
    ];

    tgnp_call_telegram_api($bot_token, 'setMyCommands', ['commands' => json_encode($commands)]);
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
    
    $success = in_array($http_code, [200, 302]);
    
    return $success;
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

    // 1. Save Bot Token
    if ($action === 'save_bot_token') {
        $bot_token = escape_string($_POST['bot_token']);
        $mode = $_POST['mode'] ?? 'standalone';
        
        if (empty($bot_token)) {
            echo json_encode(['status' => false, 'message' => 'Bot Token cannot be empty.']);
            exit();
        }

        $getMe = tgnp_call_telegram_api($bot_token, 'getMe');
        if (!($getMe['ok'] ?? false)) {
            echo json_encode(['status' => false, 'message' => 'Invalid Bot Token.']);
            exit();
        }

        $webhook_status_message = "Webhook not set (Node Mode).";
        
        if ($mode !== 'node') {
            $webhook_url = pp_get_site_url() . "/pp-content/plugins/modules/telegram-bot-notification-pro/ipn.php?telegram-bot-notification-pro";
            $setWebhook = tgnp_call_telegram_api($bot_token, 'setWebhook', ['url' => $webhook_url]);
            
            if ($setWebhook['ok'] ?? false) {
                $webhook_status_message = "Webhook active: " . $webhook_url;
                tgnp_set_bot_commands($bot_token);
            } else {
                $webhook_status_message = "Error setting webhook: " . ($setWebhook['description'] ?? 'Unknown error');
            }
        }

        $settings = pp_get_plugin_setting($plugin_slug);
        if (!is_array($settings)) $settings = [];
        
        $settings_to_save = array_merge($settings, [
            'bot_token' => $bot_token,
            'bot_username' => $getMe['result']['username'],
            'webhook_status' => $webhook_status_message,
            'operation_mode' => $mode
        ]);

        if (tgnp_save_settings($plugin_slug, $settings_to_save)) {
            echo json_encode(['status' => true, 'message' => 'Bot Connected! ' . $webhook_status_message]);
        } else {
            echo json_encode(['status' => false, 'message' => 'Could not save settings.']);
        }
        exit();
    }

    // 2. Delete Bot Token
    if ($action === 'delete_bot_token') {
        $settings = pp_get_plugin_setting($plugin_slug);
        $mode = $settings['operation_mode'] ?? 'standalone';
        $bot_token = $settings['bot_token'] ?? '';
        
        if ($mode !== 'node' && !empty($bot_token)) {
             tgnp_call_telegram_api($bot_token, 'deleteWebhook');
        }

        $settings['bot_token'] = '';
        $settings['bot_username'] = '';
        $settings['webhook_status'] = 'Disconnected';
        
        if(tgnp_save_settings($plugin_slug, $settings)) {
            echo json_encode(['status' => true, 'message' => 'Disconnected successfully.']);
        } else {
            echo json_encode(['status' => false, 'message' => 'Failed to disconnect.']);
        }
        exit();
    }
    
    // 3. Save Settings
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

        $operation_mode = $_POST['operation_mode'] ?? 'standalone';
        $site_name = $_POST['site_name'] ?? 'My Store';
        $site_identifier = $_POST['site_identifier'] ?? 'store_1';
        $hub_url = $_POST['hub_url'] ?? '';
        $api_secret = $_POST['api_secret'] ?? '';

        $new_settings = [
            'notifications_enabled' => isset($_POST['notifications_enabled']) ? 'true' : 'false',
            'notify_pending' => isset($_POST['notify_pending']) ? 'true' : 'false',
            'notify_completed' => isset($_POST['notify_completed']) ? 'true' : 'false',
            'notify_failed' => isset($_POST['notify_failed']) ? 'true' : 'false',
            'enable_confirm_button' => isset($_POST['enable_confirm_button']) ? 'true' : 'false',
            'chat_ids_json' => json_encode($chat_ids),
            'template_completed' => !empty($_POST['template_completed']) ? $_POST['template_completed'] : $default_templates['completed'],
            'template_pending'   => !empty($_POST['template_pending']) ? $_POST['template_pending'] : $default_templates['pending'],
            'template_failed'    => !empty($_POST['template_failed']) ? $_POST['template_failed'] : $default_templates['failed'],
            
            'operation_mode' => $operation_mode,
            'site_name' => $site_name,
            'site_identifier' => preg_replace('/[^a-zA-Z0-9_]/', '', $site_identifier),
            'hub_url' => $hub_url,
            'api_secret' => $api_secret
        ];

        if ($operation_mode === 'hub' && isset($_POST['remove_node'])) {
            $node_to_remove = $_POST['remove_node'];
            $nodes = json_decode($settings['connected_nodes_json'] ?? '[]', true);
            $nodes = array_filter($nodes, function($n) use ($node_to_remove) { return $n['site_identifier'] !== $node_to_remove; });
            $new_settings['connected_nodes_json'] = json_encode(array_values($nodes));
        }

        $settings_to_save = array_merge($settings, $new_settings);
        
        if(tgnp_save_settings($plugin_slug, $settings_to_save)) {
             echo json_encode(['status' => true, 'message' => 'Settings saved successfully.']);
        } else {
             echo json_encode(['status' => false, 'message' => 'Failed to save settings.']);
        }
        exit();
    }
    
    // 4. Register Node
    if ($action === 'register_node') {
        $hub_url = $_POST['hub_url'];
        $api_secret = $_POST['api_secret'];
        $site_identifier = $_POST['site_identifier'];
        $site_name = $_POST['site_name'];
        
        $hub_url_clean = rtrim($hub_url, '/');
        $target = $hub_url_clean . "/pp-content/plugins/modules/telegram-bot-notification-pro/ipn.php?telegram-bot-notification-pro=true";
        
        $payload = [
            'site_url' => pp_get_site_url(),
            'site_name' => $site_name,
            'site_identifier' => $site_identifier
        ];
        
        $response = tgnp_send_remote_request($target, 'register_node', $payload, $api_secret);
        
        if ($response['status'] ?? false) {
            $settings = pp_get_plugin_setting($plugin_slug);
            if (!is_array($settings)) $settings = [];
            
            $settings_to_save = array_merge($settings, [
                'operation_mode' => 'node',
                'hub_url' => $hub_url,
                'api_secret' => $api_secret,
                'site_name' => $site_name,
                'site_identifier' => $site_identifier,
                'hub_connected_status' => 'Connected'
            ]);
            
            tgnp_save_settings($plugin_slug, $settings_to_save);
        }
        
        echo json_encode($response);
        exit();
    }
    
    // 5. Disconnect Hub
    if ($action === 'disconnect_hub') {
        $settings = pp_get_plugin_setting($plugin_slug);
        
        // Reset Hub-related settings
        $settings['hub_url'] = '';
        $settings['api_secret'] = '';
        $settings['hub_connected_status'] = 'Disconnected';
        
        if(tgnp_save_settings($plugin_slug, $settings)) {
             echo json_encode(['status' => true, 'message' => 'Disconnected from Hub locally.']);
        } else {
             echo json_encode(['status' => false, 'message' => 'Failed to disconnect.']);
        }
        exit();
    }

    // 6. Check for Updates
    if ($action === 'check_for_updates') {
        $github_update = tgnp_check_for_github_updates();
        $refat_update = tgnp_check_for_refat_server_updates();
        
        $github_available = $github_update && version_compare($github_update['new_version'], TGNP_CURRENT_VERSION, '>');
        $refat_available = $refat_update && version_compare($refat_update['new_version'], TGNP_CURRENT_VERSION, '>');

        echo json_encode([
            'status' => true,
            'github' => [
                'update_available' => $github_available,
                'data' => $github_update
            ],
            'refat' => [
                'update_available' => $refat_available,
                'data' => $refat_update
            ],
            'message' => (!$github_available && !$refat_available) ? 'You are using the latest version (' . TGNP_CURRENT_VERSION . ').' : 'Update check complete.'
        ]);
        exit();
    }

    // 7. Install Update
    if ($action === 'install_update') {
        $download_url = $_POST['download_url'] ?? null;

        if (empty($download_url) || !filter_var($download_url, FILTER_VALIDATE_URL)) {
            echo json_encode(['status' => false, 'message' => 'Invalid download URL.']);
            exit();
        }

        // 1. Create backup
        $backup_path = tgnp_create_backup();
        if ($backup_path === false) {
            echo json_encode(['status' => false, 'message' => 'Failed to create backup. Update aborted. Please check file permissions for the backups directory.']);
            exit();
        }

        // 2. Download and install update
        $install_result = tgnp_install_update_from_zip($download_url);

        if ($install_result === true) {
            // 3. Success: Delete backup
            @unlink($backup_path);
            echo json_encode(['status' => true, 'message' => 'Update installed successfully.']);
        } else {
            // 4. Failure: Restore from backup
            tgnp_restore_from_backup($backup_path);
            echo json_encode(['status' => false, 'message' => 'Update failed: ' . $install_result . ' Restored from backup.']);
        }
        exit();
    }
}

// --- Notification Logic ---

function send_telegram_bot_notification_pro($message, $reply_markup = null) {
    $plugin_slug = 'telegram-bot-notification-pro';
    $settings = pp_get_plugin_setting($plugin_slug);
    
    if (($settings['notifications_enabled'] ?? 'false') !== 'true' || empty($settings['bot_token'])) {
        return;
    }
    
    $chat_ids = isset($settings['chat_ids_json']) ? json_decode($settings['chat_ids_json'], true) : [];
    
    // Append Site Name
    $site_name = $settings['site_name'] ?? 'PipraPay';
    $message .= "\n\n----------\n🔗 *From:* " . $site_name;

    foreach ($chat_ids as $chat) {
        if (($chat['enabled'] ?? 'false') === 'true') {
            $params = [
                'chat_id' => $chat['id'],
                'text' => $message,
                'parse_mode' => 'Markdown'
            ];
            if ($reply_markup) {
                $params['reply_markup'] = json_encode($reply_markup);
            }
            
            tgnp_call_telegram_api($settings['bot_token'], 'sendMessage', $params);
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
    
    if(($settings['notifications_enabled'] ?? 'false') !== 'true') return;

    $notify_enabled_key = 'notify_' . $status_lower;
    if(($settings[$notify_enabled_key] ?? 'false') !== 'true') return;

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
    
    $reply_markup = null;
    if ($status_lower === 'pending' && ($settings['enable_confirm_button'] ?? 'false') === 'true') {
        $mode = $settings['operation_mode'] ?? 'standalone';
        $site_identifier = $settings['site_identifier'] ?? 'default';
        
        $callback_data = "cp|{$site_identifier}|{$transaction_id}";
        if ($mode === 'standalone') {
             $callback_data = "cp|local|{$transaction_id}";
        }
        
        if (strlen($callback_data) <= 64) {
            $reply_markup = [
                'inline_keyboard' => [[['text' => 'Confirm Pending Transaction', 'callback_data' => $callback_data]]]
            ];
        }
    }
    
    send_telegram_bot_notification_pro($message, $reply_markup);
}

function telegram_bot_notification_pro_invoice_admin_ipn($invoice_id) {
    // Invoice logic
}

// --- UPDATE FUNCTIONS ---

/**
 * Get the path to the plugin directory.
 */
function tgnp_get_plugin_dir() {
    return __DIR__;
}

/**
 * Get the path to the backups directory.
 */
function tgnp_get_backup_dir() {
    // Go up 3 levels from /pp-content/plugins/modules/telegram-bot-notification-pro/
    $backup_dir = dirname(__DIR__, 3) . '/backups/tgnp/';
    if (!is_dir($backup_dir)) {
        @mkdir($backup_dir, 0755, true);
    }
    return $backup_dir;
}

/**
 * Deletes a directory and all its contents.
 */
function tgnp_delete_directory($dir) {
    if (!file_exists($dir)) { return true; }
    if (!is_dir($dir)) { return unlink($dir); }
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') { continue; }
        if (!tgnp_delete_directory($dir . DIRECTORY_SEPARATOR . $item)) { return false; }
    }
    return rmdir($dir);
}

/**
 * Create a zip backup of the current plugin directory.
 */
function tgnp_create_backup() {
    if (!class_exists('ZipArchive')) {
        return false;
    }
    
    $plugin_dir = tgnp_get_plugin_dir();
    $backup_dir = tgnp_get_backup_dir();
    
    if (!is_writable($backup_dir)) {
        error_log("TGNP Backup Error: Directory not writable: " . $backup_dir);
        return false;
    }

    $backup_file = $backup_dir . TGNP_PLUGIN_SLUG . '-backup-' . time() . '.zip';
    
    $zip = new ZipArchive();
    if ($zip->open($backup_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        return false;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($plugin_dir),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $name => $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($plugin_dir) + 1);
            $zip->addFile($filePath, $relativePath);
        }
    }

    $zip->close();
    return $backup_file;
}

/**
 * Restore the plugin from a backup zip.
 */
function tgnp_restore_from_backup($backup_path) {
    if (!class_exists('ZipArchive') || !file_exists($backup_path)) {
        return false;
    }

    $plugin_dir = tgnp_get_plugin_dir();
    
    // 1. Clear the current plugin directory
    tgnp_delete_directory($plugin_dir);
    @mkdir($plugin_dir, 0755, true); // Recreate the empty dir

    // 2. Extract backup
    $zip = new ZipArchive();
    if ($zip->open($backup_path) === TRUE) {
        $zip->extractTo($plugin_dir);
        $zip->close();
        return true;
    }
    return false;
}

/**
 * Download and install the update from a zip URL.
 */
function tgnp_install_update_from_zip($download_url) {
    if (!class_exists('ZipArchive')) {
        return "ZipArchive class is not available.";
    }

    $plugin_dir = tgnp_get_plugin_dir();
    $temp_zip = tgnp_get_backup_dir() . 'tgnp-update.zip';

    // 1. Download the zip file
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $download_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PipraPay Plugin Update Checker');
    $zip_data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code != 200 || $zip_data === false) {
        return "Failed to download update file (HTTP code: {$http_code}).";
    }

    if (file_put_contents($temp_zip, $zip_data) === false) {
        return "Failed to save temporary update file.";
    }

    // 2. Clear the plugin directory
    tgnp_delete_directory($plugin_dir);
    @mkdir($plugin_dir, 0755, true);

    // 3. Extract the new version
    $zip = new ZipArchive();
    if ($zip->open($temp_zip) === TRUE) {
        // Check if the zip has a root folder
        $root_dir = $zip->getNameIndex(0);
        
        if (substr_count($root_dir, '/') == 1 && substr($root_dir, -1) == '/') {
            // Contains a root folder, extract to a temp location and move files
            $temp_extract_dir = tgnp_get_backup_dir() . 'tgnp-extract/';
            tgnp_delete_directory($temp_extract_dir); // Clear old
            
            $zip->extractTo($temp_extract_dir);
            $zip->close();
            
            // Move files from /.../tgnp-extract/plugin-root-folder/ to /.../plugin_dir/
            $files = scandir($temp_extract_dir . $root_dir);
            foreach ($files as $file) {
                if ($file == '.' || $file == '..') continue;
                @rename($temp_extract_dir . $root_dir . $file, $plugin_dir . '/' . $file);
            }
            tgnp_delete_directory($temp_extract_dir);
            
        } else {
            // No root folder, extract directly
            $zip->extractTo($plugin_dir);
            $zip->close();
        }
        
        @unlink($temp_zip); // Delete the temp update zip
        return true;
    } else {
        @unlink($temp_zip);
        return "Failed to open the downloaded zip file.";
    }
}

function tgnp_check_for_github_updates() {
    $api_url = "https://api.github.com/repos/" . TGNP_GITHUB_REPO . "/releases/latest";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $api_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'PipraPay Plugin Update Checker'
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response) {
        $release_data = json_decode($response, true);
        if (isset($release_data['tag_name'])) {
            $latest_version = ltrim($release_data['tag_name'], 'v');
            $download_url = '';
            
            if (!empty($release_data['assets'])) {
                foreach ($release_data['assets'] as $asset) {
                    if (strpos($asset['name'], '.zip') !== false) {
                        $download_url = $asset['browser_download_url'];
                        break;
                    }
                }
            }
            // Fallback if no zip asset
            if (empty($download_url) && isset($release_data['zipball_url'])) {
                 $download_url = $release_data['zipball_url'];
            }

            $html_changelog = $release_data['body'] ?? '';
            // Basic formatting
            $html_changelog = preg_replace('/^### (.*)$/m', '<h5>$1</h5>', $html_changelog);
            $html_changelog = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $html_changelog);
            $html_changelog = preg_replace('/^[-*]\s+(.*)$/m', '<li>$1</li>', $html_changelog);
            $html_changelog = nl2br($html_changelog);

            return [
                'new_version' => $latest_version,
                'download_url' => $download_url,
                'changelog' => $html_changelog
            ];
        }
    }
    return null;
}

function tgnp_check_for_refat_server_updates() {
    $api_url = TGNP_REFAT_SERVER_URL;
    $payload = [
        'action'  => 'update_check',
        'request' => json_encode([
            'slug'    => TGNP_PLUGIN_SLUG,
            'version' => TGNP_CURRENT_VERSION
        ])
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $result = curl_exec($ch);
    curl_close($ch);

    if ($result === false) {
        return null;
    }

    $response = json_decode($result);

    if ($response && isset($response->success) && $response->success && isset($response->data)) {
        $data = $response->data;
        $changelog = '';

        if (isset($data->sections) && isset($data->sections->changelog)) {
            $changelog = $data->sections->changelog;
        }

        return [
            'new_version' => $data->new_version,
            'download_url' => $data->package,
            'changelog' => $changelog
        ];
    }
    
    return null;
}
?>