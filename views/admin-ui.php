<?php
    if (!defined('pp_allowed_access')) {
        die('Direct access not allowed');
    }

    $plugin_slug = 'telegram-bot-notification-pro';
    $settings = pp_get_plugin_setting($plugin_slug);
    
    // --- Data Setup ---
    $operation_mode = $settings['operation_mode'] ?? 'standalone'; // standalone, hub, node
    $site_name = $settings['site_name'] ?? 'My Store';
    $site_identifier = $settings['site_identifier'] ?? 'store_' . rand(100,999);
    $api_secret = $settings['api_secret'] ?? bin2hex(random_bytes(16));
    
    $bot_token = $settings['bot_token'] ?? '';
    $bot_username = $settings['bot_username'] ?? '';
    $webhook_status = $settings['webhook_status'] ?? 'Not set.';
    
    // Node Mode Settings
    $hub_url = $settings['hub_url'] ?? '';
    $hub_connected_status = $settings['hub_connected_status'] ?? 'Disconnected';
    
    $chat_ids = isset($settings['chat_ids_json']) ? json_decode($settings['chat_ids_json'], true) : [];
    if (!is_array($chat_ids)) $chat_ids = [];

    $connected_nodes = isset($settings['connected_nodes_json']) ? json_decode($settings['connected_nodes_json'], true) : [];
    if (!is_array($connected_nodes)) $connected_nodes = [];

    // Define default templates
    $default_templates = tgnp_get_default_templates();
    
    $templates = [
        'completed' => !empty($settings['template_completed']) ? $settings['template_completed'] : $default_templates['completed'],
        'pending'   => !empty($settings['template_pending']) ? $settings['template_pending'] : $default_templates['pending'],
        'failed'    => !empty($settings['template_failed']) ? $settings['template_failed'] : $default_templates['failed'],
    ];
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
<style>
    /* Add styles for update card */
    .update-card {
        border: 1px solid #e9ecef;
        border-radius: 5px;
        margin-bottom: 1rem;
    }
    .update-card-header {
        background-color: #f8f9fa;
        padding: 0.75rem 1rem;
        border-bottom: 1px solid #e9ecef;
        font-weight: 600;
    }
    .update-card-body {
        padding: 1rem;
    }
    .changelog {
        background: #fff;
        border: 1px solid #eee;
        padding: 1rem;
        border-radius: 4px;
        max-height: 200px;
        overflow-y: auto;
    }
    .changelog ul { margin-bottom: 0; }
</style>

<div class="page-header">
  <h1 class="page-header-title">Telegram Bot Notification Pro <span class="badge bg-secondary fs-6 align-middle ms-2"><?php echo ucfirst($operation_mode); ?> Mode</span></h1>
</div>

