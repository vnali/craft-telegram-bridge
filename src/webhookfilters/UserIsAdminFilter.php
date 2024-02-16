<?php

/**
 * @copyright Copyright (c) vnali
 */

 namespace vnali\telegrambridge\webhookfilters;

use Craft;
use craft\webhooks\filters\ExclusiveFilterInterface;
use yii\base\Event;

abstract class UserIsAdminFilter implements ExclusiveFilterInterface
{
    public static function displayName(): string
    {
        return Craft::t('telegram-bridge', 'The user is admin');
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
        $user = Craft::$app->getUser();
        $isAdmin = false;
        if ($user->getIsAdmin()) {
            $isAdmin = true;
        }
        return  $isAdmin === $value;
    }
}
