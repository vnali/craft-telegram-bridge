<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\telegrambridge\tools;

use Craft;
use craft\elements\Entry;
use craft\helpers\ArrayHelper;
use craft\models\Section;
use MaddHatter\MarkdownTable\Builder;
use vnali\telegrambridge\base\ToolTypeInterface;
use vnali\telegrambridge\TelegramBridge;
use yii\web\ServerErrorHttpException;

/**
 * Providing tools related to Craft in telegram bot
 */
class CraftTool implements ToolTypeInterface
{
    public const RecentEntries = 'Recent Entries';
    public const MyDrafts = 'My Drafts';

    /**
     * @inheritDoc
     */
    public static function handle(): string
    {
        return 'craft';
    }

    /**
     * @inheritDoc
     */
    public static function displayName($language = null): string
    {
        return Craft::t('telegram-bridge', 'Craft', [], $language);
    }

    /**
     * @inheritDoc
     */
    public static function tools($language): array
    {
        $tools = [
            self::RecentEntries => craft::t('app', self::RecentEntries, [], $language),
            self::MyDrafts => craft::t('app', self::MyDrafts, [], $language),
        ];
        return $tools;
    }

    /**
     * Validate siteId
     *
     * @param string $siteId
     * @param string $chatId
     * @return array
     */
    public static function siteIdValidate(string $siteId, string $chatId): array
    {
        $user = TelegramBridge::$plugin->chatId->getUserByChatId($chatId);
        if (!$user) {
            return array(false, craft::t('telegram-bridge', 'User is not defined for this chat id.'));
        }
        if ($siteId != '*') {
            if (!in_array($siteId, Craft::$app->getSites()->getAllSiteIds())) {
                return array(false, craft::t('telegram-bridge', 'Selected site is not valid.'));
            }
            $site = Craft::$app->sites->getSiteById((int)$siteId);
            if (!$user->can("editSite:$site->uid")) {
                return array(false, craft::t('telegram-bridge', 'User can not access selected site.'));
            }
        }
        return array(true, null);
    }

    /**
     * Validate sectionId
     *
     * @param string $sectionId
     * @param string $chatId
     * @return array
     */
    public static function sectionIdValidate(string $sectionId, string $chatId): array
    {
        $user = TelegramBridge::$plugin->chatId->getUserByChatId($chatId);
        if (!$user) {
            return array(false, craft::t('telegram-bridge', 'User is not defined for this chat id.'));
        }
        if ($sectionId != '*') {
            $section = Craft::$app->sections->getSectionById((int)$sectionId);
            if (!$section) {
                return array(false, craft::t('telegram-bridge', 'Selected section is not valid.'));
            }
            if ($section->type == Section::TYPE_SINGLE) {
                return array(false, craft::t('telegram-bridge', 'Selected Section is single.'));
            }
            if (!$user->can('viewEntries:' . $section->uid)) {
                return array(false, craft::t('telegram-bridge', 'User can not access selected section.'));
            }
        }
        return array(true, null);
    }

    /**
     * @inheritDoc
     */
    public static function criteria(string $tool, string $chatId): array
    {
        $user = TelegramBridge::$plugin->chatId->getUserByChatId($chatId);
        $language = $user->getPreference('language') ?? 'en';
        switch ($tool) {
            case self::RecentEntries:
                $steps = [
                    'siteId' => [
                        'isNullable' => false,
                        'label' => craft::t('telegram-bridge', 'site', [], $language),
                        'multiple' => false,
                        'validation' => 'vnali\telegrambridge\tools\CraftTool::siteIdValidate',
                    ],
                    'sectionId' => [
                        'isNullable' => false,
                        'label' => craft::t('telegram-bridge', 'section', [], $language),
                        'multiple' => false,
                        'validation' => 'vnali\telegrambridge\tools\CraftTool::sectionIdValidate',
                    ],
                    'limit' => [
                        'isNullable' => false,
                        'label' => craft::t('telegram-bridge', 'limit', [], $language),
                        'multiple' => false,
                    ],
                ];
                break;
            case self::MyDrafts:
                $steps = [
                    'limit' => [
                        'isNullable' => false,
                        'label' => craft::t('telegram-bridge', 'limit', [], $language),
                        'multiple' => false,
                    ],
                ];
                break;
            default:
                throw new ServerErrorHttpException('not expected tool: ' . $tool);
        }
        return $steps;
    }

