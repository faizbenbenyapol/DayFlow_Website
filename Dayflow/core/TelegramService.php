<?php
// core/TelegramService.php

class TelegramService
{
    /**
     * Send a message to a user's Telegram chat if configured and event is enabled.
     * 
     * @param int $userId The user ID to send the notification to.
     * @param string $event The event name (e.g., 'project', 'task', 'note').
     * @param string $message The message content (HTML formatted).
     * @return bool True if sent or skipped intentionally, false on error.
     */
    public static function sendNotification(int $userId, string $event, string $message): bool
    {
        require_once __DIR__ . '/../models/User.php';
        
        $settings = User::getSettings($userId);
        
        // Check if token and chat ID exist
        if (empty($settings['telegram_bot_token']) || empty($settings['telegram_chat_id'])) {
            return true; // Not configured, silently skip
        }
        
        // Check if event is enabled
        if (!empty($settings['telegram_notify_events'])) {
            $events = json_decode($settings['telegram_notify_events'], true);
            if (is_array($events) && isset($events[$event]) && $events[$event] == false) {
                return true; // Event is disabled by user
            }
        }
        
        return self::sendMessage($settings['telegram_bot_token'], $settings['telegram_chat_id'], $message);
    }

    /**
     * Send a raw message using bot token and chat id.
     */
    public static function sendMessage(string $botToken, string $chatId, string $message): bool
    {
        $url = "https://api.telegram.org/bot" . $botToken . "/sendMessage";
        $data = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML'
        ];

        // Try CURL first
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5); 
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $httpCode === 200;
        }

        // Fallback to file_get_contents
        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data),
                'timeout' => 5
            ]
        ];
        $context  = stream_context_create($options);
        $result = @file_get_contents($url, false, $context);
        
        return $result !== false;
    }

    public static function formatThaiDate(string $dateString): string
    {
        $time = strtotime($dateString);
        if (!$time) return '-';
        $months = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        $d = date('j', $time);
        $m = $months[(int)date('n', $time)];
        $y = date('Y', $time);
        return "$d $m $y";
    }
    
    public static function formatThaiDateTime(string $dateString): string
    {
        $time = strtotime($dateString);
        if (!$time) return '-';
        $months = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
        $d = date('j', $time);
        $m = $months[(int)date('n', $time)];
        $y = date('Y', $time);
        $t = date('H:i', $time);
        return "$d $m $y เวลา $t น.";
    }

    public static function formatMessage(string $title, array $details, ?string $status = null): string
    {
        $msg = "<b>[ DayFlow ]</b>\n\n";
        $msg .= "<b>{$title}</b>\n\n";
        $msg .= "────────────────────────\n\n";
        
        foreach ($details as $k => $v) {
            $msg .= "{$k}\n{$v}\n\n";
        }
        
        $msg .= "────────────────────────\n";
        if ($status) {
            $msg .= "\n{$status}";
        }
        
        return $msg;
    }
}