<div class="row">
    <div class="col-lg-8">
        <div id="ajaxResponse" class="mb-3"></div>

        <div class="card mb-3">
            <div class="card-header"><h4 class="card-title">1. Operation Mode & Identity</h4></div>
            <div class="card-body">
                <form id="identityForm">
                    <input type="hidden" name="telegram-bot-notification-pro-action" value="save_settings">
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Select Mode</label>
                        <select class="form-select" name="operation_mode" id="operation_mode_select">
                            <option value="standalone" <?php echo $operation_mode === 'standalone' ? 'selected' : ''; ?>>👤 Standalone (Standard)</option>
                            <option value="hub" <?php echo $operation_mode === 'hub' ? 'selected' : ''; ?>>👑 Controller (Hub)</option>
                            <option value="node" <?php echo $operation_mode === 'node' ? 'selected' : ''; ?>>🔗 Connected Node (Client)</option>
                        </select>
                        <div class="form-text" id="mode_desc"></div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Site Name</label>
                            <input type="text" class="form-control" name="site_name" value="<?php echo htmlspecialchars($site_name); ?>" required>
                            <div class="form-text">Shown in messages (e.g. "Main Store").</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Site Identifier (Slug)</label>
                            <input type="text" class="form-control" name="site_identifier" value="<?php echo htmlspecialchars($site_identifier); ?>" pattern="[a-zA-Z0-9_]+" required>
                            <div class="form-text">Unique ID (a-z, 0-9, _ only).</div>
                        </div>
                    </div>

                    <div class="mb-3" id="api_secret_container">
                        <label class="form-label">API Secret Key</label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" name="api_secret" id="api_secret" value="<?php echo htmlspecialchars($api_secret); ?>" required>
                            <button class="btn btn-outline-secondary" type="button" onclick="generateSecret()">Generate New</button>
                        </div>
                        <div class="form-text text-hub-only">⚠️ Copy this key to your Client Nodes.</div>
                        <div class="form-text text-node-only">🔑 Paste the Hub's Secret Key here.</div>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary btn-sm">Save Identity Settings</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="card-title">2. Bot Settings</h4>
                <?php if(!empty($bot_token)): ?>
                    <span class="badge bg-success">Token Saved</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <p class="form-text text-node-only"><strong>Note:</strong> In Node mode, the token is used to <em>send</em> messages. The Webhook lives on the Hub.</p>
                
                <?php if(empty($bot_token)): ?>
                <form id="botTokenForm">
                    <input type="hidden" name="telegram-bot-notification-pro-action" value="save_bot_token">
                    <input type="hidden" name="mode" id="bot_token_mode_input" value="<?php echo $operation_mode; ?>">
                    
                    <div class="mb-3">
                        <label for="bot_token" class="form-label">Telegram Bot Token</label>
                        <input type="text" class="form-control" id="bot_token" name="bot_token" placeholder="Enter Bot Token" required>
                    </div>
                    <button type="submit" class="btn btn-primary" id="btnConnectBot">Connect Bot</button>
                </form>
                <?php else: ?>
                    <div class="alert alert-soft-info">
                        <strong>Current Bot:</strong> @<?php echo htmlspecialchars($bot_username); ?><br>
                        <strong>Webhook Status:</strong> <?php echo htmlspecialchars($webhook_status); ?>
                    </div>
                    <button id="deleteBotToken" class="btn btn-danger btn-sm">Disconnect / Reset Token</button>
                <?php endif; ?>

                <div class="mt-4 section-hub-nodes" style="display:none;">
                    <hr>
                    <h5>🔗 Connected Nodes</h5>
                    <?php if (empty($connected_nodes)): ?>
                        <p class="text-muted">No external sites connected.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead><tr><th>Site Name</th><th>ID</th><th>Actions</th></tr></thead>
                                <tbody>
                                <?php foreach($connected_nodes as $node): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($node['site_name']); ?></strong><br><small><?php echo htmlspecialchars($node['site_url']); ?></small></td>
                                        <td><code><?php echo htmlspecialchars($node['site_identifier']); ?></code></td>
                                        <td><button class="btn btn-xs btn-outline-danger remove-node-btn" data-id="<?php echo htmlspecialchars($node['site_identifier']); ?>">Remove</button></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card mb-3 section-node" style="display:none;">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4 class="card-title">3. Connect to Hub</h4>
                <?php if($hub_connected_status === 'Connected'): ?>
                    <span class="badge bg-success">✅ Connected</span>
                <?php else: ?>
                    <span class="badge bg-secondary">Not Connected</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if($hub_connected_status === 'Connected'): ?>
                    <div class="alert alert-success d-flex align-items-center mb-3">
                        <i class="bi-check-circle-fill me-2"></i>
                        <div>
                            <strong>Connected Successfully!</strong><br>
                            Linked to Hub: <code><?php echo htmlspecialchars($hub_url); ?></code>
                        </div>
                    </div>
                <?php endif; ?>

                <form id="connectHubForm">
                    <input type="hidden" name="telegram-bot-notification-pro-action" value="register_node">
                    <input type="hidden" name="site_identifier" value="<?php echo htmlspecialchars($site_identifier); ?>">
                    <input type="hidden" name="site_name" value="<?php echo htmlspecialchars($site_name); ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Hub Site URL</label>
                        <input type="url" class="form-control" name="hub_url" value="<?php echo htmlspecialchars($hub_url); ?>" placeholder="https://main-site.com" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Hub API Secret</label>
                        <input type="text" class="form-control" name="api_secret" id="node_api_secret" value="<?php echo htmlspecialchars($api_secret); ?>" required>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn <?php echo ($hub_connected_status === 'Connected' ? 'btn-outline-success' : 'btn-success'); ?>">
                            <?php echo ($hub_connected_status === 'Connected' ? '🔄 Update Connection' : '🔗 Connect to Hub'); ?>
                        </button>
                        
                        <?php if($hub_connected_status === 'Connected'): ?>
                            <button type="button" id="disconnectHubBtn" class="btn btn-danger">Disconnect</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <form id="mainSettingsForm" method="post" action="">
            <input type="hidden" name="telegram-bot-notification-pro-action" value="save_settings">
            <input type="hidden" name="operation_mode" value="<?php echo $operation_mode; ?>">
            <input type="hidden" name="site_name" value="<?php echo htmlspecialchars($site_name); ?>">
            <input type="hidden" name="site_identifier" value="<?php echo htmlspecialchars($site_identifier); ?>">
            <input type="hidden" name="api_secret" value="<?php echo htmlspecialchars($api_secret); ?>">
            <input type="hidden" name="hub_url" value="<?php echo htmlspecialchars($hub_url); ?>">
            <input type="hidden" name="hub_connected_status" value="<?php echo htmlspecialchars($hub_connected_status); ?>">

            <div class="card mb-3">
                <div class="card-header"><h4 class="card-title">4. Notification Rules</h4></div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="notifications_enabled" <?php echo ($settings['notifications_enabled'] ?? 'true') === 'true' ? 'checked' : ''; ?>>
                        <label class="form-check-label"><b>Enable All Notifications</b></label>
                    </div>

                    <div class="d-flex gap-4 mb-3">
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="notify_pending" <?php echo ($settings['notify_pending'] ?? 'true') === 'true' ? 'checked' : ''; ?>><label class="form-check-label">Pending</label></div>
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="notify_completed" <?php echo ($settings['notify_completed'] ?? 'true') === 'true' ? 'checked' : ''; ?>><label class="form-check-label">Completed</label></div>
                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="notify_failed" <?php echo ($settings['notify_failed'] ?? 'true') === 'true' ? 'checked' : ''; ?>><label class="form-check-label">Failed</label></div>
                    </div>
                    
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="enable_confirm_button" <?php echo ($settings['enable_confirm_button'] ?? 'false') === 'true' ? 'checked' : ''; ?>>
                        <label class="form-check-label"><b>Enable "Confirm Transaction" Button</b></label>
                    </div>

                    <hr>
                    <h5>Recipient Chat IDs</h5>
                    <div id="chatIdsContainer">
                        <?php foreach($chat_ids as $index => $chat): ?>
                        <div class="row g-2 align-items-center mb-2 chat-id-row">
                            <div class="col"><input type="text" class="form-control" name="chat_ids[<?php echo $index; ?>][id]" value="<?php echo htmlspecialchars($chat['id']); ?>" placeholder="Chat ID" required></div>
                            <div class="col"><input type="text" class="form-control" name="chat_ids[<?php echo $index; ?>][name]" value="<?php echo htmlspecialchars($chat['name']); ?>" placeholder="Name"></div>
                            <div class="col-auto"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="chat_ids[<?php echo $index; ?>][enabled]" <?php echo ($chat['enabled'] ?? 'false') === 'true' ? 'checked' : ''; ?>></div></div>
                            <div class="col-auto"><button type="button" class="btn btn-soft-danger btn-icon btn-sm remove-chat-id"><i class="bi-trash"></i></button></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" id="addChatId" class="btn btn-soft-secondary btn-sm mt-2"><i class="bi-plus"></i> Add Chat ID</button>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h4 class="card-title">5. Message Templates</h4></div>
                <div class="card-body">
                    <p class="form-text">Placeholders: <code>{amount}, {currency}, {customer_name}, {payment_method}, {sender_number}, {date}, {payment_id}, {gateway_trx_id}, {status}</code></p>
                    
                    <div class="mb-3">
                        <label class="form-label">Completed</label>
                        <textarea class="form-control" name="template_completed" rows="4"><?php echo htmlspecialchars($templates['completed']); ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Pending</label>
                        <textarea class="form-control" name="template_pending" rows="4"><?php echo htmlspecialchars($templates['pending']); ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Failed</label>
                        <textarea class="form-control" name="template_failed" rows="4"><?php echo htmlspecialchars($templates['failed']); ?></textarea>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-100">Save All Settings</button>
        </form>
        
        <div class="card mt-3">
            <div class="card-header"><h4 class="card-title">Available Bot Commands</h4></div>
            <div class="card-body">
                <p class="form-text">Use these commands in Telegram. In Multi-site setup, you will be asked to select the site.</p>
                <ul class="list-group list-group-flush">
                    <li class="list-group-item"><code>/start</code> - Get Chat ID</li>
                    <li class="list-group-item"><code>/sales_today</code> - Today's Sales</li>
                    <li class="list-group-item"><code>/sales_yesterday</code> - Yesterday's Sales</li>
                    <li class="list-group-item"><code>/sales_this_month</code> - This Month's Sales</li>
                    <li class="list-group-item"><code>/last_transaction</code> - Recent Transaction Details</li>
                    <li class="list-group-item"><code>/pending_transactions</code> - Count Pending</li>
                    <li class="list-group-item"><code>/completed_transactions</code> - Count Completed</li>
                </ul>
            </div>
        </div>
        
        <div class="row mt-4">
            <div class="col-lg-6">
                <div class="card mb-3">
                    <div class="card-header"><h4 class="card-title">Plugin Updates</h4></div>
                    <div class="card-body">
                        <p class="form-text">Check for new versions of the plugin. Updates can be installed directly.</p>
                        <button id="checkForUpdatesBtn" class="btn btn-secondary">Check for Updates</button>
                        <div id="updateCheckResponse" class="mt-3"></div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                 <div class="card mb-3">
                    <div class="card-header"><h4 class="card-title">Developer Info</h4></div>
                    <div class="card-body">
                        <p class="developer-name" style="margin-bottom: 5px; font-size: 16px;"><strong>Refat Rahman</strong></p>
                        <div class="developer-links">
                            <a href="https://www.facebook.com/rjrefat" target="_blank" style="margin-right: 10px; text-decoration: none;">
                                <i class="fab fa-facebook-square" style="font-size: 24px;"></i> Facebook
                            </a>
                            <a href="https://github.com/refatbd/" target="_blank" style="text-decoration: none;">
                                <i class="fab fa-github-square" style="font-size: 24px;"></i> Github
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function generateSecret() {
    const arr = new Uint8Array(16);
    window.crypto.getRandomValues(arr);
    const hex = Array.from(arr, byte => byte.toString(16).padStart(2, '0')).join('');
    document.getElementById('api_secret').value = hex;
    if(document.getElementById('node_api_secret')) document.getElementById('node_api_secret').value = hex;
}

