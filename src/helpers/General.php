<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\telegrambridge\helpers;

use Craft;
use vnali\telegrambridge\TelegramBridge;

class General
{
    /**
     * Check if format of date is correct
     *
     * @param string $date
     * @return bool
     */
    public static function isYmdFormat(string $date)
    {
        $datetime = \DateTime::createFromFormat('Y-m-d', $date);
        return $datetime && $datetime->format('Y-m-d') === $date;
    }

    /**
     * Check if the user can access tools
     *
     * @param int $userId
     * @return bool
     */
    public static function canAccessTools(int $userId)
    {
        $toolTypes = TelegramBridge::$plugin->tool->getAllToolTypes();
        $user = Craft::$app->users->getUserById($userId);
        if ($user) {
            foreach ($toolTypes as $key => $toolType) {
                if ($user->can('telegram-bridge-accessTool-' . $toolType::handle())) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Check if the user can access a tool
     *
     * @param int $userId
     * @param string $toolType
     * @return bool
     */
    public static function canAccessTool(int $userId, string $toolType): bool
    {
        $user = Craft::$app->users->getUserById($userId);
        if ($user) {
            if ($user->can('telegram-bridge-accessTool-' . $toolType::handle())) {
                return true;
            }
        }
        return false;
    }
}
