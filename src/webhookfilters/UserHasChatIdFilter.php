<?php

/**
 * @copyright Copyright (c) vnali
 */

 namespace vnali\telegrambridge\webhookfilters;

use Craft;
use craft\webhooks\filters\ExclusiveFilterInterface;
use vnali\telegrambridge\TelegramBridge;
use yii\base\Event;

abstract class UserHasChatIdFilter implements ExclusiveFilterInterface
{
    public static function displayName(): string
    {
        return Craft::t('telegram-bridge', 'The user has a chat id');
    }

    public static function show(string $class, string $event): bool
    {
        return true;
    }

    public static function excludes(): array
    {
        return [
            IsGuestFilter::class,
        ];
    }

    public static function check(Event $event, bool $value): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        $hasChatId = false;
        if ($user) {
            $username = $user->username;
            $chatId = TelegramBridge::$plugin->chatId->getChatIdByUser($username);
            if ($chatId) {
                $hasChatId = true;
            }
        }
        return  $hasChatId === $value;
    }
}
