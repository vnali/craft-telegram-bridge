<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\telegrambridge\helpers;

use craft\helpers\App;

class Gql
{
    public static function getTypeName(mixed $typeNode): array
    {
        if ($typeNode->kind === 'ListType') {
            return array(Gql::getTypeName($typeNode->type), true, true);
        } elseif ($typeNode->kind === 'NonNullType') {
            return array(Gql::getTypeName($typeNode->type), false, false);
        } else {
            return array($typeNode->name->value, false, true);
        }
    }

    /**
     * Get gql access token of a telegram chat id
     *
     * @param array $gqlAccessTokens
     * @param string $chatId
     * @return string|null
     */
    public static function gqlQueriesAccessToken(array $gqlAccessTokens, string $chatId): ?string
    {
        $gqlAccessToken = null;
        if (isset($gqlAccessTokens[$chatId])) {
            $gqlAccessToken = $gqlAccessTokens[$chatId];
        } elseif (App::parseEnv('$ALLOW_OTHER_TELEGRAM_CHAT_IDS_GQL') == 'true' && isset($gqlAccessTokens['others'])) {
            $gqlAccessToken = $gqlAccessTokens['others'];
        }
        return $gqlAccessToken;
    }
}
