<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\telegrambridge\services;

use Craft;
use craft\elements\User;
use craft\helpers\App;
use yii\base\Component;

class chatIdService extends Component
{
    /**
     * Get the chat id by user
     *
     * @param string $username
     * @return string|null
     */
    public function getChatIdByUser(string $username): ?string
    {
        if (!App::parseEnv('$ALLOWED_TELEGRAM_CHAT_IDS') || App::parseEnv('$ALLOWED_TELEGRAM_CHAT_IDS') == '$ALLOWED_TELEGRAM_CHAT_IDS') {
            return null;
        }
        if (!App::parseEnv('$ALLOWED_TELEGRAM_CHAT_IDS_USER') || App::parseEnv('$ALLOWED_TELEGRAM_CHAT_IDS_USER') == '$ALLOWED_TELEGRAM_CHAT_IDS_USER') {
            return null;
        }
        $supportedChatIds = explode('||', App::parseEnv('$ALLOWED_TELEGRAM_CHAT_IDS'));
        $supportedChatIdsUser = explode('||', App::parseEnv('$ALLOWED_TELEGRAM_CHAT_IDS_USER'));
        // Check if count of telegram chat ids and their users are equal
        if (count($supportedChatIds) != count($supportedChatIdsUser)) {
            return null;
        }
        foreach ($supportedChatIds as $key => $supportedChatId) {
            $userParts = explode('-', $supportedChatIdsUser[$key], 2);
            if (isset($userParts[1]) && $userParts[1] == $username) {
                if (is_numeric($userParts[0])) {
                    $userId = (int)$userParts[0];
                    // Check if provided user id and user email point to same user. we do this to prevent possible mistakes
                    $userById = Craft::$app->users->getUserById($userId);
                    $usernameById = $userById->username;
                    if ($usernameById != $userParts[1]) {
                        return null;
                    }
                    return $supportedChatId;
                } else {
                    return null;
                }
            }
        }
        return null;
    }

    /**
     * Get the user by chat id
     *
     * @param string $chatId
     * @return User|null
     */
    public function getUserByChatId(string $chatId): ?User
    {
        if (!App::parseEnv('$ALLOWED_TELEGRAM_CHAT_IDS') || App::parseEnv('$ALLOWED_TELEGRAM_CHAT_IDS') == '$ALLOWED_TELEGRAM_CHAT_IDS') {
            return null;
        }
        if (!App::parseEnv('$ALLOWED_TELEGRAM_CHAT_IDS_USER') || App::parseEnv('$ALLOWED_TELEGRAM_CHAT_IDS_USER') == '$ALLOWED_TELEGRAM_CHAT_IDS_USER') {
            return null;
        }
        $supportedChatIds = explode('||', App::parseEnv('$ALLOWED_TELEGRAM_CHAT_IDS'));
        $supportedChatIdsUser = explode('||', App::parseEnv('$ALLOWED_TELEGRAM_CHAT_IDS_USER'));
        // Check if count of telegram chat ids and their users are equal
        if (count($supportedChatIds) != count($supportedChatIdsUser)) {
            return null;
        }
        foreach ($supportedChatIds as $key => $supportedChatId) {
            if ($chatId == $supportedChatId) {
                $userParts = explode('-', $supportedChatIdsUser[$key], 2);
                if (is_numeric($userParts[0]) && isset($userParts[1])) {
                    $userId = (int)$userParts[0];
                    // Check if provided user id and user email point to same user. we do this to prevent possible mistakes
                    $userById = Craft::$app->users->getUserById($userId);
                    $username = $userById->name;
                    if ($username != $userParts[1]) {
                        return null;
                    }
                    return $userById;
                } else {
                    return null;
                }
            }
        }
        return null;
    }
}
