<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\telegrambridge\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use vnali\telegrambridge\helpers\Curl;
use vnali\telegrambridge\TelegramBridge;
use yii\caching\TagDependency;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Settings controller
 */
class SettingsController extends Controller
{
    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException(Craft::t('telegram-bridge', 'Administrative changes are disallowed in this environment.'));
        }
        // Require permission
        $this->requirePermission('telegram-bridge-manageSettings');

        return parent::beforeAction($action);
    }

    /**
     * Telegram bridge index page
     * @return Response
     */
    public function actionIndex(): Response
    {
        $variables = [];
        $infos = [];
        $errors = [];
        $warnings = [];
        $tokens = [];
        $users = [];

        // Check if bot token is valid
        if (!App::parseEnv('$TELEGRAM_BOT_TOKEN') || App::parseEnv('$TELEGRAM_BOT_TOKEN') == '$TELEGRAM_BOT_TOKEN') {
            $errors[] = 'You should specify TELEGRAM_BOT_TOKEN environment setting.';
        } else {
            // Get Me
            $url = TelegramBridge::BOT_URL . App::parseEnv('$TELEGRAM_BOT_TOKEN') . '/getMe';
            list($result, $error, $response) = Curl::getWithCurl($url, [], false);
            if ($result) {
                $result = json_decode($result);
            } else {
                $result = json_encode($error);
            }
            $description = $result->description ?? $result;
            if (isset($result->ok) && $result->ok) {
                $infos['Bot Information'] = json_encode($description, JSON_PRETTY_PRINT);
            } else {
                $errors[] = 'Bot information: ' . ($description ?? 'bot info error');
            }
            // Get Webhook Info
            $url = TelegramBridge::BOT_URL . App::parseEnv('$TELEGRAM_BOT_TOKEN') . '/getWebhookInfo';
            list($result, $error, $response) = Curl::getWithCurl($url, [], false);
            if ($result) {
                $result = json_decode($result);
            } else {
                $result = json_encode($error);
            }
            $description = $result->description ?? $result;
            if (isset($result->ok) && $result->ok) {
                $infos['Webhook Information'] = json_encode($description, JSON_PRETTY_PRINT);
            } else {
                $errors[] = 'Webhook information: ' . ($description ?? 'webhook info error' . json_encode($result));
            }
        }

        if (!App::parseEnv('$TELEGRAM_WEBHOOK_ADDRESS') || App::parseEnv('$TELEGRAM_WEBHOOK_ADDRESS') == '$TELEGRAM_WEBHOOK_ADDRESS') {
            $warnings[] = 'You should specify TELEGRAM_WEBHOOK_ADDRESS environment setting to be able to set webhook later.';
        }

        if (!App::parseEnv('$X_TELEGRAM_BOT_API_SECRET_TOKEN') || (App::parseEnv('$X_TELEGRAM_BOT_API_SECRET_TOKEN') == '$X_TELEGRAM_BOT_API_SECRET_TOKEN')) {
            $warnings[] = 'You should specify X_TELEGRAM_BOT_API_SECRET_TOKEN environment setting to be able to  set webhook later.';
        }

        if (!App::parseEnv('$X_CRAFT_TELEGRAM_BRIDGE_SECRET_TOKEN') || (App::parseEnv('$X_CRAFT_TELEGRAM_BRIDGE_SECRET_TOKEN') == '$X_CRAFT_TELEGRAM_BRIDGE_SECRET_TOKEN')) {
            $warnings[] = 'You should specify X_CRAFT_TELEGRAM_BRIDGE_SECRET_TOKEN environment setting to send events to telegram chats.';
        }

        // Parse supported chat ids, tokens and users
        $supportedChatIds = explode('||', App::parseEnv('$ALLOWED_TELEGRAM_CHAT_IDS'));
        $supportedChatIdsGqlToken = explode('||', App::parseEnv('$ALLOWED_TELEGRAM_CHAT_IDS_GQL_TOKEN'));
        $supportedChatIdsUser = explode('||', App::parseEnv('$ALLOWED_TELEGRAM_CHAT_IDS_USER'));

        $enabledGQL = false;
        if (App::parseEnv('$GRAPHQL_API') && (App::parseEnv('$GRAPHQL_API') != '$GRAPHQL_API')) {
            $enabledGQL = true;
        }

        // Push other telegram chat ids and their token to white list if it is allowed
        if (App::parseEnv('$ALLOW_OTHER_TELEGRAM_CHAT_IDS_GQL') == 'true') {
            $supportedChatIds[] = 'others';
            $supportedChatIdsGqlToken[] = App::parseEnv('$OTHER_TELEGRAM_CHAT_IDS_GQL_TOKEN');
            $supportedChatIdsUser[] = null;
        }

        // Check format of provided configs
        if ($supportedChatIds[0] == '$ALLOWED_TELEGRAM_CHAT_IDS') {
            // We don't set error because $ALLOWED_TELEGRAM_CHAT_IDS is not necessary when we want to only sent request via craft webhook
            $warnings[] = 'You should specify ALLOWED_TELEGRAM_CHAT_IDS environment setting to allow chats to interact with the plugin.';
        } elseif (Craft::$app->getEdition() === Craft::Pro && $enabledGQL && count($supportedChatIds) != count($supportedChatIdsGqlToken)) {
            // Check if count of telegram chat ids and their gql tokens are equal
            $errors[] = 'The count of supported chat ids and GQL tokens is not equal.';
        } else {
            // Check if count of telegram chat ids and their users are equal
            if (count($supportedChatIds) != count($supportedChatIdsUser)) {
                $errors[] = 'The count of supported chat ids and users is not equal.';
            } else {
                foreach ($supportedChatIds as $key => $supportedChatId) {
                    if (Craft::$app->getEdition() === Craft::Pro && $enabledGQL) {
                        $tokenParts = explode('-', $supportedChatIdsGqlToken[$key], 2);
                        if (!$tokenParts[0] || $tokenParts[0] == '$ALLOWED_TELEGRAM_CHAT_IDS_GQL_TOKEN' || $tokenParts[0] == '$OTHER_TELEGRAM_CHAT_IDS_GQL_TOKEN' || is_numeric($tokenParts[0]) || $tokenParts[0] == 'public') {
                            if ($tokenParts[0] == 'public') {
                                $tokens[$supportedChatId] = 'public token';
                            } elseif (is_numeric($tokenParts[0])) {
                                $tokenId = (int)$tokenParts[0];
                                // Check if provided token id and token name point to same token. we do this to prevent possible mistakes
                                if (!Craft::$app->gql->getTokenById($tokenId)) {
                                    $errors[] = "The $tokenId token id returns no token.";
                                }
                                if (!Craft::$app->gql->getTokenByName($tokenParts[1])) {
                                    $errors[] = "The $tokenParts[1] token name returns no token.";
                                }
                                if (Craft::$app->gql->getTokenById($tokenId) != Craft::$app->gql->getTokenByName($tokenParts[1])) {
                                    $errors[] = "The token id $tokenId and token name $tokenParts[1] does not return same token.";
                                } else {
                                    $tokens[$supportedChatId] = $tokenParts[1] ?? $tokenParts[0];
                                }
                            }
                        } else {
                            $errors[] = 'The format of provided supported GQL token is not valid: ' . $tokenParts[0];
                        }
                    }
                    $userParts = explode('-', $supportedChatIdsUser[$key], 2);
                    if ((is_numeric($userParts[0]) && isset($userParts[1])) || !$userParts[0] || $userParts[0] == '$ALLOWED_TELEGRAM_CHAT_IDS_USER') {
                        if (is_numeric($userParts[0]) && isset($userParts[1])) {
                            $userId = (int)$userParts[0];
                            // Check if provided user id and user email point to same user. we do this to prevent possible mistakes
                            $userById = Craft::$app->users->getUserById($userId);
                            $userByUsernameOrEmail = Craft::$app->users->getUserByUsernameOrEmail($userParts[1]);
                            if (!$userById) {
                                $errors[] = "There is no user, $supportedChatIdsUser[$key] for userId $userId";
                            }
                            if (!$userByUsernameOrEmail) {
                                $errors[] = "There is no user, $supportedChatIdsUser[$key] for $userParts[1] username/email";
                            }
                            if (isset($userById) && isset($userByUsernameOrEmail) && $userById->id != $userByUsernameOrEmail->id) {
                                $errors[] = "The user id $userId and token name $userParts[1] does not return same user.";
                            } else {
                                $users[$supportedChatId] = $userParts[1];
                            }
                        }
                    } else {
                        $errors[] = 'The format of provided user is not valid: ' . $userParts[0];
                    }
                }
            }
        }

        if (!App::parseEnv('$GRAPHQL_API') || (App::parseEnv('$GRAPHQL_API') == '$GRAPHQL_API')) {
            $warnings[] = 'You should specify GRAPHQL_API environment setting to execute GQL queries.';
        } else {
            if (!App::parseEnv('$GRAPHQL_QUERY_SECTIONS') || (App::parseEnv('$GRAPHQL_QUERY_SECTIONS') == '$GRAPHQL_QUERY_SECTIONS')) {
                $warnings[] = 'You should specify GRAPHQL_QUERY_SECTIONS environment setting to execute GQL queries.';
            }
            if (!App::parseEnv('$GRAPHQL_QUERY_FIELD') || (App::parseEnv('$GRAPHQL_QUERY_FIELD') == '$GRAPHQL_QUERY_FIELD')) {
                $warnings[] = 'You should specify GRAPHQL_QUERY_FIELD environment setting to execute GQL queries.';
            }
        }

        // Only show access table if there is no error and $ALLOWED_TELEGRAM_CHAT_IDS is set and $SHOW_CHAT_ID_TABLE set to true
        $accessTable = null;
        if (!$errors && $supportedChatIds[0] != '$ALLOWED_TELEGRAM_CHAT_IDS' && App::parseEnv('$SHOW_CHAT_ID_TABLE') == 'true') {
            $tableBuilder = new \MaddHatter\MarkdownTable\Builder();
            $tableBuilder->headers([Craft::t('telegram-bridge', 'Chat ID'), Craft::t('telegram-bridge', 'User'), Craft::t('telegram-bridge', 'GQL Token')])->align(['L', 'L', 'L']); // set column alignment
            foreach ($supportedChatIds as $key => $supportedChatId) {
                $tableBuilder->row([$supportedChatId, $users[$supportedChatId] ?? '', $tokens[$supportedChatId] ?? '']);
            }
            $accessTable = $tableBuilder->render();
        }

        $variables['infos'] = $infos;
        $variables['errors'] = $errors;
        $variables['warnings'] = $warnings;
        $variables['webhookAddress'] = App::parseEnv('$TELEGRAM_WEBHOOK_ADDRESS');
        $variables['accessTable'] = $accessTable;
        return $this->renderTemplate('telegram-bridge/settings/_index.twig', $variables);
    }

    /**
     * Set telegram webhook
     *
     * @return void
     */
    public function actionSetWebhook(): void
    {
        $cache = Craft::$app->getCache();
        $validate = true;
        if (!App::parseEnv('$TELEGRAM_BOT_TOKEN') || App::parseEnv('$TELEGRAM_BOT_TOKEN') == '$TELEGRAM_BOT_TOKEN') {
            $validate = false;
            Craft::$app->getSession()->setError(Craft::t('telegram-bridge', 'You should specify TELEGRAM_BOT_TOKEN environment setting.'));
        }
        if (!App::parseEnv('$X_TELEGRAM_BOT_API_SECRET_TOKEN') || App::parseEnv('$X_TELEGRAM_BOT_API_SECRET_TOKEN') == '$X_TELEGRAM_BOT_API_SECRET_TOKEN') {
            $validate = false;
            Craft::$app->getSession()->setError(Craft::t('telegram-bridge', 'You should specify X_TELEGRAM_BOT_API_SECRET_TOKEN environment setting.'));
        }
        if (!App::parseEnv('$TELEGRAM_WEBHOOK_ADDRESS') || App::parseEnv('$TELEGRAM_WEBHOOK_ADDRESS') == '$TELEGRAM_WEBHOOK_ADDRESS') {
            $validate = false;
            Craft::$app->getSession()->setError(Craft::t('telegram-bridge', 'You should specify TELEGRAM_WEBHOOK_ADDRESS environment setting.'));
        }
        if ($validate) {
            $url = TelegramBridge::BOT_URL . App::parseEnv('$TELEGRAM_BOT_TOKEN') . '/setWebhook';
            $data = array(
                'url' => App::parseEnv('$TELEGRAM_WEBHOOK_ADDRESS'),
                'secret_token' => App::parseEnv('$X_TELEGRAM_BOT_API_SECRET_TOKEN'),
            );
            list($result, $errors, $response) = Curl::getWithCurl($url, $data, false);
            if ($result) {
                $result = json_decode($result);
            } else {
                $result = json_encode($errors);
            }
            $description = $result->description ?? $result;
            if (isset($result->ok) && $result->ok) {
                Craft::$app->getSession()->setNotice(Craft::t('telegram-bridge', $description));
                $cache->set('set_webhook', 'set', 0, new TagDependency(['tags' => ['telegram-bridge']]));
            } else {
                Craft::$app->getSession()->setError(Craft::t('telegram-bridge', $description));
            }
        }
    }

    /**
     * Delete telegram webhook
     *
     * @return void
     */
    public function actionDeleteWebhook(): void
    {
        $cache = Craft::$app->getCache();
        $validate = true;
        if (!App::parseEnv('$TELEGRAM_BOT_TOKEN') || App::parseEnv('$TELEGRAM_BOT_TOKEN') == '$TELEGRAM_BOT_TOKEN') {
            $validate = false;
            Craft::$app->getSession()->setError(Craft::t('telegram-bridge', 'You should specify TELEGRAM_BOT_TOKEN environment setting.'));
        }
        if ($validate) {
            $url = TelegramBridge::BOT_URL . App::parseEnv('$TELEGRAM_BOT_TOKEN') . '/deleteWebhook';
            list($result, $errors, $response) = Curl::getWithCurl($url, [], false);
            if ($result) {
                $result = json_decode($result);
            } else {
                $result = json_encode($errors);
            }
            $description = $result->description ?? $result;
            if (isset($result->ok) && $result->ok) {
                Craft::$app->getSession()->setNotice(Craft::t('telegram-bridge', $description));
                $cache->set('set_webhook', 'notset', 0, new TagDependency(['tags' => ['telegram-bridge']]));
            } else {
                Craft::$app->getSession()->setError(Craft::t('telegram-bridge', $description));
            }
        }
    }
}