$(document).ready(function() {
    // UI Toggles
    function updateUIForMode() {
        const mode = $('#operation_mode_select').val();
        $('#bot_token_mode_input').val(mode); // Update hidden input for bot form

        $('.section-hub-nodes, .section-node, .text-hub-only, .text-node-only').hide();

        if (mode === 'standalone') {
            $('#mode_desc').html('<b>Standalone:</b> This site controls the bot.');
            $('#btnConnectBot').text('Connect Bot (Set Webhook)');
            $('#api_secret_container').hide();
        } else if (mode === 'hub') {
            $('.section-hub-nodes').fadeIn();
            $('#mode_desc').html('<b>Hub:</b> Receives Webhooks & Commands from Nodes.');
            $('.text-hub-only').show();
            $('#btnConnectBot').text('Connect Bot (Set Webhook)');
            $('#api_secret_container').show();
        } else if (mode === 'node') {
            $('.section-node').fadeIn();
            $('#mode_desc').html('<b>Node:</b> Sends data via Hub. Needs Bot Token for sending, but NO Webhook.');
            $('.text-node-only').show();
            $('#btnConnectBot').text('Save Token Only (No Webhook)');
            $('#api_secret_container').show();
        }
    }

    $('#operation_mode_select').on('change', updateUIForMode);
    updateUIForMode();

    // AJAX Helper
    function showResponse(message, isSuccess) {
        const cls = isSuccess ? 'alert-success' : 'alert-danger';
        $('#ajaxResponse').html(`<div class="alert ${cls} alert-dismissible fade show">${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`);
        window.scrollTo(0,0);
    }

    function ajaxRequest(data, button, callback) {
        if(button) {
            var original = button.html();
            button.html('<span class="spinner-border spinner-border-sm"></span>').prop('disabled', true);
        }
        $.ajax({
            url: '', type: 'POST', data: data, dataType: 'json',
            success: function(res) {
                showResponse(res.message, res.status);
                if(callback) callback(res);
            },
            error: function() { showResponse('An unexpected error occurred.', false); },
            complete: function() { if(button) button.html(original).prop('disabled', false); }
        });
    }

    // Forms
    $('#identityForm').on('submit', function(e) { e.preventDefault(); ajaxRequest($(this).serialize(), $(this).find('button'), () => setTimeout(() => location.reload(), 1000)); });
    
    $('#botTokenForm').on('submit', function(e) { e.preventDefault(); ajaxRequest($(this).serialize(), $(this).find('button'), () => setTimeout(() => location.reload(), 1000)); });
    
    $('#connectHubForm').on('submit', function(e) { e.preventDefault(); ajaxRequest($(this).serialize(), $(this).find('button'), (res) => { if(res.status) setTimeout(() => location.reload(), 1000); }); });
    
    // Disconnect Hub (New)
    $('#disconnectHubBtn').on('click', function() {
        if(confirm('Are you sure you want to disconnect from the Hub?')) {
            ajaxRequest({'telegram-bot-notification-pro-action':'disconnect_hub'}, $(this), () => setTimeout(() => location.reload(), 1000));
        }
    });

    $('#mainSettingsForm').on('submit', function(e) { e.preventDefault(); ajaxRequest($(this).serialize(), $(this).find('button')); });
    
    $('#deleteBotToken').on('click', function() { if(confirm('Disconnect bot?')) ajaxRequest({'telegram-bot-notification-pro-action':'delete_bot_token'}, $(this), () => location.reload()); });

    // Node Removal
    $('.remove-node-btn').on('click', function() {
        if(confirm('Remove this node?')) {
            let data = $('#mainSettingsForm').serialize() + '&remove_node=' + $(this).data('id');
            ajaxRequest(data, $(this), () => location.reload());
        }
    });

    // Helper to build update card
    function createUpdateCard(sourceName, update) {
        if (!update || !update.new_version) return '';
        
        let changelog = update.changelog || "<p>No changelog provided.</p>";
        
        return `
            <div class="update-card mt-3">
                <div class="update-card-header">
                    Update Available from: ${sourceName}
                </div>
                <div class="update-card-body">
                    <p>A new version (<strong>${update.new_version}</strong>) is available.</p>
                    <strong>Changelog:</strong>
                    <div class="changelog mb-3">${changelog}</div>
                    <button class="btn btn-success install-update-btn" 
                            data-url="${update.download_url}" 
                            data-source="${sourceName}">
                        <i class="fas fa-download"></i> Install Now
                    </button>
                    <div class="install-status text-muted small mt-2"></div>
                </div>
            </div>`;
    }

    // Updates Check
    $('#checkForUpdatesBtn').on('click', function() {
        const button = $(this);
        const responseContainer = $('#updateCheckResponse');
        const originalButtonText = button.html();
        
        button.html('<span class="spinner-border spinner-border-sm"></span> Checking...').prop('disabled', true);
        responseContainer.html('');
        
        $.ajax({
            url: '',
            type: 'POST',
            data: {'telegram-bot-notification-pro-action':'check_for_updates'}, 
            dataType: 'json',
            success: function(response) {
                let html = '';
                if (response.status) {
                    if (!response.github.update_available && !response.refat.update_available) {
                        html = `<div class="alert alert-success mt-3">${response.message}</div>`;
                    } else {
                        if (response.github.update_available) {
                            html += createUpdateCard('GitHub', response.github.data);
                        }
                        if (response.refat.update_available) {
                            html += createUpdateCard("Refat's Server", response.refat.data);
                        }
                    }
                } else {
                     html = `<div class="alert alert-danger mt-3">Error: ${response.message}</div>`;
                }
                responseContainer.html(html);
            },
            error: function() {
                responseContainer.html('<div class="alert alert-danger mt-3">An unexpected error occurred.</div>');
            },
            complete: function() {
                button.html(originalButtonText).prop('disabled', false);
            }
        });
    });

    // Install Update
    $(document).on('click', '.install-update-btn', function() {
        const button = $(this);
        const downloadUrl = button.data('url');
        const sourceName = button.data('source');
        const statusContainer = button.siblings('.install-status');

        if (!confirm(`Are you sure you want to update from ${sourceName}? This will back up and replace your current plugin files.`)) {
            return;
        }
        
        const originalButtonText = button.html();
        button.html('<span class="spinner-border spinner-border-sm"></span> Backing up...').prop('disabled', true);
        statusContainer.text('Creating a backup of the current version...');

        $.ajax({
            url: '',
            type: 'POST',
            data: {
                'telegram-bot-notification-pro-action': 'install_update',
                'download_url': downloadUrl
            },
            dataType: 'json',
            beforeSend: function() {
                button.html('<span class="spinner-border spinner-border-sm"></span> Installing...');
                statusContainer.text('Downloading and installing the new version...');
            },
            success: function(response) {
                if (response.status) {
                    button.html('<i class="fas fa-check"></i> Update Complete').removeClass('btn-success').addClass('btn-secondary');
                    statusContainer.text('Update successful! Please refresh the page.');
                    showResponse(response.message, true);
                } else {
                    button.html(originalButtonText).prop('disabled', false);
                    statusContainer.text('Error: ' + response.message);
                    showResponse(response.message, false);
                }
            },
            error: function() {
                button.html(originalButtonText).prop('disabled', false);
                statusContainer.text('An unexpected server error occurred.');
                showResponse('An unexpected server error occurred.', false);
            }
        });
    });

    // Dynamic Chat IDs
    $('#addChatId').on('click', function() {
        let i = $('.chat-id-row').length;
        $('#chatIdsContainer').append(`<div class="row g-2 align-items-center mb-2 chat-id-row"><div class="col"><input type="text" class="form-control" name="chat_ids[${i}][id]" placeholder="ID" required></div><div class="col"><input type="text" class="form-control" name="chat_ids[${i}][name]" placeholder="Name"></div><div class="col-auto"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="chat_ids[${i}][enabled]" checked></div></div><div class="col-auto"><button type="button" class="btn btn-soft-danger btn-icon btn-sm remove-chat-id"><i class="bi-trash"></i></button></div></div>`);
    });
    $('#chatIdsContainer').on('click', '.remove-chat-id', function() { $(this).closest('.chat-id-row').remove(); });
});
</script>