    /**
     * @inheritDoc
     */
    public static function render(string $toolType, string $chatId): array
    {
        $cache = Craft::$app->getCache();
        $messageText = '';
        $img = null;

        $limit = $cache->get('limit_' . $chatId);
        if (!$limit) {
            $limit = 10;
        }
        $section = $cache->get('sectionId_' . $chatId);
        $siteId = $cache->get('siteId_' . $chatId);
        $user = TelegramBridge::$plugin->chatId->getUserByChatId($chatId);
        $language = $user->getPreference('language') ?? 'en';

        $tableBuilder = new Builder();
        if ($toolType == self::RecentEntries) {
            if ($user) {
                $entries = self::_getEntries($section, $siteId, $chatId);
                $tableBuilder->headers([craft::t('app', 'Title', [], $language), craft::t('app', 'Date Created', [], $language), craft::t('app', 'Author', [], $language)])->align(['L', 'L', 'L']); // set column alignment
                $limitCounter = 0;
                foreach ($entries as $entry) {
                    if ($limitCounter == $limit) {
                        break;
                    }
                    $section = $entry->getSection();
                    $sectionUid = $section->uid;
                    if ($user->can('viewPeerEntries:' . $sectionUid) || ($user->can('viewEntries:' . $sectionUid) && ($entry->getAuthorId() == $user->id))) {
                        $tableBuilder->row([$entry->title, $entry->dateCreated->format('Y-m-d H:i'), $entry->getAuthor()->username]);
                        $limitCounter++;
                    }
                }
                $messageText = craft::t('app', self::RecentEntries, [], $language) . PHP_EOL . '<pre>' . $tableBuilder->render() . '</pre>';
            }
        } elseif ($toolType == self::MyDrafts) {
            if ($user) {
                /** @var Entry[] $drafts */
                $drafts = Entry::find()
                    ->drafts()
                    ->status(null)
                    ->draftCreator($user->getId())
                    ->site('*')
                    ->unique()
                    ->orderBy(['dateUpdated' => SORT_DESC])
                    ->limit($limit)
                    ->all();

                $tableBuilder->headers([craft::t('app', 'Title', [], $language), craft::t('app', 'Date Created', [], $language)])->align(['L', 'L']); // set column alignment
                foreach ($drafts as $draft) {
                    $tableBuilder->row([$draft->title, $draft->dateCreated->format('Y-m-d H:i')]);
                }
                $messageText = craft::t('app', self::MyDrafts, [], $language) . PHP_EOL . '<pre>' . $tableBuilder->render() . '</pre>';
            }
        } else {
            $messageText = 'there was a problem with ' . $toolType;
        }
        return array($messageText, $img);
    }

    /**
     * @inheritDoc
     */
    public static function keyboardItems(string $step, string $chatId): array
    {
        // user language
        $language = null;
        $user = TelegramBridge::$plugin->chatId->getUserByChatId($chatId);
        if ($user) {
            $language = $user->getPreference('language') ?? 'en';
        }
        $items = [];
        if ($step == 'siteId') {
            foreach (Craft::$app->getSites()->getAllSites() as $site) {
                if ($user->can("editSite:$site->uid")) {
                    $item = [];
                    $item['text'] = craft::t('site', $site->name, [], $language);
                    $item['callback_data'] = $site->id;
                    array_push($items, $item);
                }
            }
        } elseif ($step == 'sectionId') {
            $sections = Craft::$app->sections->getAllSections();
            $items = [];
            $item = [];
            $item['text'] = craft::t('app', 'All', [], $language);
            $item['callback_data'] = '*';
            $item['new_row_after'] = true;
            array_push($items, $item);
            foreach ($sections as $section) {
                if ($section->type != Section::TYPE_SINGLE) {
                    if ($user->can('viewEntries:' . $section->uid)) {
                        $item = [];
                        $item['text'] = craft::t('site', $section->name, [], $language);
                        $item['callback_data'] = $section->id;
                        array_push($items, $item);
                    }
                }
            }
        }
        return $items;
    }

