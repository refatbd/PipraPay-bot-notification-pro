# Telegram Bot Notification Pro

**Contributors:** Refat Rahman  
**Donate link:** [https://refat.ovh/donate](https://refat.ovh/donate)  
**Tags:** notification, bot notification, admin notification, telegram, multi-site, hub, node  
**Stable tag:** 2.2.1  
**License:** GPL-2.0+  

## Description

An enhanced Telegram Bot Notification plugin for PipraPay that delivers real-time transaction alerts directly to your Telegram.

**🚀 New in Version 2.2.1: Multi-Site Hub & Node Support!**

Run a single Telegram Bot for unlimited PipraPay websites.
* **👑 Hub Mode (Controller):** One site acts as the gateway. It owns the Bot Token, registers the Webhook, and manages all incoming commands.
* **🔗 Node Mode (Client):** Connect additional sites to the Hub. They send notifications through the Hub and can receive commands securely.

### Key Features
* **Hub & Node Architecture:** Manage 10+ websites with a single Bot Token.
* **Interactive Site Menu:** Typing `/sales_today` brings up a menu asking *which* site you want data from.
* **Remote Transaction Confirmation:** Approve pending payments on *any* connected site directly from Telegram.
* **Secure API Handshake:** Nodes connect to the Hub using encrypted signatures (HMAC SHA256).
* **Site Identity:** Customize "Site Name" and "Site Identifier" to know exactly where a sale came from (e.g., "From: Fashion Store").
* **Easy Setup:** Automatic configuration handling for Webhooks and Commands.
* **Granular Controls:** Enable/Disable notifications for Pending, Completed, or Failed transactions.
* **Global Toggle:** Quickly turn off the entire notification system.

## Installation

1.  Download the plugin.
2.  Upload the `telegram-bot-notification-pro` folder to your PipraPay `pp-content/plugins/modules/` directory.
3.  Activate the plugin from the PipraPay Admin Panel.
4.  Navigate to **Admin Dashboard → Module → Telegram Bot Notification Pro**.

### Setup Guide

#### Option A: Single Site (Standalone)
Use this if you only have one website.
1.  Select **Standalone Mode**.
2.  Enter your **Telegram Bot Token** (from @BotFather).
3.  Click **Connect Bot**.

#### Option B: Multi-Site (Hub & Node)
Use this to connect multiple websites to one bot.

**1. Set up the Controller (Site A):**
* Select **Hub Mode**.
* Enter your Bot Token and click **Connect Bot**.
* Copy the **API Secret Key** displayed in the settings.

**2. Set up the Client (Site B):**
* Select **Node Mode**.
* Enter the **Hub Site URL** (The URL of Site A).
* Paste the **API Secret Key** you copied from Site A.
* Click **Connect to Hub**.

*Site A (Hub) will now recognize Site B (Node) and route commands/notifications accordingly.*

## Bot Commands

You can use the following commands in your Telegram chat. If multiple sites are connected, an interactive menu will appear.

* `/start` - Get your Chat ID.
* `/last_transaction` - Get details of the most recent transaction.
* `/sales_today` - Get the total sales amount for today.
* `/sales_yesterday` - Get the total sales amount for yesterday.
* `/sales_this_month` - Get the total sales amount for the current month.
* `/pending_transactions` - Get a count of pending transactions.
* `/failed_transactions` - Get a count of failed transactions.
* `/completed_transactions` - Get a count of completed transactions.
* `/help` - Show all available commands.

## Changelog

### 2.2.1
* **NEW:** Implemented Hub & Node architecture for multi-site support.
* **NEW:** Added interactive "Select Site" menu for bot commands.
* **NEW:** Added secure API communication between Hub and Nodes.
* **NEW:** Added "Disconnect" feature for connected nodes.
* **NEW:** Notifications now display the source Site Name.
* **UPDATE:** Complete Admin UI overhaul with dynamic mode switching.
* **UPDATE:** Improved transaction confirmation logic to support remote sites.

### 2.1.1
* Added interactive "Confirm Transaction" button for pending notifications.
* Added setting to enable/disable the confirmation button.

### 2.0.0
* Complete overhaul of the plugin.
* Added support for multiple chat IDs.
* Added granular notification controls.
* Added interactive bot commands for sales and transaction data.
* Redesigned the admin interface.