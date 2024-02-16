<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\telegrambridge\controllers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Entry;
use craft\helpers\App;
use craft\helpers\FileHelper;
use craft\helpers\Gql as GqlHelper;
use craft\web\Controller;
use CURLFile;
use GraphQL\Language\Parser;
use vnali\telegrambridge\base\ToolTypeInterface;
use vnali\telegrambridge\helpers\Curl;
use vnali\telegrambridge\helpers\General;
use vnali\telegrambridge\helpers\Gql;
use vnali\telegrambridge\TelegramBridge;
use yii\caching\FileDependency;
use yii\caching\TagDependency;
use yii\web\ForbiddenHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

/**
 * Default controller
 */
class DefaultController extends Controller
{
    protected int|bool|array $allowAnonymous = ['craft-webhook', 'telegram-webhook'];

    protected int $callbackQueryId;

    protected string $chatId;

    protected array $chatIdUsers = [];

    protected string|null $gqlAccessToken;

    protected array $gqlAccessTokens = [];

    protected string|null $language = null;

    protected int $stepCounter = 0;

    protected array $supportedChatIds = [];

    protected string $updateText;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
    }

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        // Don’t require a CSRF token for incoming webhooks from Craft and Telegram
        if ($action->id === 'craft-webhook' || $action->id === 'telegram-webhook') {
            $this->enableCsrfValidation = false;
        }

        // We should check format of some settings before telegram-webhook and get-updates action
        if ($action->id == 'telegram-webhook' || $action->id == 'get-updates') {
            if (!App::parseEnv('$TELEGRAM_BOT_TOKEN') || App::parseEnv('$TELEGRAM_BOT_TOKEN') == '$TELEGRAM_BOT_TOKEN') {
                throw new ServerErrorHttpException('You should specify TELEGRAM_BOT_TOKEN environment setting.');
            }

            $supportedChatIds = explode('||', App::parseEnv('$ALLOWED_TELEGRAM_CHAT_IDS'));
            $supportedChatIdsGqlToken = explode('||', App::parseEnv('$ALLOWED_TELEGRAM_CHAT_IDS_GQL_TOKEN'));
            $supportedChatIdsUser = explode('||', App::parseEnv('$ALLOWED_TELEGRAM_CHAT_IDS_USER'));

            // Push non mentioned telegram chat ids and their token to whitelist if it is allowed
            if (App::parseEnv('$ALLOW_OTHER_TELEGRAM_CHAT_IDS_GQL') == 'true') {
                $supportedChatIds[] = 'others';
                $supportedChatIdsGqlToken[] = App::parseEnv('$OTHER_TELEGRAM_CHAT_IDS_GQL_TOKEN');
                $supportedChatIdsUser[] = null;
            }

            $enabledGQL = false;
            if (App::parseEnv('$GRAPHQL_API') && (App::parseEnv('$GRAPHQL_API') != '$GRAPHQL_API')) {
                $enabledGQL = true;
            }

            // Check if the count of telegram chat ids and their gql tokens is equal
            if (Craft::$app->getEdition() === Craft::Pro && $enabledGQL && count($supportedChatIds) != count($supportedChatIdsGqlToken)) {
                throw new ServerErrorHttpException('The count of $ALLOWED_TELEGRAM_CHAT_IDS and $ALLOWED_TELEGRAM_CHAT_IDS_GQL_TOKEN items is not equal.');
            }

            // Check if the count of telegram chat ids and their users is equal
            if (count($supportedChatIds) != count($supportedChatIdsUser)) {
                throw new ServerErrorHttpException('The count of $ALLOWED_TELEGRAM_CHAT_IDS and $ALLOWED_TELEGRAM_CHAT_IDS_USER items is not equal.');
            }

            $this->supportedChatIds = $supportedChatIds;

            foreach ($supportedChatIds as $key => $supportedChatId) {
                if (Craft::$app->getEdition() === Craft::Pro && $enabledGQL) {
                    $tokenParts = explode('-', $supportedChatIdsGqlToken[$key], 2);
                    // Token can be not set, public or first part should be a number
                    if (!$tokenParts[0] || $tokenParts[0] == '$ALLOWED_TELEGRAM_CHAT_IDS_GQL_TOKEN' || $tokenParts[0] == '$OTHER_TELEGRAM_CHAT_IDS_GQL_TOKEN' || is_numeric($tokenParts[0]) || $tokenParts[0] == 'public') {
                        if ($tokenParts[0] == 'public') {
                            $token = Craft::$app->gql->getPublicToken();
                            $this->gqlAccessTokens[$supportedChatId] = $token->accessToken;
                        } elseif (is_numeric($tokenParts[0])) {
                            $tokenId = (int)$tokenParts[0];
                            $token = Craft::$app->gql->getTokenById($tokenId);
                            if (!$token) {
                                throw new ServerErrorHttpException("The token id $tokenId does not return a token");
                            }
                            // Check if provided token id and token name point to same token. we do this to prevent typo mistakes which lead to possible unwanted access
                            if ($token != Craft::$app->gql->getTokenByName($tokenParts[1])) {
                                throw new ServerErrorHttpException("The token id $tokenId and token name $tokenParts[1] does not return same token");
                            }
                            $this->gqlAccessTokens[$supportedChatId] = $token->accessToken;
                        }
                    } else {
                        throw new ServerErrorHttpException('The format of provided supported GQL token is not valid: ' . $tokenParts[0]);
                    }
                }
                $userParts = explode('-', $supportedChatIdsUser[$key], 2);
                // can be in format of userId-email/username or null
                if ((is_numeric($userParts[0]) && isset($userParts[1])) || !$userParts[0] || $userParts[0] == '$ALLOWED_TELEGRAM_CHAT_IDS_USER') {
                    if (is_numeric($userParts[0]) && isset($userParts[1])) {
                        $userId = (int)$userParts[0];
                        // Check if provided user id and user email point to the same user. we do this to prevent typo mistakes which lead to possible unwanted access
                        $userById = Craft::$app->users->getUserById($userId);
                        $userByUsernameOrEmail = Craft::$app->users->getUserByUsernameOrEmail($userParts[1]);
                        if (!$userById) {
                            throw new ServerErrorHttpException("The user id $userId does not return a user");
                        }
                        if (!$userByUsernameOrEmail) {
                            throw new ServerErrorHttpException("The $userParts[1] does not return a user");
                        }
                        if ($userById->id != $userByUsernameOrEmail->id) {
                            throw new ServerErrorHttpException("The $userId and $userParts[1] does not return same user");
                        }
                        $this->chatIdUsers[$supportedChatId] = $userId;
                    } else {
                        $this->chatIdUsers[$supportedChatId] = null;
                    }
                } else {
                    throw new ServerErrorHttpException('The format of provided user is not valid: ' . $userParts[0]);
                }
            }
        }

        return parent::beforeAction($action);
    }

    /**
     * Process the requests sent by webhook plugin
     *
     * @return void
     */
    public function actionCraftWebhook(): void
    {
        // Check plugin secret token
        if (!isset($_SERVER['HTTP_X_CRAFT_TELEGRAM_BRIDGE_SECRET_TOKEN']) || (App::parseEnv('$X_CRAFT_TELEGRAM_BRIDGE_SECRET_TOKEN') == '$X_CRAFT_TELEGRAM_BRIDGE_SECRET_TOKEN') || (App::parseEnv('$X_CRAFT_TELEGRAM_BRIDGE_SECRET_TOKEN') != $_SERVER['HTTP_X_CRAFT_TELEGRAM_BRIDGE_SECRET_TOKEN'])) {
            throw new ForbiddenHttpException("plugin secret token is not valid");
        }
        $url = TelegramBridge::BOT_URL . App::parseEnv('$TELEGRAM_BOT_TOKEN') . "/sendMessage";
        $input = file_get_contents('php://input');
        $request = json_decode($input, true);
        if (!isset($request['chatIds'])) {
            throw new ServerErrorHttpException('chatIds is not provided');
        }
        if (!isset($request['message'])) {
            throw new ServerErrorHttpException('message is not provided');
        }
        if (isset($request['parseMode']) && $request['parseMode'] != 'HTML') {
            throw new ServerErrorHttpException('parseMode is not valid');
        }
        $time = '';
        if (isset($request['time'])) {
            $time = $request['time'] . PHP_EOL;
        }
        $username = '';
        if (isset($request['user'])) {
            $username = $request['user'] . PHP_EOL;
        }
        $message = $request['message'];
        $message = $time . $username . $message;
        $chatIds = $request['chatIds'];
        $chatIds = explode(',', $chatIds);
        // TODO: send request parallel
        foreach ($chatIds as $chatId) {
            $data = [
                'chat_id' => trim($chatId),
                'text' => $message,
            ];
            if (isset($request['parseMode'])) {
                $data['parse_mode'] = $request['parseMode'];
            }
            Curl::getWithCurl($url, $data, false);
        }
    }

    /**
     * Process the requests sent by telegram
     *
     * @return void
     */
    public function actionTelegramWebhook(): void
    {
        if (!isset($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) || (App::parseEnv('$X_TELEGRAM_BOT_API_SECRET_TOKEN') == '$X_TELEGRAM_BOT_API_SECRET_TOKEN') || (App::parseEnv('$X_TELEGRAM_BOT_API_SECRET_TOKEN') != $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'])) {
            throw new ForbiddenHttpException("Telegram secret token is not valid");
        }
        $input = file_get_contents('php://input');
        $result = json_decode($input);
        if (isset($result->message->photo[3]['file_id']) && isset($result->message->chat->id) && isset($result->message->chat->type) && $result->message->chat->type == 'private') {
            $this->updateText = $this->actionProcessFile($result->message->photo[3]['file_id']);
            $this->chatId = $result->message->chat->id;
        } elseif (isset($result->message->document->file_id) && isset($result->message->chat->id) && isset($result->message->chat->type) && $result->message->chat->type == 'private') {
            $this->updateText = $this->actionProcessFile($result->message->document->file_id);
            $this->chatId = $result->message->chat->id;
        } elseif (isset($result->message->voice->file_id) && isset($result->message->chat->id) && isset($result->message->chat->type) && $result->message->chat->type == 'private') {
            $this->updateText = $this->actionProcessFile($result->message->voice->file_id);
            $this->chatId = $result->message->chat->id;
        } elseif (isset($result->message->audio->file_id) && isset($result->message->chat->id) && isset($result->message->chat->type) && $result->message->chat->type == 'private') {
            $this->updateText = $this->actionProcessFile($result->message->audio->file_id);
            $this->chatId = $result->message->chat->id;
        } elseif (isset($result->message->video->file_id) && isset($result->message->chat->id) && isset($result->message->chat->type) && $result->message->chat->type == 'private') {
            $this->updateText = $this->actionProcessFile($result->message->video->file_id);
            $this->chatId = $result->message->chat->id;
        } elseif (isset($result->message) && isset($result->message->text) && isset($result->message->chat->id) && isset($result->message->chat->type) && $result->message->chat->type == 'private') {
            $this->updateText = $result->message->text;
            $this->chatId = $result->message->chat->id;
        } elseif (isset($result->callback_query) && isset($result->callback_query->data) && isset($result->callback_query->message->chat->id) && isset($result->callback_query->id) && isset($result->callback_query->message->chat->type) && $result->callback_query->message->chat->type == 'private') {
            $this->updateText = $result->callback_query->data;
            $this->chatId = $result->callback_query->message->chat->id;
            $this->callbackQueryId = $result->callback_query->id;
        } else {
            throw new ServerErrorHttpException('message is not valid' . $input);
        }
        $this->gqlAccessToken = Gql::gqlQueriesAccessToken($this->gqlAccessTokens, $this->chatId);
        // user language
        $user = TelegramBridge::$plugin->chatId->getUserByChatId($this->chatId);
        if ($user) {
            $this->language = $user->getPreference('language') ?? 'en';
        }
        $this->actionAnalyze();
    }

    /**
     * Get updates from telegram. only for test reasons
     *
     * @return Response
     */
    public function actionGetUpdates(): Response
    {
        $this->requirePermission('telegram-bridge-getUpdates');
        $cache = Craft::$app->getCache();
        $url = TelegramBridge::BOT_URL . App::parseEnv('$TELEGRAM_BOT_TOKEN') . "/getUpdates";
        $data = [
            'limit' => 1,
        ];
        if ($cache->get('last_process_update_id')) {
            $data['offset'] = $cache->get('last_process_update_id') + 1;
        }
        list($results) = Curl::getWithCurl($url, $data, false);
        $results = json_decode($results);
        if (isset($results->result) && is_array($results->result)) {
            /** @var object $result */
            foreach ($results->result as $result) {
                if (isset($result->message->photo[3]->file_id) && isset($result->message->chat->id) && isset($result->message->chat->type) && $result->message->chat->type == 'private') {
                    $this->updateText = $this->actionProcessFile($result->message->photo[3]->file_id);
                    $this->chatId = $result->message->chat->id;
                } elseif (isset($result->message->document->file_id) && isset($result->message->chat->id) && isset($result->message->chat->type) && $result->message->chat->type == 'private') {
                    $this->updateText = $this->actionProcessFile($result->message->document->file_id);
                    $this->chatId = $result->message->chat->id;
                } elseif (isset($result->message->voice->file_id) && isset($result->message->chat->id) && isset($result->message->chat->type) && $result->message->chat->type == 'private') {
                    $this->updateText = $this->actionProcessFile($result->message->voice->file_id);
                    $this->chatId = $result->message->chat->id;
                } elseif (isset($result->message->audio->file_id) && isset($result->message->chat->id) && isset($result->message->chat->type) && $result->message->chat->type == 'private') {
                    $this->updateText = $this->actionProcessFile($result->message->audio->file_id);
                    $this->chatId = $result->message->chat->id;
                } elseif (isset($result->message->video->file_id) && isset($result->message->chat->id) && isset($result->message->chat->type) && $result->message->chat->type == 'private') {
                    $this->updateText = $this->actionProcessFile($result->message->video->file_id);
                    $this->chatId = $result->message->chat->id;
                } elseif (isset($result->message) && isset($result->message->text) && isset($result->message->chat->id) && isset($result->message->chat->type) && $result->message->chat->type == 'private') {
                    $this->updateText = $result->message->text;
                    $this->chatId = $result->message->chat->id;
                } elseif (isset($result->callback_query) && isset($result->callback_query->data) && isset($result->callback_query->message->chat->id) && isset($result->callback_query->id) && isset($result->callback_query->message->chat->type) && $result->callback_query->message->chat->type == 'private') {
                    $this->updateText = $result->callback_query->data;
                    $this->chatId = $result->callback_query->message->chat->id;
                    $this->callbackQueryId = $result->callback_query->id;
                }
                $cache->set('last_process_update_id', $result->update_id, 0, new TagDependency(['tags' => ['telegram-bridge']]));
                if (isset($this->updateText) && isset($this->chatId)) {
                    // Get Gql Access token
                    $this->gqlAccessToken = Gql::gqlQueriesAccessToken($this->gqlAccessTokens, $this->chatId);
                    // User language
                    $user = TelegramBridge::$plugin->chatId->getUserByChatId($this->chatId);
                    if ($user) {
                        $this->language = $user->getPreference('language') ?? 'en';
                    }
                    $this->actionAnalyze();
                } else {
                    craft::info('This update object does not contain message');
                }
            }
        }
        $variables = [];
        // Check whether telegram webhook is set or not
        $setWebhook = null;
        if ($cache->get('set_webhook') == 'notset') {
            $setWebhook = false;
        } elseif ($cache->get('set_webhook') == 'set') {
            $setWebhook = true;
        }
        if (!$cache->get('set_webhook')) {
            $url = TelegramBridge::BOT_URL . App::parseEnv('$TELEGRAM_BOT_TOKEN') . '/getWebhookInfo';
            list($result) = Curl::getWithCurl($url, [], false);
            $result = json_decode($result);
            if (isset($result->result->url) && $result->result->url) {
                $setWebhook = true;
                $cache->set('set_webhook', 'set', 0, new TagDependency(['tags' => ['telegram-bridge']]));
            } else {
                $setWebhook = false;
                $cache->set('set_webhook', 'notset', 0, new TagDependency(['tags' => ['telegram-bridge']]));
            }
        }
        $variables['setWebhook'] = $setWebhook;
        //
        $variables['autoRefresh'] = 1000;
        $auto_refresh_get_updates_per_millisec = App::parseEnv('$AUTO_REFRESH_GET_UPDATES_PER_MILLISEC');
        if ($auto_refresh_get_updates_per_millisec && $auto_refresh_get_updates_per_millisec != '$AUTO_REFRESH_GET_UPDATES_PER_MILLISEC') {
            $variables['autoRefresh'] = $auto_refresh_get_updates_per_millisec;
        }
        return $this->renderTemplate('telegram-bridge/_admin/index.twig', $variables);
    }

    /**
     * Get fileId of file and generate data URI
     *
     * @param string $fileId
     * @return string
     */
    protected function actionProcessFile(string $fileId): string
    {
        $url = TelegramBridge::BOT_URL . App::parseEnv('$TELEGRAM_BOT_TOKEN') . "/getFile";
        $data = [
            'file_id' => $fileId,
        ];
        list($result) = Curl::getWithCurl($url, $data, false);
        $result = json_decode($result);
        $filePath = $result->result->file_path;

        $url = TelegramBridge::BOT_FILE_URL . App::parseEnv('$TELEGRAM_BOT_TOKEN') . "/" . $filePath;
        $data = [];
        list($result) = Curl::getWithCurl($url, $data, false);
        $dataString = base64_encode($result);

        $mimeType = FileHelper::getMimeTypeByExtension($filePath);

        // Construct the data URI
        $result = "data:$mimeType;base64,$dataString";

        return $result;
    }

    /**
     * Analyze user message
     *
     * @return void
     */
    protected function actionAnalyze(): void
    {
        $urlCallback = TelegramBridge::BOT_URL . App::parseEnv('$TELEGRAM_BOT_TOKEN') . "/answerCallbackQuery";
        $urlMessage = TelegramBridge::BOT_URL . App::parseEnv('$TELEGRAM_BOT_TOKEN') . "/sendMessage";
        $urlPhoto = TelegramBridge::BOT_URL . App::parseEnv('$TELEGRAM_BOT_TOKEN') . "/sendPhoto";
        $cache = Craft::$app->getCache();

        // Don't respond to user if they are not in supported chat id lists, if other telegram chat ids can't send GQL queries and if /chatId command is not allowed or sent
        if (!in_array($this->chatId, $this->supportedChatIds) && (App::parseEnv('$ALLOW_OTHER_TELEGRAM_CHAT_IDS_GQL') != 'true') && (strtolower($this->updateText) != '/chatid' || App::parseEnv('$ALLOW_CHAT_ID_COMMAND') != 'true')) {
            craft::warning('A not valid chatId recieved' . $this->chatId);
            return;
        }

        // if the /start is pressed, the user's record who sent the message is changed, .env file is modified or plugin cache is deleted start again
        if ($this->updateText == '/start' || $cache->get('user_changed_' . $this->chatId) || !$cache->get('env_is_not_modified') || !$cache->get('cache_is_not_deleted')) {
            TagDependency::invalidate(Craft::$app->getCache(), 'telegram-bridge-' . $this->chatId);
            $cache->set('env_is_not_modified', 1, 0, new FileDependency([
                'fileName' => Craft::getAlias('@dotenv'),
            ]));
            $cache->set('cache_is_not_deleted', 1, 0, new TagDependency(['tags' => ['telegram-bridge']]));
            $data = $this->createResponse('home');
            $cache->set('next_message_type_' . $this->chatId, 'home', 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
        } elseif (strtolower($this->updateText) == '/chatid') {
            if (App::parseEnv('$ALLOW_CHAT_ID_COMMAND') == 'true') {
                $replyMarkup = null;
                $messageText = $this->chatId;
                $data = $this->actionPrepareData($messageText, $this->chatId, $replyMarkup);
            }
        } elseif (($this->updateText == Craft::t('telegram-bridge', 'Tools', [], $this->language) . ' 📋')) {
            if (isset($this->chatIdUsers[$this->chatId]) && General::canAccessTools($this->chatIdUsers[$this->chatId])) {
                $this->clearCaches();
                $cache->set('current_menu_' . $this->chatId, 'tools', 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                $data = $this->createResponse('tool category', 'inline_keyboard');
                $cache->set('next_message_type_' . $this->chatId, 'tool_category', 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
            }
        } elseif (($this->updateText == Craft::t('telegram-bridge', 'Queries', [], $this->language) . '❓')) {
            $this->clearCaches();
            $cache->set('current_menu_' . $this->chatId, 'queries', 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
            $data = $this->createResponse('queries', 'inline_keyboard');
            $cache->set('next_message_type_' . $this->chatId, 'query_type', 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
        } elseif ($cache->get('next_message_type_' . $this->chatId) == 'tool_category') {
            if (isset($this->chatIdUsers[$this->chatId]) && class_exists($this->updateText) && (is_subclass_of($this->updateText, ToolTypeInterface::class)) && General::canAccessTool($this->chatIdUsers[$this->chatId], $this->updateText)) {
                $cache->set('tool_category_' . $this->chatId, $this->updateText, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                $data = $this->createResponse('tools', 'inline_keyboard');
                $cache->set('next_message_type_' . $this->chatId, 'tool', 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
            }
        } elseif ($cache->get('next_message_type_' . $this->chatId) == 'criteria') {
            $validate = true;
            $error = null;
            $step = $cache->get('criteria_step_' . $this->chatId);
            $currentMenu = $cache->get('current_menu_' . $this->chatId);
            if ($currentMenu == 'queries') {
                $steps = $this->queryCriteriaSteps();
            } elseif ($currentMenu == 'tools') {
                $steps = $this->toolCriteriaSteps();
            } else {
                throw new ServerErrorHttpException("Error Processing Request");
            }
            // If there is a previous step and user want go to that step
            if ($cache->get('previous_criteria_step_' . $this->chatId) && ($this->updateText == '⬅️ ' . Craft::t('telegram-bridge', 'Previous Step', [], $this->language))) {
                foreach ($steps as $stepKey => $stepItem) {
                    if ($step == $stepKey) {
                        // break, we get to the current step
                        break;
                    // we don't show offset as a step to user so ignore it because it is returned in steps
                    } elseif ($stepKey != 'offset') {
                        $previousStep = $stepKey;
                    }
                }
                if (isset($previousStep) && isset($stepKey)) {
                    // there is a previous step. clear its data, also clear current step data if there is any
                    $cache->delete($stepKey . '_' . $this->chatId);
                    $cache->delete($previousStep . '_' . $this->chatId);
                }
                // Now process steps
                $data = $this->stepProcess();
            } elseif (isset($steps[$step]['multiple']) && $steps[$step]['multiple']) {
                // this step is multiple. get data in cache if there is any
                $update = $cache->get($step . '_' . $this->chatId);
                if (!$update) {
                    $update = [];
                }
                // this is multiple step, if next step is not requested and * is not selected for site step
                if (($this->updateText != (Craft::t('telegram-bridge', 'Next Step', [], $this->language) . ' ➡️')) && ($step != 'site' || $this->updateText != '*')) {
                    // check if step has validation
                    if (isset($steps[$step]['validation'])) {
                        list($validate, $error) = call_user_func($steps[$step]['validation'], $this->updateText, $this->chatId);
                    }
                    // it is not validated, don't process the response. pass error to the user
                    if (!$validate) {
                        $data = $this->actionPrepareData($error, $this->chatId);
                    } else {
                        // validated
                        // if input is null it should not be in array
                        if ($this->updateText == 'null') {
                            $update = null;
                        } else {
                            array_push($update, $this->updateText);
                        }
                        $message = craft::t('telegram-bridge', 'Please select another one.', [], $this->language);
                        $data = $this->actionPrepareData($message, $this->chatId, null, null, 'inline_keyboard');
                        $cache->set($step . '_' . $this->chatId, $update, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                        if ($this->updateText == 'null') {
                            $data = $this->stepProcess();
                        }
                    }
                } else {
                    // We should go to next step
                    // TODO: check what happen with * as site
                    $cache->set($step . '_' . $this->chatId, $update, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                    $data = $this->stepProcess();
                }
            } else {
                // This step is not a multiple.
                $this->updateText = ($this->updateText != Craft::t('telegram-bridge', 'Next Step', [], $this->language) . ' ➡️') ? $this->updateText : '';
                if ($this->updateText && isset($steps[$step]['validation'])) {
                    list($validate, $error) = call_user_func($steps[$step]['validation'], $this->updateText, $this->chatId);
                }
                if (!$validate) {
                    $data = $this->actionPrepareData($error, $this->chatId);
                } else {
                    // save the update text if the update text is not equal to Next Step
                    $cache->set($step . '_' . $this->chatId, $this->updateText, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                    $data = $this->stepProcess();
                }
            }
            // We are in last step, generate output
            if (!$data) {
                $cache->delete('criteria_step_' . $this->chatId);
                $cache->delete('previous_criteria_step_' . $this->chatId);

                $currentMenu = $cache->get('current_menu_' . $this->chatId);
                if ($currentMenu == 'queries') {
                    $cache->set('gql_result_' . $this->chatId, true, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                    $data = $this->createResponse('queries', 'inline_keyboard');
                    if ($cache->get('query_type_' . $this->chatId)) {
                        $data = $this->actionGqlQueryRender(json_decode($data['reply_markup'], true));
                        $cache->set('next_message_type_' . $this->chatId, 'query_type', 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                    }
                } elseif ($currentMenu == 'tools') {
                    $cache->set('tool_result_' . $this->chatId, true, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                    $data = null;
                    $data = $this->createResponse('tools', 'inline_keyboard');
                    if ($cache->get('tool_' . $this->chatId)) {
                        $replyMarkup = null;
                        if (isset($data['reply_markup'])) {
                            $replyMarkup = json_decode($data['reply_markup'], true);
                        }
                        $data = $this->toolRender($replyMarkup);
                        $cache->set('next_message_type_' . $this->chatId, 'tool', 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                    }
                }
            }
        } elseif ($cache->get('next_message_type_' . $this->chatId) == 'tool') {
            if ($this->updateText != Craft::t('telegram-bridge', 'Change Criteria', [], $this->language) . ' 🔎' && $this->updateText != Craft::t('telegram-bridge', 'Next Results', [], $this->language) . ' ⏭️' && $this->updateText != '⏮️ ' . Craft::t('telegram-bridge', 'Previous Results', [], $this->language)) {
                // Delete previous tool steps, if there is any
                $this->deleteSteps();

                $data = null;
                $toolCategory = $cache->get('tool_category_' . $this->chatId);
                if (in_array($this->updateText, array_keys($toolCategory::tools($this->language)))) {
                    // User selected a tool, process the tool criteria
                    $cache->set('tool_' . $this->chatId, $this->updateText, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                    $data = $this->stepProcess();
                }
            } elseif ($this->updateText == Craft::t('telegram-bridge', 'Change Criteria', [], $this->language) . ' 🔎') {
                // get tool criteria steps, delete the related cache
                $steps = $this->toolCriteriaSteps();
                foreach ($steps as $stepKey => $step) {
                    $cache->delete($stepKey . '_' . $this->chatId);
                }
                $cache->delete('previous_criteria_step_' . $this->chatId);
                $cache->delete('criteria_step_' . $this->chatId);
                $data = $this->stepProcess();
            } else {
                // Next results or previous results are requested
                $data = $this->stepProcess();
            }
            if (!$data) {
                //$data = $this->createResponse('tools', $this->defaultChatKeyboard);
                if ($cache->get('tool_' . $this->chatId)) {
                    $replyMarkup = null;
                    //if (isset($data['reply_markup'])) {
                    //$replyMarkup = json_decode($data['reply_markup'], true);
                    //}
                    $data = $this->toolRender($replyMarkup);
                    $cache->set('next_message_type_' . $this->chatId, 'tool', 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                }
            }
        } elseif ($cache->get('next_message_type_' . $this->chatId) == 'query_type') {
            if ($this->updateText != Craft::t('telegram-bridge', 'Change Criteria', [], $this->language) . ' 🔎' && $this->updateText != Craft::t('telegram-bridge', 'Next Results', [], $this->language) . ' ⏭️' && $this->updateText != '⏮️ ' . Craft::t('telegram-bridge', 'Previous Results', [], $this->language)) {
                $validEntry = false;
                if (is_numeric($this->updateText)) {
                    $entry = Entry::find()->id($this->updateText)->one();
                    if ($entry) {
                        $sectionId = $entry->sectionId;
                        $sectionHandle = App::parseEnv('$GRAPHQL_QUERY_SECTIONS');
                        $sectionHandles = explode('||', $sectionHandle);
                        $token = Craft::$app->gql->getTokenByAccessToken($this->gqlAccessToken);
                        $pairs = GqlHelper::extractAllowedEntitiesFromSchema('read', $token->getSchema());
                        if (isset($pairs['sections'])) {
                            $section = craft::$app->sections->getSectionById($sectionId);
                            if ($section->type == 'structure') {
                                if (in_array($section->uid, $pairs['sections']) && in_array($section->handle, $sectionHandles)) {
                                    // Check if it is menu
                                    $gqlField = App::parseEnv('$GRAPHQL_QUERY_FIELD');
                                    if (!$gqlField || $gqlField == '$GRAPHQL_QUERY_FIELD') {
                                        throw new ServerErrorHttpException('Graph QL query field is not specified');
                                    }
                                    $gqlQuery = $entry->{$gqlField};
                                    if (!$gqlQuery) {
                                        $cache->set('descendantOf_' . $this->chatId, $this->updateText, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                                        $queries = $this->gqlQueries();
                                        if ($queries) {
                                            $data = $this->createResponse('queries', 'inline_keyboard');
                                        } else {
                                            $data = $this->actionPrepareData('Selected item has no query or children', $this->chatId);
                                        }
                                    } else {
                                        // Delete previous query steps, if there is any
                                        $this->deleteSteps();
                                        // User selected a query, process the query criteria
                                        $cache->set('query_type_' . $this->chatId, $this->updateText, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                                        $data = $this->stepProcess();
                                    }
                                }
                            }
                        }
                    }
                }
            } elseif ($this->updateText == Craft::t('telegram-bridge', 'Change Criteria', [], $this->language) . ' 🔎') {
                $validEntry = true;
                $currentMenu = $cache->get('current_menu_' . $this->chatId);
                if ($currentMenu == 'queries') {
                    $steps = $this->queryCriteriaSteps();
                } elseif ($currentMenu == 'tools') {
                    $steps = $this->toolCriteriaSteps();
                } else {
                    throw new ServerErrorHttpException('UNKNOWN TYPE');
                }
                //TODO: give user a notice if there is no step for changing criteria
                foreach ($steps as $stepKey => $step) {
                    $cache->delete($stepKey . '_' . $this->chatId);
                }
                $cache->delete('previous_criteria_step_' . $this->chatId);
                $cache->delete('criteria_step_' . $this->chatId);
                $data = $this->stepProcess();
            } else {
                $validEntry = true;
                $data = $this->stepProcess();
            }
            if ($validEntry && (!isset($data) || !$data)) {
                $cache->set('gql_result_' . $this->chatId, true, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                $data = $this->createResponse('queries', 'inline_keyboard');
                if ($cache->get('query_type_' . $this->chatId)) {
                    $data = $this->actionGqlQueryRender(json_decode($data['reply_markup'], true));
                    $cache->set('next_message_type_' . $this->chatId, 'query_type', 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                }
            }
        }

        if (!isset($data)) {
            $data = $this->actionPrepareData(Craft::t('telegram-bridge', 'Invalid data received.'), $this->chatId);
            craft::warning('Invalid data received: ' . $this->updateText);
        }

        if (isset($this->callbackQueryId)) {
            $callbackQueryData = $data;
            unset($callbackQueryData['chat_id']);
            unset($callbackQueryData['photo']);
            unset($callbackQueryData['text']);
            $callbackQueryData['callback_query_id'] = $this->callbackQueryId;
            Curl::getWithCurl($urlCallback, $callbackQueryData, false);
        }

        if (isset($data['text'])) {
            $textData = $data;
            unset($textData['photo']);
            Curl::getWithCurl($urlMessage, $textData, false);
        }

        $showChart = App::parseEnv('$SHOW_RESULT_CHART');
        if ($showChart == 'true' && isset($data['photo'])) {
            $photoData = $data;
            unset($photoData['text']);
            // don't send markup again if it is sent already by text data
            if (isset($data['text'])) {
                unset($photoData['reply_markup']);
            }
            if (isset($data['callback_query_id'])) {
                unset($photoData['callback_query_id']);
            }
            Curl::getWithCurl($urlPhoto, $photoData, false);
            $photo = $cache->get('chart_photo_' . $this->chatId);
            @unlink($photo);
        }
    }

    /**
     * Create Response data
     *
     * @param string $step
     * @param string $keyboard
     * @return array|null
     */
    protected function createResponse(string $step, string $keyboard = 'reply_keyboard'): ?array
    {
        $cache = Craft::$app->getCache();
        $steps = null;
        $replyMarkup = false;

        $currentMenu = $cache->get('current_menu_' . $this->chatId);
        if ($currentMenu == 'queries') {
            $type = $cache->get('query_type_' . $this->chatId);
            $steps = $cache->get('steps_' . $type);
        } elseif ($currentMenu == 'tools') {
            $type = $cache->get('tool_' . $this->chatId);
            $steps = $cache->get('steps_' . $type);
        }
        $items = $this->actionCreateKeyboardItems($step);
        $nullable = $steps[$step]['isNullable'] ?? true;
        if ($step == 'limit') {
            $nullable = true;
        }
        $previous = false;
        // Show Previous Step if the current step is not first one
        if (is_array($steps) && $this->stepCounter != 1 && $cache->get('previous_criteria_step_' . $this->chatId)) {
            $item = [];
            $item['text'] = '⬅️ ' . Craft::t('telegram-bridge', 'Previous Step', [], $this->language);
            $item['callback_data'] = '⬅️ ' . Craft::t('telegram-bridge', 'Previous Step', [], $this->language);
            $item['item_per_row'] = 2;
            $item['new_row_before'] = true;
            array_push($items, $item);
            $previous = true;
        }
        // Show Next Step if the current step can be not set or it can be multi
        if ($cache->get('criteria_step_' . $this->chatId) && ($nullable || (is_array($steps) && isset($steps[$step]['multiple']) && $steps[$step]['multiple']))) {
            $item = [];
            $item['text'] = Craft::t('telegram-bridge', 'Next Step', [], $this->language) . ' ➡️';
            $item['callback_data'] = Craft::t('telegram-bridge', 'Next Step', [], $this->language) . ' ➡️';
            $item['item_per_row'] = 2;
            $item['new_row_after'] = true;
            if (!$previous) {
                $item['new_row_before'] = true;
            }
            array_push($items, $item);
        }
        if (isset($items)) {
            $replyMarkup = $this->actionCreateKeyboard($keyboard, $items, true, false);
            if ($step == 'home' && $items == []) {
                $data = null;
            } else {
                $messageText = $this->actionCreateMessageText($step);
                $data = $this->actionPrepareData($messageText, $this->chatId, $replyMarkup, null, $keyboard);
            }
        } else {
            $data = $this->actionPrepareData('No keyboard items for ' . $step, $this->chatId);
        }
        return $data;
    }

    /**
     * Create response data for a step
     *
     * @return array|null
     */
    protected function stepProcess(): ?array
    {
        $cache = Craft::$app->getCache();
        $data = null;
        $currentMenu = $cache->get('current_menu_' . $this->chatId);
        if ($currentMenu == 'queries') {
            $steps = $this->queryCriteriaSteps();
        } elseif ($currentMenu == 'tools') {
            $steps = $this->toolCriteriaSteps();
        } else {
            throw new ServerErrorHttpException('Unknown Type');
        }
        $this->stepCounter = 0;
        foreach ($steps as $stepKey => $step) {
            $this->stepCounter++;
            $skip = false;
            if ($stepKey != 'offset' && $cache->get($stepKey . '_' . $this->chatId) === false) {
                // Check only if this step is depended to other items
                if (isset($steps[$stepKey]['showIf'])) {
                    foreach ($steps[$stepKey]['showIf'] as $showIfKey => $showIf) {
                        if (!in_array(strtolower($cache->get($showIfKey . '_' . $this->chatId)), $showIf)) {
                            $skip = true;
                            break;
                        }
                    }
                }
                if ($skip) {
                    continue;
                }
                $criteriaStep = $cache->get('criteria_step_' . $this->chatId);
                if ($criteriaStep) {
                    $cache->set('previous_criteria_step_' . $this->chatId, $criteriaStep, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                }
                $cache->set('next_message_type_' . $this->chatId, 'criteria', 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                $cache->set('criteria_step_' . $this->chatId, $stepKey, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                $data = $this->createResponse($stepKey, 'inline_keyboard');
                break;
            }
        }
        return $data;
    }

    /**
     * Create keyboard reply markup
     *
     * @param string $type
     * @param array $items
     * @param boolean $resize_keyboard
     * @param boolean $one_time_keyboard
     * @return array
     */
    protected function actionCreateKeyboard(string $type, array $items, bool $resize_keyboard, bool $one_time_keyboard): array
    {
        // reply_keyboard type is keyboard
        if ($type == 'reply_keyboard') {
            $type = 'keyboard';
        }
        $replyMarkup = [];
        $replyMarkup[$type] = [];
        $item_counter = 0;
        $row_item_counter = 0;
        $keyboard_row = [];
        foreach ($items as $item) {
            if (isset($item['new_row_before']) && $item['new_row_before']) {
                if (count($keyboard_row) > 0) {
                    array_push($replyMarkup[$type], $keyboard_row);
                }
                $keyboard_row = [];
                $row_item_counter = 0;
            }
            $item_counter++;
            $row_item_counter++;
            $keyboard_row_item = [];
            $keyboard_row_item['text'] = $item['text'];
            if ($type == 'inline_keyboard') {
                $keyboard_row_item['callback_data'] = $item['callback_data'];
            }
            array_push($keyboard_row, $keyboard_row_item);
            if (isset($item['item_per_row'])) {
                $item_per_row = $item['item_per_row'];
            } else {
                $item_per_row = 2;
            }
            if ((isset($item['new_row_after']) && $item['new_row_after']) || $row_item_counter % $item_per_row == 0 || count($items) == $item_counter) {
                // if this item is not already pushed
                array_push($replyMarkup[$type], $keyboard_row);
            }
            if ((isset($item['new_row_after']) && $item['new_row_after']) || $row_item_counter % $item_per_row == 0) {
                $keyboard_row = [];
                // Reset Counter for new row
                $row_item_counter = 0;
            }
        }
        if ($type == 'keyboard') {
            $replyMarkup['resize_keyboard'] = $resize_keyboard;
            $replyMarkup['one_time_keyboard'] = $one_time_keyboard;
        }
        return $replyMarkup;
    }

    /**
     * Return keyboard items for a step
     *
     * @param string $step
     * @return array|null
     */
    protected function actionCreateKeyboardItems(string $step): ?array
    {
        $items = null;
        $cache = Craft::$app->getCache();
        $queryType = $cache->get('query_type_' . $this->chatId);
        $steps = $cache->get('steps_' . $queryType);
        if ($step == 'home') {
            $items = [];
            if (isset($this->chatIdUsers[$this->chatId]) && General::canAccessTools($this->chatIdUsers[$this->chatId])) {
                $item = [];
                $item['text'] = Craft::t('telegram-bridge', 'Tools', [], $this->language) . ' 📋';
                $item['callback_data'] = Craft::t('telegram-bridge', 'Tools', [], $this->language) . ' 📋';
                $item['item_per_row'] = 2;
                array_push($items, $item);
            }
            if ((Craft::$app->getEdition() === Craft::Pro) && (App::parseEnv('$GRAPHQL_API') && (App::parseEnv('$GRAPHQL_API') != '$GRAPHQL_API')) && $this->gqlAccessToken) {
                $item = [];
                $item['text'] = Craft::t('telegram-bridge', 'Queries', [], $this->language) . '❓';
                $item['callback_data'] = Craft::t('telegram-bridge', 'Queries', [], $this->language) . '❓';
                $item['item_per_row'] = 2;
                array_push($items, $item);
            }
        } elseif ($step == 'tool category') {
            $items = [];
            $item_per_row = 2;
            $toolTypes = TelegramBridge::$plugin->tool->getAllToolTypes();
            foreach ($toolTypes as $key => $toolType) {
                if (isset($this->chatIdUsers[$this->chatId]) && General::canAccessTool($this->chatIdUsers[$this->chatId], $toolType)) {
                    $item = [];
                    $item['text'] = $toolType::displayName($this->language);
                    $item['callback_data'] = $toolType;
                    $item['item_per_row'] = $item_per_row;
                    array_push($items, $item);
                }
            }
        } elseif ($step == 'tools') {
            $toolCategory = $cache->get('tool_category_' . $this->chatId);
            $tools = [];

            if (method_exists($toolCategory, 'tools')) {
                $tools = $toolCategory::tools($this->language);
                $items = [];
                $item_per_row = 2;
                if (!$cache->get('tool_result_' . $this->chatId)) {
                    $toolKeyIndex = 0;
                    foreach ($tools as $toolKey => $tool) {
                        if ($toolKeyIndex == 0) {
                            $item['new_row_before'] = true;
                        }
                        $item = [];
                        $item['text'] = $tool;
                        $item['callback_data'] = $toolKey;
                        if (strlen($tool) > 20) {
                            $item['new_row_before'] = true;
                            $item['new_row_after'] = true;
                        }
                        $item['item_per_row'] = $item_per_row;
                        array_push($items, $item);
                        $toolKeyIndex++;
                    }
                }
                if ($cache->get('tool_' . $this->chatId)) {
                    $item = [];
                    $item['text'] = Craft::t('telegram-bridge', 'Change Criteria', [], $this->language) . ' 🔎';
                    $item['callback_data'] = Craft::t('telegram-bridge', 'Change Criteria', [], $this->language) . ' 🔎';
                    $item['new_row_before'] = true;
                    array_push($items, $item);
                }
            }
        } elseif ($step == 'queries') {
            if (Craft::$app->getEdition() !== Craft::Pro) {
                craft::warning('there is a request for queries but Craft version is solo');
                return null;
            }
            if (App::parseEnv('$GRAPHQL_API') && (App::parseEnv('$GRAPHQL_API') == '$GRAPHQL_API')) {
                craft::warning('there is a request for queries but $GRAPHQL_API is not set');
                return null;
            }
            if (!$this->gqlAccessToken) {
                craft::warning('there is a request for queries but GQL access token is not set');
                return null;
            }
            $items = [];
            if ($cache->get('query_type_' . $this->chatId) && $this->updateText != (Craft::t('telegram-bridge', 'Queries', [], $this->language) . '❓')) {
                if (in_array('offset', array_keys($steps))) {
                    $offset = (int)$cache->get('offset_' . $this->chatId);
                    $limit = (int)$cache->get('limit_' . $this->chatId);
                    if ($this->updateText == Craft::t('telegram-bridge', 'Next Results', [], $this->language) . ' ⏭️') {
                        $offset = $offset + $limit;
                    }
                    if ($this->updateText == '⏮️ ' . Craft::t('telegram-bridge', 'Previous Results', [], $this->language)) {
                        $offset = $offset - $limit;
                    }
                    if ($offset) {
                        $item = [];
                        $item['text'] = '⏮️ ' . Craft::t('telegram-bridge', 'Previous Results', [], $this->language);
                        $item['callback_data'] = '⏮️ ' . Craft::t('telegram-bridge', 'Previous Results', [], $this->language);
                        $item['item_per_row'] = 2;
                        array_push($items, $item);
                    }
                    if ($cache->get('gql_result_' . $this->chatId)) {
                        $item = [];
                        $item['text'] = Craft::t('telegram-bridge', 'Next Results', [], $this->language) . ' ⏭️';
                        $item['callback_data'] = Craft::t('telegram-bridge', 'Next Results', [], $this->language) . ' ⏭️';
                        $item['item_per_row'] = 2;
                        $item['new_row_after'] = true;
                        array_push($items, $item);
                    }
                }

                $item = [];
                $item['text'] = Craft::t('telegram-bridge', 'Change Criteria', [], $this->language) . ' 🔎';
                $item['callback_data'] = Craft::t('telegram-bridge', 'Change Criteria', [], $this->language) . ' 🔎';
                $item['item_per_row'] = 1;
                array_push($items, $item);
            }
            if ($this->updateText == (Craft::t('telegram-bridge', 'Queries', [], $this->language) . '❓') || $cache->get('descendantOf_' . $this->chatId)) {
                $queryItems = $this->gqlQueries();
                $items = array_merge($items, $queryItems);
            }
        } elseif ($cache->get('current_menu_' . $this->chatId) == 'queries' && ($step == 'section' || $step == 'sectionId')) {
            $sections = Craft::$app->sections->getAllSections();
            $token = Craft::$app->gql->getTokenByAccessToken($this->gqlAccessToken);
            $pairs = GqlHelper::extractAllowedEntitiesFromSchema('read', $token->getSchema());
            $items = [];
            if (isset($pairs['sections']) && $pairs['sections']) {
                // only show sections that user can read
                foreach ($sections as $section) {
                    if (in_array($section->uid, $pairs['sections'])) {
                        $item = [];
                        $item['text'] = $section->name;
                        $item['callback_data'] = ($step == 'section') ? $section->handle : $section->id;
                        array_push($items, $item);
                    }
                }
                // allow to select not
                $item = [];
                $item['text'] = 'not';
                $item['callback_data'] = 'not';
                $item['new_row_before'] = true;
                array_push($items, $item);
            }
        } elseif ($cache->get('current_menu_' . $this->chatId) == 'queries' && ($step == 'type' || $step == 'typeId')) {
            $token = Craft::$app->gql->getTokenByAccessToken($this->gqlAccessToken);
            $pairs = GqlHelper::extractAllowedEntitiesFromSchema('read', $token->getSchema());
            // filter allowed section ids by schema
            $allowedSectionIds = [];
            if (isset($pairs['sections']) && $pairs['sections']) {
                foreach ($pairs['sections'] as $sec) {
                    $sect = Craft::$app->sections->getSectionByUid($sec);
                    $allowedSectionIds[] = $sect->id;
                }
            }
            $selectedSectionHandles = $cache->get('section_' . $this->chatId);
            $selectedSectionIds = $cache->get('sectionId_' . $this->chatId);

            if ($selectedSectionHandles) {
                $sectionIds = [];
                foreach ($selectedSectionHandles as $sec) {
                    if ($sec != 'not') {
                        $sectionByHandle = Craft::$app->sections->getSectionByHandle($sec);
                        $sectionIds[] = $sectionByHandle->id;
                    }
                }
                // filter allowed sections based on allowed section ids and if user selected not
                if ($selectedSectionHandles[0] == 'not') {
                    $allowedSectionIds = array_diff($allowedSectionIds, $sectionIds);
                } else {
                    $allowedSectionIds = array_intersect($allowedSectionIds, $sectionIds);
                }
            } elseif ($selectedSectionIds) {
                if ($selectedSectionIds[0] == 'not') {
                    $allowedSectionIds = array_diff($allowedSectionIds, $selectedSectionIds);
                } else {
                    $allowedSectionIds = array_intersect($allowedSectionIds, $selectedSectionIds);
                }
            }
            $entryTypes = [];
            if ($allowedSectionIds) {
                foreach ($allowedSectionIds as $allowedSectionId) {
                    $entryTypes = Craft::$app->sections->getEntryTypesBySectionId($allowedSectionId);
                }
            }
            $token = Craft::$app->gql->getTokenByAccessToken($this->gqlAccessToken);
            // TODO: Craft 5 does not have entry types in schema
            $pairs = GqlHelper::extractAllowedEntitiesFromSchema('read', $token->getSchema());
            $items = [];
            if (isset($pairs['entrytypes']) && $pairs['entrytypes']) {
                foreach ($entryTypes as $entryType) {
                    if (in_array($entryType->uid, $pairs['entrytypes'])) {
                        $item = [];
                        $item['text'] = $entryType->getSection()->name . ' - ' . $entryType->name;
                        $item['callback_data'] = ($step == 'type') ? $entryType->handle : $entryType->id;
                        array_push($items, $item);
                    }
                }
                $item = [];
                $item['text'] = 'not';
                $item['callback_data'] = 'not';
                $item['new_row_before'] = true;
                array_push($items, $item);
            }
        } elseif ($cache->get('current_menu_' . $this->chatId) == 'queries' && ($step == 'site' || $step == 'siteId')) {
            $sites = Craft::$app->sites->getAllSites();
            $token = Craft::$app->gql->getTokenByAccessToken($this->gqlAccessToken);
            $pairs = GqlHelper::extractAllowedEntitiesFromSchema('read', $token->getSchema());
            $items = [];
            if (isset($pairs['sites'])) {
                foreach ($sites as $site) {
                    if (in_array($site->uid, $pairs['sites'])) {
                        $item = [];
                        $item['text'] = $site->name;
                        $item['callback_data'] = ($step == 'site') ? $site->name : $site->id;
                        array_push($items, $item);
                    }
                }
            }
            $item = [];
            $item['text'] = 'not';
            $item['callback_data'] = 'not';
            $item['new_row_before'] = true;
            array_push($items, $item);
            $item = [];
            $item['text'] = craft::t('app', 'All', [], $this->language);
            $item['callback_data'] = '*';
            $item['new_row_before'] = true;
            array_push($items, $item);
        } elseif ($cache->get('current_menu_' . $this->chatId) == 'queries' && ($step == 'volumeId' || $step == 'volume')) {
            $volumes = Craft::$app->volumes->getAllVolumes();
            $token = Craft::$app->gql->getTokenByAccessToken($this->gqlAccessToken);
            $pairs = GqlHelper::extractAllowedEntitiesFromSchema('read', $token->getSchema());
            $items = [];
            if (isset($pairs['volumes'])) {
                foreach ($volumes as $volume) {
                    if (in_array($volume->uid, $pairs['volumes'])) {
                        echo $volume->name;
                        $item = [];
                        $item['text'] = $volume->name;
                        $item['callback_data'] = ($step == 'volumeId') ? $volume->id : $volume->handle;
                        array_push($items, $item);
                    }
                }
                $item = [];
                $item['text'] = 'not';
                $item['callback_data'] = 'not';
                $item['new_row_before'] = true;
                array_push($items, $item);
            }
        } elseif ($cache->get('current_menu_' . $this->chatId) == 'queries' && ($step == 'folderId')) {
            $token = Craft::$app->gql->getTokenByAccessToken($this->gqlAccessToken);
            $pairs = GqlHelper::extractAllowedEntitiesFromSchema('read', $token->getSchema());
            // filter allowed volume ids by schema
            $allowedVolumeIds = [];
            if (isset($pairs['volumes']) && $pairs['volumes']) {
                foreach ($pairs['volumes'] as $vol) {
                    $volu = Craft::$app->volumes->getVolumeByUid($vol);
                    $allowedVolumeIds[] = $volu->id;
                }
            }
            $selectedVolumeHandles = $cache->get('volume_' . $this->chatId);
            $selectedVolumeIds = $cache->get('volumeId_' . $this->chatId);

            if ($selectedVolumeHandles) {
                $volumeIds = [];
                foreach ($selectedVolumeHandles as $vol) {
                    if ($vol != 'not') {
                        $volumeByHandle = Craft::$app->volumes->getVolumeByHandle($vol);
                        $volumeIds[] = $volumeByHandle->id;
                    }
                }
                // filter allowed volumes based on allowed volume ids and if user selected not
                if ($selectedVolumeHandles[0] == 'not') {
                    $allowedVolumeIds = array_diff($allowedVolumeIds, $volumeIds);
                } else {
                    $allowedVolumeIds = array_intersect($allowedVolumeIds, $volumeIds);
                }
            } elseif ($selectedVolumeIds) {
                if ($selectedVolumeIds[0] == 'not') {
                    $allowedVolumeIds = array_diff($allowedVolumeIds, $selectedVolumeIds);
                } else {
                    $allowedVolumeIds = array_intersect($allowedVolumeIds, $selectedVolumeIds);
                }
            }

            $items = [];
            if ($allowedVolumeIds) {
                foreach ($allowedVolumeIds as $allowedVolumeId) {
                    $vol = Craft::$app->volumes->getVolumeById($allowedVolumeId);
                    $folders = (new Query())->select(['id', 'path', 'name'])->from([Table::VOLUMEFOLDERS])->where(['volumeId' => $allowedVolumeId])->all();
                    foreach ($folders as $folder) {
                        $item = [];
                        $item['text'] = $vol->name . ' - ' . $folder['name'];
                        $item['callback_data'] = $folder['id'];
                        array_push($items, $item);
                    }
                }
            }
            $item = [];
            $item['text'] = 'not';
            $item['callback_data'] = 'not';
            $item['new_row_before'] = true;
            array_push($items, $item);
        } elseif ($step == 'limit') {
            $limits = ['1', '2', '5', '10', '15', '20'];
            $items = [];
            foreach ($limits as $limit) {
                $item = [];
                $item['text'] = $limit;
                $item['callback_data'] = $limit;
                array_push($items, $item);
            }
        } elseif ($cache->get('tool_category_' . $this->chatId)) {
            $toolCategory = $cache->get('tool_category_' . $this->chatId);
            if (method_exists($toolCategory, 'keyboardItems')) {
                /** @var ToolTypeInterface $toolCategory */
                $items = $toolCategory::keyboardItems($step, $this->chatId);
            }
        } elseif (isset($steps[$step]['type'])) {
            $type = $steps[$step]['type'];
            if ($type == 'Boolean') {
                $buttons = ['true', 'false'];
                $items = [];
                foreach ($buttons as $button) {
                    $item = [];
                    $item['text'] = $button;
                    $item['callback_data'] = $button;
                    array_push($items, $item);
                }
                if (isset($steps[$step]['isNullable']) && $steps[$step]['isNullable']) {
                    $item = [];
                    $item['text'] = 'null';
                    $item['callback_data'] = 'null';
                    array_push($items, $item);
                }
            } else {
                $items = [];
            }
        } else {
            $items = [];
        }
        return $items;
    }

    /**
     * Return steps of selected tool
     *
     * @return array
     */
    protected function toolCriteriaSteps(): array
    {
        $cache = Craft::$app->getCache();
        $toolCategory = $cache->get('tool_category_' . $this->chatId);
        $tool = $cache->get('tool_' . $this->chatId);

        if (method_exists($toolCategory, 'criteria')) {
            /** @var ToolTypeInterface $toolCategory */
            $steps = $toolCategory::criteria($tool, $this->chatId);
        } else {
            $steps = [];
        }

        $cache->set('steps_' . $tool, $steps, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
        return $steps;
    }

    /**
     * Return steps of selected gql query
     *
     * @return array
     */
    protected function queryCriteriaSteps(): array
    {
        $cache = Craft::$app->getCache();
        $id = $cache->get('query_type_' . $this->chatId);
        $entry = Entry::find()->id($id)->one();
        // Check if it is menu
        $gqlField = App::parseEnv('$GRAPHQL_QUERY_FIELD');
        if (!$gqlField) {
            throw new ServerErrorHttpException('Graph QL query field is not specified');
        }
        $gqlQuery = $entry->{$gqlField};
        // Parse the GraphQL query
        try {
            $parsedQuery = Parser::parse($gqlQuery);
        } catch (\Throwable $th) {
            $data = $this->actionPrepareData('A server error occurred', $this->chatId);
            $url = TelegramBridge::BOT_URL . App::parseEnv('$TELEGRAM_BOT_TOKEN') . "/sendMessage";
            Curl::getWithCurl($url, $data, false);
            throw new ServerErrorHttpException($th->getMessage());
        }

        // Access query variables
        $queryVariables = [];
        if (isset($parsedQuery->definitions[0]->variableDefinitions)) {
            $queryVariables = $parsedQuery->definitions[0]->variableDefinitions;
        }

        $steps = [];
        // You can now access and use the query variables as needed
        foreach ($queryVariables as $variableDefinition) {
            $variableName = $variableDefinition->variable->name->value;
            $innerVariableType = $variableDefinition->type;
            list($variableTypeName, $isArray, $isNullable) = Gql::getTypeName($innerVariableType);
            $steps[$variableName] = [
                'multiple' => $isArray,
                'isNullable' => $isNullable,
                'type' => is_array($variableTypeName) ? $variableTypeName[0] : $variableTypeName,
            ];
        }
        $cache->set('steps_' . $id, $steps, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
        return $steps;
    }

    /**
     * Return result of tools render
     *
     * @param array|null $replyMarkup
     * @return array
     */
    protected function toolRender(?array $replyMarkup = null): array
    {
        $cache = Craft::$app->getCache();

        $tool = $cache->get('tool_' . $this->chatId);
        $toolCategory = $cache->get('tool_category_' . $this->chatId);

        if (method_exists($toolCategory, 'render')) {
            /** @var ToolTypeInterface $toolCategory */
            list($messageText, $img) = $toolCategory::render($tool, $this->chatId);
        } else {
            throw new ServerErrorHttpException('tool type is not defined' . $toolCategory);
        }

        $data = $this->actionPrepareData($messageText, $this->chatId, $replyMarkup, $img, 'inline_keyboard');
        return $data;
    }

    /**
     * Return result of execute of graphql query
     *
     * @param mixed $replyMarkup
     * @return array
     */
    protected function actionGqlQueryRender(mixed $replyMarkup): array
    {
        $cache = Craft::$app->getCache();
        $cache->delete('criteria_step_' . $this->chatId);
        $queryType = $cache->get('query_type_' . $this->chatId);
        $steps = $cache->get('steps_' . $queryType);
        $entry = Entry::find()->id($queryType)->one();
        $gqlField = App::parseEnv('$GRAPHQL_QUERY_FIELD');
        if (!$gqlField) {
            throw new ServerErrorHttpException('Graph QL query field is not specified');
        }
        $gqlQuery = $entry->{$gqlField};

        if (in_array('offset', array_keys($steps))) {
            $offset = (int)$cache->get('offset_' . $this->chatId);
            $limit = (int)$cache->get('limit_' . $this->chatId);

            if ($this->updateText == Craft::t('telegram-bridge', 'Next Results', [], $this->language) . ' ⏭️') {
                $offset = $offset + $limit;
            }
            if ($this->updateText == '⏮️ ' . Craft::t('telegram-bridge', 'Previous Results', [], $this->language)) {
                $offset = $offset - $limit;
            }

            $variables['offset'] = $offset;
            $cache->set('offset_' . $this->chatId, $offset, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
        }

        // Create query variables
        if (is_array($steps)) {
            foreach ($steps as $stepKey => $step) {
                $type = $steps[$stepKey]['type'];
                if ($type == "Int") {
                    $intValue = (int)$cache->get($stepKey . '_' . $this->chatId);
                    // limit 0 returns nothing - it happens when next step used-, use 10 instead to return 10
                    if ($stepKey == 'limit' && $intValue == 0) {
                        $intValue = 10;
                        $cache->set('limit_' . $this->chatId, 10, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
                    }
                    $variables[$stepKey] = $intValue;
                } elseif ($type == "Boolean") {
                    switch ($cache->get($stepKey . '_' . $this->chatId)) {
                        case 'true':
                            $bool = true;
                            $variables[$stepKey] = $bool;
                            break;
                        case 'false':
                            $bool = false;
                            $variables[$stepKey] = $bool;
                            break;
                        case 'null':
                            $bool = null;
                            $variables[$stepKey] = $bool;
                            break;
                        default:
                            break;
                    }
                } elseif ($type == "FileInput") {
                    $variables[$stepKey] = ['fileData' => $cache->get($stepKey . '_' . $this->chatId)];
                } else {
                    // If it is array and it is [] ignore it
                    if (!is_array($cache->get($stepKey . '_' . $this->chatId)) || $cache->get($stepKey . '_' . $this->chatId) != []) {
                        $variables[$stepKey] = $cache->get($stepKey . '_' . $this->chatId);
                    }
                }
            }
        }

        $endpoint = App::parseEnv('$GRAPHQL_API'); // Replace with your GraphQL endpoint
        $ch = curl_init($endpoint);
        $data = [
            'query' => $gqlQuery,
        ];
        if (isset($variables)) {
            $data['variables'] = $variables;
        }
        $data = json_encode($data, JSON_THROW_ON_ERROR);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->gqlAccessToken,
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        $result = json_decode($response, true);
        if (isset($result['errors'])) {
            $messageText = ($result['errors'][0]['debugMessage'] ?? ' ') . ' ' . ($result['errors'][0]['message'] ?? ' ');
        } else {
            $showQuery = App::parseEnv('$SHOW_GRAPHQL_QUERY');
            $queryData = '';
            if ($showQuery == 'true') {
                $queryData = '<code>' . $data . '</code>' . PHP_EOL . PHP_EOL;
            }
            // Decode and encode the data with JSON_PRETTY_PRINT option for formatting
            $prettyGglResult = json_encode($result, JSON_PRETTY_PRINT);
            $messageText = $queryData . '<pre>' . $prettyGglResult . '</pre>';
            if (mb_strlen($messageText) > 4096) {
                $messageText = Craft::t('telegram-bridge', 'Response text is bigger than 4096.');
                if (mb_strlen($prettyGglResult) < 4096) {
                    $messageText = $messageText . '<pre>' . $prettyGglResult . '</pre>';
                }
            }
        }
        curl_close($ch);

        $data = $this->actionPrepareData($messageText, $this->chatId, $replyMarkup);
        return $data;
    }

    /**
     * Get queries based on the selected section and level
     *
     * @return array
     */
    protected function gqlQueries(): array
    {
        $items = [];
        $cache = Craft::$app->getCache();
        $sectionHandle = App::parseEnv('$GRAPHQL_QUERY_SECTIONS');
        if ($sectionHandle && $sectionHandle != '$GRAPHQL_QUERY_SECTIONS') {
            $sectionHandles = explode('||', $sectionHandle);
            $token = Craft::$app->gql->getTokenByAccessToken($this->gqlAccessToken);
            $pairs = GqlHelper::extractAllowedEntitiesFromSchema('read', $token->getSchema());
            if (isset($pairs['sections'])) {
                foreach ($sectionHandles as $sectionHandle) {
                    $section = craft::$app->sections->getSectionByHandle($sectionHandle);
                    if ($section->type == 'structure') {
                        if (in_array($section->uid, $pairs['sections'])) {
                            $sections[] = $sectionHandle;
                        }
                    }
                }
            }
            if (isset($sections)) {
                // Currently only we show queries in primary site
                $query = Entry::find()->section($sections);
                $descendantOf = $cache->get('descendantOf_' . $this->chatId);
                if ($descendantOf) {
                    $query->descendantOf($descendantOf);
                    $query->descendantDist(1);
                } else {
                    $query->level(1);
                }
                $query->orderBy('entries.sectionId asc, content.title asc');
                $entries = $query->all();
                $queryField = App::parseEnv('$GRAPHQL_QUERY_FIELD');
                foreach ($entries as $entry) {
                    // make sure if it has title
                    if ($entry->title) {
                        $item = [];
                        $item['text'] = $entry->title . ((isset($entry->$queryField) && $entry->getFieldValue($queryField)) ? '❓' : ' 📂');
                        $item['callback_data'] = $entry->id;
                        if (strlen($entry->title) > 20 || (!isset($entry->$queryField) || !$entry->getFieldValue($queryField))) {
                            // categories have new lines
                            $item['new_row_before'] = true;
                            $item['new_row_after'] = true;
                        }
                        array_push($items, $item);
                    }
                }
            } else {
                craft::warning('there are no sections with structure type to show GQL queries.');
            }
        }
        return $items;
    }

    /**
     * Create the message text
     *
     * @param string $message_type
     * @return string
     */
    protected function actionCreateMessageText(string $message_type): string
    {
        $cache = Craft::$app->getCache();
        if ($message_type == 'home' || $message_type == 'tools' || $message_type == 'queries') {
            $messageText = Craft::t('telegram-bridge', 'Please select an option.', [], $this->language);
        } else {
            $action = 'Please select';
            $queryType = $cache->get('query_type_' . $this->chatId);
            if ($queryType) {
                $steps = $cache->get('steps_' . $queryType);
                $step = $cache->get('criteria_step_' . $this->chatId);
                if (isset($steps[$step]['type']) && $steps[$step]['type'] == 'FileInput') {
                    $action = 'Please upload';
                }
            }
            $tool = $cache->get('tool_' . $this->chatId);
            if ($tool) {
                $steps = $cache->get('steps_' . $tool);
                $step = $cache->get('criteria_step_' . $this->chatId);
                if (isset($steps[$step]['label'])) {
                    $message_type = $steps[$step]['label'];
                }
            }
            if ($message_type == 'tool category') {
                $message_type = craft::t('telegram-bridge', 'category', [], $this->language);
            }
            $messageText = Craft::t('telegram-bridge', $action . ' the {item}.', ['item' => $message_type], $this->language);
        }
        return $messageText;
    }

    /**
     * Prepare message data
     *
     * @param string|null $message
     * @param string $chatId
     * @param array|null $replyMarkup
     * @param string|null $file
     * @return array
     */
    protected function actionPrepareData(?string $message, string $chatId, ?array $replyMarkup = null, ?string $file = null, $keyboard = 'reply_keyboard'): array
    {
        $cache = Craft::$app->getCache();
        $data = [
            'chat_id' => $chatId,
            'parse_mode' => 'HTML',
        ];
        if ($file) {
            $data['photo'] = new CURLFile($file);
            $cache->set('chart_photo_' . $this->chatId, $file, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $this->chatId]]));
        }
        if ($keyboard == 'inline_keyboard' && isset($this->callbackQueryId)) {
            $data['callback_query_id'] = $this->callbackQueryId;
        }
        if (isset($message)) {
            $data['text'] = $message;
        } else {
            $data['text'] = 'No result';
        }
        if ($replyMarkup) {
            $data['reply_markup'] = json_encode($replyMarkup);
        }
        return $data;
    }

    /**
     * Clear cache depending on the current status
     *
     * @return void
     */
    protected function clearCaches(): void
    {
        $cache = Craft::$app->getCache();
        $cache->delete('criteria_step_' . $this->chatId);
        $cache->delete('previous_criteria_step_' . $this->chatId);

        $currentMenu = $cache->get('current_menu_' . $this->chatId);
        if ($currentMenu == 'queries') {
            $cache->delete('gql_result_' . $this->chatId);
            $cache->delete('descendantOf_' . $this->chatId);
            $queryType = $cache->get('query_type_' . $this->chatId);
            $steps = $cache->get('steps_' . $queryType);
            if ($steps) {
                foreach ($steps as $stepKey => $step) {
                    $cache->delete($stepKey . '_' . $this->chatId);
                }
            }
            $cache->delete('query_type_' . $this->chatId);
        } elseif ($currentMenu == 'tools') {
            $cache->delete('tool_result_' . $this->chatId);
            $cache->delete('tool_category_' . $this->chatId);
            $tool = $cache->get('tool_' . $this->chatId);
            $steps = $cache->get('steps_' . $tool);
            if ($steps) {
                foreach ($steps as $stepKey => $step) {
                    $cache->delete($stepKey . '_' . $this->chatId);
                }
            }
            $cache->delete('tool_' . $this->chatId);
        }
    }

    /**
     * Delete all steps of previous item
     *
     * @return void
     */
    protected function deleteSteps(): void
    {
        $cache = Craft::$app->getCache();
        $steps = null;
        $currentMenu = $cache->get('current_menu_' . $this->chatId);
        if ($currentMenu == 'queries') {
            $queryType = $cache->get('query_type_' . $this->chatId);
            $steps = $cache->get('steps_' . $queryType);
            $cache->delete('query_type_' . $this->chatId);
        } elseif ($currentMenu == 'tools') {
            $tool = $cache->get('tool_' . $this->chatId);
            $steps = $cache->get('steps_' . $tool);
        }
        if ($steps) {
            foreach ($steps as $stepKey => $step) {
                $cache->delete($stepKey . '_' . $this->chatId);
            }
        }
    }
}
