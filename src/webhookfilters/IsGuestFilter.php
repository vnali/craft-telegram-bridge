<?php

/**
 * @copyright Copyright (c) vnali
 */

 namespace vnali\telegrambridge\webhookfilters;

use Craft;
use craft\webhooks\filters\ExclusiveFilterInterface;
use yii\base\Event;

abstract class IsGuestFilter implements ExclusiveFilterInterface
{
    public static function displayName(): string
    {
        return Craft::t('telegram-bridge', 'Is Guest');
    }

    public static function show(string $class, string $event): bool
    {
        return true;
    }

    public static function excludes(): array
    {
        return [
            UserHasChatIdFilter::class,
            UserIsAdminFilter::class,
        ];
    }

    public static function check(Event $event, bool $value): bool
    {
        $user = Craft::$app->getUser();
        $isGuest = false;
        if ($user->getIsGuest()) {
            $isGuest = true;
        }
        return  $isGuest === $value;
    }
}
