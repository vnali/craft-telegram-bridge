<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\telegrambridge;

use Craft;
use craft\base\Plugin;
use craft\elements\User;
use craft\events\ModelEvent;
use craft\events\RegisterCacheOptionsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\UserPermissions;
use craft\utilities\ClearCaches;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\webhooks\Plugin as Webhooks;
use vnali\telegrambridge\models\Settings;
use vnali\telegrambridge\services\chatIdService;
use vnali\telegrambridge\services\toolService;
use vnali\telegrambridge\twig\CraftVariableBehavior;
use vnali\telegrambridge\webhookfilters\IsGuestFilter;
use vnali\telegrambridge\webhookfilters\UserHasChatIdFilter;
use vnali\telegrambridge\webhookfilters\UserIsAdminFilter;
use yii\base\Event;
use yii\caching\TagDependency;

/**
 * @property-read chatIdService $chatId
 * @property-read toolService $tool
 */
class TelegramBridge extends Plugin
{
    /**
     * @var TelegramBridge
     */
    public static TelegramBridge $plugin;

    public const BOT_URL = 'https://api.telegram.org/bot';

    public const BOT_FILE_URL = 'https://api.telegram.org/file/bot';


    /**
     * @inheritdoc
     */
    public static function config(): array
    {
        return [
            'components' => [
                'tool' => toolService::class,
                'chatId' => chatIdService::class,
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        // Only show CP section if user can access one of get updates or setting pages
        $allowAdminChanges = Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
        $user = Craft::$app->getUser();
        $cpSection = false;
        if (($allowAdminChanges && $user->checkPermission('telegram-bridge-manageSettings')) || $user->checkPermission('telegram-bridge-getUpdates')) {
            $cpSection = true;
        }

        $this->hasCpSection = $cpSection;
        $this->hasCpSettings = true;

        $this->_registerRules();
        $this->_registerEvents();
        $this->_registerPermissions();
        $this->_registerVariables();
    }

    /**
     * Register plugin events
     *
     * @return void
     */
    private function _registerEvents(): void
    {
        Event::on(
            ClearCaches::class,
            ClearCaches::EVENT_REGISTER_TAG_OPTIONS,
            function(RegisterCacheOptionsEvent $event) {
                $event->options = array_merge(
                    $event->options,
                    $this->_customAdminCpTagOptions()
                );
            }
        );

        if (class_exists('craft\webhooks\Plugin') && Craft::$app->plugins->isPluginInstalled('webhooks') && Craft::$app->plugins->isPluginEnabled('webhooks')) {
            Event::on(
                Webhooks::class,
                Webhooks::EVENT_REGISTER_FILTER_TYPES,
                function(RegisterComponentTypesEvent $event) {
                    $event->types[] = IsGuestFilter::class;
                    $event->types[] = UserIsAdminFilter::class;
                    $event->types[] = UserHasChatIdFilter::class;
                }
            );
        }

        Event::on(
            User::class,
            User::EVENT_BEFORE_SAVE,
            function(ModelEvent $event) {
                if (!$event->sender->propagating) {
                    $cache = Craft::$app->getCache();
                    $newUserInfo = $event->sender;
                    // TODO: We should only check for attributes that has impact on the plugin lihe user's language preference
                    $chatId = TelegramBridge::$plugin->chatId->getChatIdByUser($newUserInfo->username);
                    if ($chatId) {
                        $cache->set('user_changed_' . $chatId, true, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $chatId]]));
                    }
                }
            }
        );
    }

    /**
     * Register tag option for cache
     *
     * @return array
     */
    private function _customAdminCpTagOptions(): array
    {
        return [
            [
                'tag' => 'telegram-bridge',
                'label' => Craft::t('telegram-bridge', 'Telegram Bridge plugin'),
            ],
        ];
    }

    /**
     * Register CP Url and site rules.
     *
     * @return void
     */
    private function _registerRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['telegram-bridge/get-updates'] = 'telegram-bridge/default/get-updates';
                $event->rules['telegram-bridge/settings'] = 'telegram-bridge/settings';
            }
        );

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_SITE_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['telegram-bridge/telegram-webhook'] = 'telegram-bridge/default/telegram-webhook';
                $event->rules['telegram-bridge/craft-webhook'] = 'telegram-bridge/default/craft-webhook';
            }
        );
    }

    /**
     * @inheritDoc
     */
    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    /**
     * @inheritDoc
     */
    public function getSettingsResponse(): mixed
    {
        $url = UrlHelper::cpUrl('telegram-bridge/settings');
        return Craft::$app->getResponse()->redirect($url);
    }

    /**
     * Register plugin permission
     *
     * @return void
     */
    private function _registerPermissions()
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $toolTypes = TelegramBridge::$plugin->tool->getAllToolTypes();
                $permissions = [];
                $toolPermissions = [];
                foreach ($toolTypes as $toolType) {
                    $toolPermissions['telegram-bridge-accessTool-' . $toolType::handle()] = [
                        'label' => Craft::t('telegram-bridge', 'Access {name} tool', [
                            'name' => $toolType::displayName(),
                        ]),
                    ];
                }
                $permissions['telegram-bridge-manageSettings'] = [
                    'label' => Craft::t('telegram-bridge', 'Manage settings'),
                ];
                $permissions['telegram-bridge-getUpdates'] = [
                    'label' => Craft::t('telegram-bridge', 'Get updates'),
                ];
                $permissions['telegram-bridge-accessTools'] = [
                    'label' => Craft::t('telegram-bridge', 'Access tools'),
                    'nested' => $toolPermissions,
                ];
                $event->permissions[] = [
                    'heading' => Craft::t('telegram-bridge', 'Telegram bridge'),
                    'permissions' => $permissions,
                ];
            }
        );
    }

    /**
     * @inheritDoc
     */
    public function getCpNavItem(): ?array
    {
        $allowAdminChanges = Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
        $user = Craft::$app->getUser();

        $nav = parent::getCpNavItem();

        $nav['label'] = Craft::t('telegram-bridge', 'Telegram Bridge');

        // Get updates
        if ($user->checkPermission('telegram-bridge-getUpdates')) {
            $nav['subnav']['get-updates'] = [
                'label' => Craft::t('telegram-bridge', 'Get Updates'),
                'url' => 'telegram-bridge/get-updates',
            ];
        }

        // Settings
        if ($allowAdminChanges && $user->checkPermission('telegram-bridge-manageSettings')) {
            $nav['subnav']['settings'] = [
                'label' => Craft::t('telegram-bridge', 'Settings'),
                'url' => 'telegram-bridge/settings',
            ];
        }

        return $nav;
    }

    /**
     * Register plugin services
     *
     * @return void
     */
    private function _registerVariables(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->attachBehavior('telegrambridge', CraftVariableBehavior::class);
        });
    }
}