    /**
     * Returns the recent entries, based on the tool settings and user permissions.
     *
     * @param string $section
     * @param int $siteId
     * @param string $chatId
     * @return array
     */
    private static function _getEntries(string $section, int $siteId, string $chatId): array
    {
        $targetSiteId = self::_getTargetSiteId($siteId, $chatId);

        if ($targetSiteId === null) {
            // Hopeless
            return [];
        }

        // Normalize the target section ID value.
        $editableSectionIds = self::_getEditableSectionIds($chatId);
        $targetSectionId = $section;

        if (!$targetSectionId || $targetSectionId === '*' || !in_array($targetSectionId, $editableSectionIds, false)) {
            $targetSectionId = array_merge($editableSectionIds);
        }

        if (!$targetSectionId) {
            return [];
        }

        /** @var Entry[] */
        return Entry::find()
            ->sectionId($targetSectionId)
            ->status(null)
            ->siteId($targetSiteId)
            ->with(['author'])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();
    }

    /**
     * Returns the Channel and Structure section IDs that the user is allowed to edit.
     *
     * @param string $chatId
     * @return array
     */
    private static function _getEditableSectionIds(string $chatId): array
    {
        $sectionIds = [];
        foreach (self::_getEditableSections($chatId) as $section) {
            if ($section->type != Section::TYPE_SINGLE) {
                $sectionIds[] = $section->id;
            }
        }

        return $sectionIds;
    }

    /**
     * Returns the target site ID for the tool.
     *
     * @param int $siteId
     * @param string $chatId
     * @return int|null
     */
    private static function _getTargetSiteId(int $siteId, string $chatId): int|null
    {
        if (!Craft::$app->getIsMultiSite()) {
            return $siteId;
        }

        // Make sure that the user is actually allowed to edit entries in the current site. Otherwise grab entries in
        // their first editable site.

        // Figure out which sites the user is actually allowed to edit
        $editableSiteIds = self::_getEditableSiteIds($chatId);

        // If they aren't allowed to edit *any* sites, return false
        if (empty($editableSiteIds)) {
            return null;
        }

        // Figure out which site was selected in the settings
        $targetSiteId = $siteId;

        // Only use that site if it still exists and they're allowed to edit it.
        // Otherwise go with the first site that they are allowed to edit.
        if (!in_array($targetSiteId, $editableSiteIds, false)) {
            $targetSiteId = $editableSiteIds[0];
        }

        return $targetSiteId;
    }

    /**
     * Get editable site Ids for a chatId
     *
     * @param string $chatId
     * @return array|null
     */
    private static function _getEditableSiteIds(string $chatId): array|null
    {
        if (!Craft::$app->getIsMultiSite()) {
            return Craft::$app->getSites()->getAllSiteIds(true);
        }

        $_editableSiteIds = [];
        $user = TelegramBridge::$plugin->chatId->getUserByChatId($chatId);
        if (!$user) {
            return null;
        }

        foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
            if ($user->can("editSite:$site->uid")) {
                $_editableSiteIds[] = $site->id;
            }
        }

        return $_editableSiteIds;
    }

    /**
     * Get editable sections for a chatId
     *
     * @param string $chatId
     * @return array
     */
    private static function _getEditableSections(string $chatId): array
    {
        $user = TelegramBridge::$plugin->chatId->getUserByChatId($chatId);

        if (!$user) {
            return [];
        }

        return ArrayHelper::where(Craft::$app->getSections()->getAllSections(), function(Section $section) use ($user) {
            return $user->can("viewEntries:$section->uid");
        }, true, true, false);
    }
}
