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

    public static function extractGQLFields($parsedQuery)
    {
        if (isset($parsedQuery->definitions[0]->selectionSet)) {
            $selections = iterator_to_array($parsedQuery->definitions[0]->selectionSet->selections);
            return array_map(function($selection) {
                $field = [
                    'field' => $selection->name->value,
                    'arguments' => [],
                ];
                if (isset($selection->arguments)) {
                    foreach ($selection->arguments as $argument) {
                        if ($argument->value->kind === 'ListValue') {
                            $listValues = [];
                            foreach ($argument->value->values as $listItem) {
                                $listValues[] = $listItem->value;
                            }
                            $field['arguments'][$argument->name->value] = $listValues;
                        } elseif ($argument->value->kind === 'Variable') {
                            $field['arguments'][$argument->name->value] = '$' . $argument->value->name->value;
                        } else {
                            $field['arguments'][$argument->name->value] = $argument->value->value;
                        }
                    }
                }
                return $field;
            }, $selections);
        }
        return [];
    }
}
