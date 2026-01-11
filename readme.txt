=== Telegram Bot Notification Pro ===
Contributors: Refat Rahman
Donate link: https://refat.ovh/donate
Tags: notification, bot notification, admin notification, telegram, multi-site, hub, node
Requires at least: 1.0.0
Tested up to: 1.0.0
Stable tag: 2.2.1
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==

An enhanced Telegram Bot Notification plugin for PipraPay that delivers real-time transaction alerts directly to your Telegram. Now featuring a powerful **Hub & Node Architecture** for managing multiple sites with a single Bot.

**New in Version 2.2.0: Multi-Site Support!**
Connect multiple PipraPay installations to a single Telegram Bot.
* **Hub Mode (Controller):** One site acts as the gateway, receiving all webhooks and commands.
* **Node Mode (Client):** Connect unlimited additional sites to the Hub to send notifications and receive commands.

**Key Features:**
* **Hub & Node Architecture**: Run one bot for 10+ websites seamlessly.
* **Interactive Site Menu**: When you type `/sales_today`, the bot asks *which* site you want to view.
* **Remote Transaction Confirmation**: Approve pending payments on *any* connected site directly from Telegram.
* **Secure API Handshake**: Nodes connect to the Hub using encrypted signatures (HMAC SHA256).
* **Site Identity**: Customize "Site Name" and "Site Identifier" for clear notifications (e.g., "From: Fashion Store").
* **Easy Bot Setup**: Connect your Telegram bot by simply pasting the token.
* **Granular Controls**: Enable or disable notifications for pending, completed, and failed payments.
* **Global Toggle**: Easily enable or disable the entire notification system.
* **Interactive Bot Commands**: Get real-time sales and transaction data.

== Installation ==

1.  Download the plugin.
2.  Upload the plugin folder to your PipraPay `Plugin` section.
3.  Activate the plugin from PipraPay's module settings.
4.  Go to **Admin Dashboard → Module → Telegram Bot Notification Pro**.

**Setup Guide:**

**For Single Site (Standalone):**
1.  Select **Standalone Mode**.
2.  Enter your Bot Token.
3.  Click "Connect Bot". Done!

**For Multi-Site (Hub & Node):**
1.  **Site A (The Controller):**
    * Select **Hub Mode**.
    * Enter Bot Token and Connect.
    * Copy the **API Secret Key**.
2.  **Site B (The Client):**
    * Select **Node Mode**.
    * Enter the **Hub Site URL** (URL of Site A).
    * Paste the **API Secret Key** from Site A.
    * Click "Connect to Hub".
3.  Site A will now list Site B as a connected node.

== Bot Commands ==
You can use the following commands in your Telegram chat. If multiple sites are connected, a menu will appear to select the target site.

* `/start` - Get your Chat ID.
* `/last_transaction` - Get details of the most recent transaction.
* `/sales_today` - Get the total sales amount for today.
* `/sales_yesterday` - Get the total sales amount for yesterday.
* `/sales_this_month` - Get the total sales amount for the current month.
* `/pending_transactions` - Get a count of pending transactions.
* `/failed_transactions` - Get a count of failed transactions.
* `/completed_transactions` - Get a count of completed transactions.
* `/help` - Show all the available commands.

== Changelog ==

= 2.2.1 =
* **NEW:** Implemented Hub & Node architecture for multi-site support.
* **NEW:** Added interactive "Select Site" menu for bot commands.
* **NEW:** Added secure API communication between Hub and Nodes.
* **NEW:** Added "Disconnect" feature for connected nodes.
* **NEW:** Notifications now display the source Site Name.
* **UPDATE:** Complete Admin UI overhaul with dynamic mode switching.
* **UPDATE:** Improved transaction confirmation logic to support remote sites.

= 2.1.1 =
* Added interactive "Confirm Transaction" button for pending notifications.
* Added a setting to enable/disable the confirmation button feature.

= 2.1.0 =
* Added interactive "Confirm Transaction" button for pending notifications.

= 2.0.0 =
* Complete overhaul of the plugin
* Added support for multiple chat IDs
* Added granular notification controls
* Added interactive bot commands for sales and transaction data.
* Redesigned the admin interface