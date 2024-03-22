<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\telegrambridge\tools;

use Craft;

use craft\commerce\elements\Order;
use craft\commerce\helpers\Currency;
use craft\commerce\Plugin as PluginCommerce;
use craft\commerce\stats\AverageOrderTotal as AverageOrderTotalStat;
use craft\commerce\stats\NewCustomers as NewCustomersStat;
use craft\commerce\stats\RepeatCustomers as RepeatingCustomersStat;
use craft\commerce\stats\TopCustomers as TopCustomersStat;
use craft\commerce\stats\TopProducts as TopProductsStat;
use craft\commerce\stats\TopProductTypes as TopProductTypesStat;
use craft\commerce\stats\TopPurchasables as TopPurchasablesStat;
use craft\commerce\stats\TotalOrders;
use craft\commerce\stats\TotalOrdersByCountry as TotalOrdersByCountryStat;
use craft\commerce\stats\TotalRevenue as TotalRevenueStat;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\Assets;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;
use Phplot\Phplot\phplot;
use vnali\telegrambridge\base\ToolTypeInterface;
use vnali\telegrambridge\helpers\General;
use vnali\telegrambridge\TelegramBridge;
use yii\caching\TagDependency;

use yii\web\ServerErrorHttpException;

class CommerceTool implements ToolTypeInterface
{
    public const Average_Order_Total = 'Average Order Total';
    public const New_Customers = 'New Customers';
    public const Repeat_Customers = 'Repeat Customers';
    public const Recent_Orders = 'Recent Orders';
    public const Top_Customers = 'Top Customers';
    public const Top_Product_Types = 'Top Product Types';
    public const Top_Products = 'Top Products';
    public const Top_Purchasables = 'Top Purchasables';
    public const Total_Orders = 'Total Orders';
    public const Total_Orders_By_Country = 'Total Orders by Country';
    public const Total_Revenue = 'Total Revenue';

    public static array $timeFrames = ['All', 'Today', 'This week', 'This month', 'This year', 'Past 7 days', 'Past 30 days', 'Past 90 days', 'Past year'];
    public static array $totalRevenueTypes = ['Total', 'Total Paid'];
    public static array $topCustomersTypes = ['Total', 'Average'];
    public static array $totalOrdersCountryTypes = ['Billing', 'Shipping'];

    /**
     * @inheritDoc
     */
    public static function handle(): string
    {
        return 'commerce';
    }

    /**
     * @inheritDoc
     */
    public static function displayName(?string $language = null): string
    {
        return Craft::t('telegram-bridge', 'Craft Commerce', [], $language);
    }

    /**
     * @inheritDoc
     */
    public static function tools(string $language): array
    {
        $tools = [];
        if (Craft::$app->plugins->isPluginInstalled('commerce') && Craft::$app->plugins->isPluginEnabled('commerce')) {
            $tools = [
                self::Average_Order_Total => craft::t('commerce', self::Average_Order_Total, [], $language),
                self::New_Customers => craft::t('commerce', self::New_Customers, [], $language),
                self::Repeat_Customers => craft::t('commerce', self::Repeat_Customers, [], $language),
                self::Recent_Orders => craft::t('commerce', self::Recent_Orders, [], $language),
                self::Top_Customers => craft::t('commerce', self::Top_Customers, [], $language),
                self::Total_Orders => craft::t('commerce', self::Total_Orders, [], $language),
                self::Total_Orders_By_Country => craft::t('commerce', self::Total_Orders_By_Country, [], $language),
                self::Top_Product_Types => craft::t('commerce', self::Top_Product_Types, [], $language),
                self::Top_Products => craft::t('commerce', self::Top_Products, [], $language),
                self::Top_Purchasables => craft::t('commerce', self::Top_Purchasables, [], $language),
                self::Total_Revenue => craft::t('commerce', self::Total_Revenue, [], $language),
            ];
        }
        return $tools;
    }

    /**
     * @inheritDoc
     */
    public static function keyboardItems(string $step, string $chatId): array
    {
        $cache = Craft::$app->getCache();
        // user language
        $language = null;
        $user = TelegramBridge::$plugin->chatId->getUserByChatId($chatId);
        if ($user) {
            $language = $user->getPreference('language') ?? 'en';
        }
        $items = [];
        if ($step == 'orderStatus') {
            $storeId = $cache->get('storeId_' . $chatId);
            $allOrderStatusIds = [];
            $items = [];
            $item = [];
            $item['text'] = Craft::t('commerce', 'All', [], $language);
            $item['callback_data'] = 'All';
            $item['new_row_after'] = true;
            array_push($items, $item);
            foreach (PluginCommerce::getInstance()->getOrderStatuses()->getAllOrderStatuses($storeId) as $orderStatus) {
                $item = [];
                $item['text'] = Craft::t('site', $orderStatus->name, [], $language);
                $item['callback_data'] = $orderStatus->name;
                array_push($items, $item);
                $allOrderStatusIds[$orderStatus->name] = $orderStatus->id;
            }
            $cache->set('allOrderStatusIds_storeId_' . $storeId, $allOrderStatusIds, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $chatId]]));
        } elseif ($step == 'orderStatuses') {
            $storeId = $cache->get('storeId_' . $chatId);
            $allOrderStatusUids = [];
            $items = [];
            foreach (PluginCommerce::getInstance()->getOrderStatuses()->getAllOrderStatuses($storeId) as $orderStatus) {
                $item = [];
                $item['text'] = Craft::t('site', $orderStatus->name, [], $language);
                $item['callback_data'] = $orderStatus->name;
                array_push($items, $item);
                $allOrderStatusUids[$orderStatus->name] = $orderStatus->uid;
            }
            $cache->set('allOrderStatusUids_storeId_' . $storeId, $allOrderStatusUids, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $chatId]]));
        } elseif ($step == 'timeframe') {
            $items = [];
            foreach (self::$timeFrames as $timeFrame) {
                $item = [];
                $item['text'] = craft::t('telegram-bridge', $timeFrame, [], $language);
                $item['callback_data'] = $timeFrame;
                array_push($items, $item);
            }
        } elseif ($step == 'totalRevenueType') {
            $items = [];
            foreach (self::$totalRevenueTypes as $totalRevenueType) {
                $item = [];
                $item['text'] = craft::t('commerce', $totalRevenueType, [], $language);
                $item['callback_data'] = $totalRevenueType;
                array_push($items, $item);
            }
        } elseif ($step == 'topCustomersType') {
            $items = [];
            foreach (self::$topCustomersTypes as $topCustomerType) {
                $item = [];
                $item['text'] = craft::t('commerce', $topCustomerType, [], $language);
                $item['callback_data'] = $topCustomerType;
                array_push($items, $item);
            }
        } elseif ($step == 'topProductTypesType' || $step == 'topProductsType' || $step == 'topPurchasablesType') {
            $types = ['Qty', 'Revenue'];
            $items = [];
            foreach ($types as $type) {
                $item = [];
                $item['text'] = craft::t('commerce', $type, [], $language);
                $item['callback_data'] = $type;
                array_push($items, $item);
            }
        } elseif ($step == 'revenueOptions') {
            $revenueOptions = [
                TopProductsStat::REVENUE_OPTION_DISCOUNT,
                TopProductsStat::REVENUE_OPTION_SHIPPING,
                TopProductsStat::REVENUE_OPTION_TAX,
            ];
            $items = [];
            foreach ($revenueOptions as $revenueOption) {
                $item = [];
                $item['text'] = craft::t('commerce', ucfirst($revenueOption), [], $language);
                $item['callback_data'] = $revenueOption;
                array_push($items, $item);
            }
            $item = [];
            $item['text'] = ucfirst(craft::t('telegram-bridge', TopProductsStat::REVENUE_OPTION_TAX_INCLUDED, [], $language));
            $item['callback_data'] = TopProductsStat::REVENUE_OPTION_TAX_INCLUDED;
            array_push($items, $item);
        } elseif ($step == 'totalOrdersCountryType') {
            $items = [];
            foreach (self::$totalOrdersCountryTypes as $totalOrdersCountryType) {
                $item = [];
                $item['text'] = craft::t('commerce', $totalOrdersCountryType, [], $language);
                $item['callback_data'] = $totalOrdersCountryType;
                array_push($items, $item);
            }
        } elseif ($step == 'storeId') {
            $items = [];
            $stores = PluginCommerce::getInstance()->getStores()->getStoresByUserId($user->id);
            foreach ($stores as $store) {
                $item = [];
                /** @var mixed $store */
                $item['text'] = Craft::t('telegram-bridge', $store->name, [], $language);
                $item['callback_data'] = $store->id;
                array_push($items, $item);
            }
        }
        return $items;
    }

    /**
     * Validate order status
     *
     * @param string $orderStatus
     * @param string $chatId
     * @return array
     */
    public static function orderStatusValidate(string $orderStatus, string $chatId): array
    {
        if ($orderStatus != 'All') {
            $cache = Craft::$app->getCache();
            $storeId = $cache->get('storeId_' . $chatId);
            $allOrderStatusUids = $cache->get('allOrderStatusUids_storeId_' . $storeId);
            if (!$allOrderStatusUids || !in_array($orderStatus, array_keys($allOrderStatusUids))) {
                return array(false, craft::t('telegram-bridge', 'The {item} is not valid.', ['item' => $orderStatus]));
            }
        }
        return array(true, null);
    }

    /**
     * Validate timeframe
     *
     * @param string $timeFrame
     * @return array
     */
    public static function timeframeValidate(string $timeFrame): array
    {
        if (!in_array($timeFrame, self::$timeFrames)) {
            $times = explode(' ', $timeFrame);
            if (isset($times[0]) && isset($times[1])) {
                if (!General::isYmdFormat($times[0])) {
                    return array(false, craft::t('telegram-bridge', 'Format of {item} is not valid.', ['item' => $times[0]]));
                }
                if (!General::isYmdFormat($times[1])) {
                    return array(false, craft::t('telegram-bridge', 'Format of {item} is not valid.', ['item' => $times[1]]));
                }
            } else {
                return array(false, craft::t('telegram-bridge', 'Start date and end date are not detected in {timeframe}.', ['timeframe' => $timeFrame]));
            }
        }
        return array(true, null);
    }

    /**
     * Validate top customer type
     *
     * @param string $type
     * @return array
     */
    public static function topCustomersTypeValidate(string $type): array
    {
        if (!in_array($type, self::$topCustomersTypes)) {
            return array(false, craft::t('telegram-bridge', 'The {item} is not valid.', ['item' => $type]));
        }
        return array(true, null);
    }

    /**
     * Validate total order country type
     *
     * @param string $type
     * @return array
     */
    public static function totalOrdersCountryTypeValidate(string $type): array
    {
        if (!in_array($type, self::$totalOrdersCountryTypes)) {
            return array(false, craft::t('telegram-bridge', 'The {item} is not valid.', ['item' => $type]));
        }
        return array(true, null);
    }

    /**
     * Validate total revenue type
     *
     * @param string $totalRevenueType
     * @return array
     */
    public static function totalRevenueTypeValidate(string $totalRevenueType): array
    {
        if (!in_array($totalRevenueType, self::$totalRevenueTypes)) {
            return array(false, craft::t('telegram-bridge', 'The {item} is not valid.', ['item' => $totalRevenueType]));
        }
        return array(true, null);
    }

    /**
     * Validate type for purchasable, product and product type
     *
     * @param string $type
     * @return array
     */
    public static function productAndPurchasableTypeValidate(string $type): array
    {
        if (!in_array($type, ['Qty', 'Revenue'])) {
            return array(false, craft::t('telegram-bridge', 'The {item} is not valid.', ['item' => $type]));
        }
        return array(true, null);
    }

    /**
     * Validate store
     *
     * @param int $storeId
     * @return array
     */
    public static function storeValidate(int $storeId, string $chatId): array
    {
        $user = TelegramBridge::$plugin->chatId->getUserByChatId($chatId);
        $stores = PluginCommerce::getInstance()->getStores()->getStoresByUserId($user->id);
        if (!in_array($storeId, $stores->pluck('id')->toArray())) {
            return array(false, craft::t('telegram-bridge', 'The user can not access the store.'));
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
        $multi = General::hasAccessToMultiStores($chatId);
        switch ($tool) {
            case self::New_Customers:
            case self::Repeat_Customers:
            case self::Average_Order_Total:
            case self::Total_Orders:
                $steps = [
                    'storeId' => [
                        'label' => craft::t('telegram-bridge', 'store', [], $language),
                        'multiple' => false,
                        'skip' => !$multi,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::storeValidate',
                    ],
                    'timeframe' => [
                        'isNullable' => false,
                        'label' => craft::t('telegram-bridge', 'date range', [], $language),
                        'multiple' => false,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::timeframeValidate',
                    ],
                    'orderStatuses' => [
                        'label' => craft::t('telegram-bridge', 'order status', [], $language),
                        'multiple' => true,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::orderStatusValidate',
                    ],
                ];
                break;
            case self::Total_Orders_By_Country:
                $steps = [
                    'storeId' => [
                        'label' => craft::t('telegram-bridge', 'store', [], $language),
                        'multiple' => false,
                        'skip' => !$multi,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::storeValidate',
                    ],
                    'timeframe' => [
                        'isNullable' => false,
                        'label' => craft::t('telegram-bridge', 'date range', [], $language),
                        'multiple' => false,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::timeframeValidate',
                    ],
                    'orderStatuses' => [
                        'label' => craft::t('telegram-bridge', 'order status', [], $language),
                        'multiple' => true,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::orderStatusValidate',
                    ],
                    'totalOrdersCountryType' => [
                        'isNullable' => false,
                        'label' => craft::t('telegram-bridge', 'type', [], $language),
                        'multiple' => false,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::totalOrdersCountryTypeValidate',
                    ],
                ];
                break;
            case self::Recent_Orders:
                $steps = [
                    'storeId' => [
                        'label' => craft::t('telegram-bridge', 'store', [], $language),
                        'multiple' => false,
                        'skip' => !$multi,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::storeValidate',
                    ],
                    'orderStatus' => [
                        'isNullable' => false,
                        'label' => craft::t('telegram-bridge', 'order status', [], $language),
                        'multiple' => false,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::orderStatusValidate',
                    ],
                    'limit' => [
                        'label' => craft::t('telegram-bridge', 'limit', [], $language),
                        'multiple' => false,
                    ],
                ];
                break;
            case self::Total_Revenue:
                $steps = [
                    'storeId' => [
                        'label' => craft::t('telegram-bridge', 'store', [], $language),
                        'multiple' => false,
                        'skip' => !$multi,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::storeValidate',
                    ],
                    'timeframe' => [
                        'isNullable' => false,
                        'label' => craft::t('telegram-bridge', 'date range', [], $language),
                        'multiple' => false,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::timeframeValidate',
                    ],
                    'orderStatuses' => [
                        'label' => craft::t('telegram-bridge', 'order status', [], $language),
                        'multiple' => true,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::orderStatusValidate',
                    ],
                    'totalRevenueType' => [
                        'isNullable' => false,
                        'label' => craft::t('telegram-bridge', 'type', [], $language),
                        'multiple' => false,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::totalRevenueTypeValidate',
                    ],
                ];
                break;
            case self::Top_Customers:
                $steps = [
                    'storeId' => [
                        'label' => craft::t('telegram-bridge', 'store', [], $language),
                        'multiple' => false,
                        'skip' => !$multi,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::storeValidate',
                    ],
                    'timeframe' => [
                        'isNullable' => false,
                        'label' => craft::t('telegram-bridge', 'date range', [], $language),
                        'multiple' => false,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::timeframeValidate',
                    ],
                    'orderStatuses' => [
                        'label' => craft::t('telegram-bridge', 'order status', [], $language),
                        'multiple' => true,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::orderStatusValidate',
                    ],
                    'topCustomersType' => [
                        'isNullable' => false,
                        'label' => craft::t('telegram-bridge', 'type', [], $language),
                        'multiple' => false,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::topCustomersTypeValidate',
                    ],
                ];
                break;
            case self::Top_Product_Types:
                $steps = [
                    'storeId' => [
                        'label' => craft::t('telegram-bridge', 'store', [], $language),
                        'multiple' => false,
                        'skip' => !$multi,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::storeValidate',
                    ],
                    'timeframe' => [
                        'isNullable' => false,
                        'label' => craft::t('telegram-bridge', 'date range', [], $language),
                        'multiple' => false,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::timeframeValidate',
                    ],
                    'orderStatuses' => [
                        'label' => craft::t('telegram-bridge', 'order status', [], $language),
                        'multiple' => true,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::orderStatusValidate',
                    ],
                    'topProductTypesType' => [
                        'isNullable' => false,
                        'label' => craft::t('telegram-bridge', 'type', [], $language),
                        'multiple' => false,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::productAndPurchasableTypeValidate',
                    ],
                ];
                break;
            case self::Top_Products:
                $steps = [
                    'storeId' => [
                        'label' => craft::t('telegram-bridge', 'store', [], $language),
                        'multiple' => false,
                        'skip' => !$multi,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::storeValidate',
                    ],
                    'timeframe' => [
                        'isNullable' => false,
                        'label' => craft::t('telegram-bridge', 'date range', [], $language),
                        'multiple' => false,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::timeframeValidate',
                    ],
                    'orderStatuses' => [
                        'label' => craft::t('telegram-bridge', 'order status', [], $language),
                        'multiple' => true,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::orderStatusValidate',
                    ],
                    'topProductsType' => [
                        'isNullable' => false,
                        'label' => craft::t('telegram-bridge', 'type', [], $language),
                        'multiple' => false,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::productAndPurchasableTypeValidate',
                    ],
                    'revenueOptions' => [
                        'label' => craft::t('telegram-bridge', 'option', [], $language),
                        'multiple' => true,
                        'showIf' => ['topProductsType' => ['revenue']],
                    ],
                ];
                break;
            case self::Top_Purchasables:
                $steps = [
                    'storeId' => [
                        'label' => craft::t('telegram-bridge', 'store', [], $language),
                        'multiple' => false,
                        'skip' => !$multi,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::storeValidate',
                    ],
                    'timeframe' => [
                        'isNullable' => false,
                        'label' => craft::t('telegram-bridge', 'date range', [], $language),
                        'multiple' => false,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::timeframeValidate',
                    ],
                    'orderStatuses' => [
                        'label' => craft::t('telegram-bridge', 'order status', [], $language),
                        'multiple' => true,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::orderStatusValidate',
                    ],
                    'topPurchasablesType' => [
                        'isNullable' => false,
                        'label' => craft::t('telegram-bridge', 'type', [], $language),
                        'multiple' => false,
                        'validation' => 'vnali\telegrambridge\tools\CommerceTool::productAndPurchasableTypeValidate',
                    ],
                ];
                break;
            default:
                throw new ServerErrorHttpException('not expected tool: ' . $tool);
        }
        // set single store to cache that user has access to
        if ($steps['storeId']['skip']) {
            $cache = Craft::$app->getCache();
            $stores = PluginCommerce::getInstance()->getStores()->getStoresByUserId($user->id);
            $cache->set('storeId_' . $chatId, $stores->first()->id, 0, new TagDependency(['tags' => ['telegram-bridge', 'telegram-bridge-' . $chatId]]));
        }
        return $steps;
    }

    /**
     * @inheritDoc
     */
    public static function render(string $toolType, string $chatId): array
    {
        // user language
        $language = null;
        $user = TelegramBridge::$plugin->chatId->getUserByChatId($chatId);
        if ($user) {
            $language = $user->getPreference('language') ?? 'en';
        }
        $cache = Craft::$app->getCache();
        $dateRange = null;
        $startDate = null;
        $endDate = null;
        if ($cache->exists('timeframe_' . $chatId)) {
            $dateRange = $cache->get('timeframe_' . $chatId);
            $startDate = null;
            $endDate = null;
            if (!in_array($dateRange, self::$timeFrames)) {
                $times = explode(' ', $dateRange);
                if (isset($times[0]) && isset($times[1])) {
                    $startDate = $times[0];
                    $endDate = $times[1];
                    $dateRange = 'custom';
                }
            } else {
                $dateRange = StringHelper::camelCase($dateRange);
            }
        }
        $limit = (int)($cache->get('limit_' . $chatId) ? $cache->get('limit_' . $chatId) : 10);
        $orderStatus = $cache->get('orderStatus_' . $chatId);
        $orderStatuses = $cache->get('orderStatuses_' . $chatId);
        $totalRevenueType = strtolower(str_replace(' ', '', $cache->get('totalRevenueType_' . $chatId)));
        $topCustomersType = $cache->get('topCustomersType_' . $chatId);
        $topProductTypesType = $cache->get('topProductTypesType_' . $chatId);
        $topProductsType = $cache->get('topProductsType_' . $chatId);
        $topPurchasablesType = $cache->get('topPurchasablesType_' . $chatId);
        $revenueOptions = $cache->get('revenueOptions_' . $chatId);
        $storeId = $cache->get('storeId_' . $chatId);
        $store = PluginCommerce::getInstance()->getStores()->getStoreById($storeId);
        if (!$revenueOptions) {
            $revenueOptions = [];
        }
        $totalOrdersCountryType = $cache->get('totalOrdersCountryType_' . $chatId);
        $temp = null;
        $messageText = null;
        if ($toolType == self::Average_Order_Total) {
            // Stat
            $_stat = new AverageOrderTotalStat(
                $dateRange,
                $startDate ? DateTimeHelper::toDateTime($startDate, true) : null,
                $endDate ? DateTimeHelper::toDateTime($endDate, true) : null,
                $storeId ?? null
            );
            // Order status
            $orderStatusesUid = self::orderStatusesUid($chatId);
            if ($orderStatusesUid) {
                $_stat->setOrderStatuses($orderStatusesUid);
            }
            $totalValue = $_stat->get();
            //
            if ($totalValue === null) {
                $totalValue = 0;
            }
            if (is_numeric($totalValue)) {
                $totalValue = Currency::formatAsCurrency($totalValue, PluginCommerce::getInstance()->getPaymentCurrencies()->getPrimaryPaymentCurrencyIso());
            } else {
                craft::error('average order total error: ' . $totalValue);
            }
            $tool = craft::t('commerce', self::Average_Order_Total, [], $language);
            $time = craft::t('telegram-bridge', $_stat->getDateRangeWording(), [], $language);
            $timeFrame = Craft::t('app', 'Date Range', [], $language);
            $orderStatuses = array_map(static function ($orderStatus) use ($language) {
                return craft::t('site', $orderStatus, [], $language);
            }, $orderStatuses);
            $messageText = $tool . ': ' . '<b>' . $totalValue . '</b>' . PHP_EOL . craft::t('commerce', 'Store', [], $language) . ': ' . $store->name . PHP_EOL . $timeFrame . ': ' . $time . PHP_EOL . craft::t('commerce', 'Order Status', [], $language) . ': ' . implode('-', $orderStatuses);
        } elseif ($toolType == self::Total_Orders) {
            // Stat
            $_stat = new TotalOrders(
                $dateRange,
                $startDate ? DateTimeHelper::toDateTime($startDate, true) : null,
                $endDate ? DateTimeHelper::toDateTime($endDate, true) : null,
                $storeId ?? null
            );
            // Order status
            $orderStatusesUid = self::orderStatusesUid($chatId);
            if ($orderStatusesUid) {
                $_stat->setOrderStatuses($orderStatusesUid);
            }
            $result = $_stat->get();
            // Table
            $tableBuilder = new \MaddHatter\MarkdownTable\Builder();
            $tableBuilder->headers([craft::t('commerce', 'Date', [], $language), craft::t('commerce', 'Total', [], $language)])->align(['L', 'L']); // set column alignment
            $data = [];
            $max = 1;
            $count = count($result['chart']);
            $i = 0;
            foreach ($result['chart'] as $res) {
                if ($count < 16) {
                    $dataKey = $res['datekey'];
                } else {
                    if ($i % 4 == 0) {
                        $dataKey = $res['datekey'];
                    } else {
                        $dataKey = '';
                    }
                }
                $total = ($res['total'] != '0') ? $res['total'] : '';
                $data[] = [$dataKey, $res['total']];
                if ($max < $res['total']) {
                    $max = $res['total'];
                }
                $tableBuilder->row([$res['datekey'], $res['total']]);
                $i++;
            }
            $table = $tableBuilder->render();
            // Title
            $tool = craft::t('commerce', self::Total_Orders, [], $language) . ': ' . $result['total'];
            $time = craft::t('telegram-bridge', $_stat->getDateRangeWording(), [], $language);
            $timeFrame = Craft::t('app', 'Date Range', [], $language);
            $orderStatuses = array_map(static function ($orderStatus) use ($language) {
                return craft::t('site', $orderStatus, [], $language);
            }, $orderStatuses);
            $title = $tool . PHP_EOL . $timeFrame . ': ' . $time . PHP_EOL . craft::t('commerce', 'Store', [], $language) . ': ' . $store->name . PHP_EOL . craft::t('commerce', 'Order Status', [], $language) . ': ' . implode('-', $orderStatuses);
            $messageText = $title . PHP_EOL . PHP_EOL . '<pre>' . $table . '</pre>';

            // Chart
            if (App::parseEnv('$SHOW_RESULT_CHART') == 'true') {
                $plot = new phplot(1280, 720);
                $plot->SetImageBorderType('plain');
                $plot->SetPlotType('bars');
                $plot->SetDataType('text-data');
                $plot->SetDataValues($data);
                $plot->SetTitle($title);

                # Turn off X tick labels and ticks because they don't apply here:
                $plot->SetXTickLabelPos('none');
                $plot->SetXTickPos('none');

                # Make sure Y=0 is displayed:
                $plot->SetPlotAreaPixels(130, 100, 1200, 600);
                $plot->SetPlotAreaWorld(null, 0, null, $max);
                if ($max < 10) {
                    $num = $max;
                } else {
                    $num = 10;
                }
                $plot->SetNumYTicks($num);
                $plot->SetPrecisionY(0);

                # Turn on Y data labels:
                $plot->SetYDataLabelPos('plotleft');
                // use angle for x labels only if the count of x labels is greater than 15
                if ($count >= 16) {
                    $plot->SetXLabelAngle(-90);
                    $plot->SetShading(0);
                }

                $temp = Assets::tempFilePath();
                $plot->SetOutputFile($temp);
                $plot->SetIsInline(true);
                $alias = Craft::getAlias('@vendor');
                $plot->SetDefaultTTFont($alias . '/vnali/craft-telegram-bridge/src/resources/fonts/OpenSans-Regular.ttf');
                $plot->SetFontTTF('generic', '', 14);
                $plot->SetFontTTF('title', '', 14);
                $plot->SetFontTTF('legend', '', 18);
                $plot->SetFontTTF('x_label', '', 12);
                $plot->SetFontTTF('y_label', '', 12);
                $plot->SetFontTTF('x_title', '', 18);
                $plot->SetFontTTF('y_title', '', 18);
                $plot->DrawGraph();
            }
        } elseif ($toolType == self::Total_Orders_By_Country) {
            // Stat
            $_stat = new TotalOrdersByCountryStat(
                $dateRange,
                strtolower($totalOrdersCountryType),
                $startDate ? DateTimeHelper::toDateTime($startDate, true) : null,
                $endDate ? DateTimeHelper::toDateTime($endDate, true) : null,
                $storeId ?? null
            );
            // Order status
            $orderStatusesUid = self::orderStatusesUid($chatId);
            if ($orderStatusesUid) {
                $_stat->setOrderStatuses($orderStatusesUid);
            }
            $stats = $_stat->get();
            // Table
            $labels = ArrayHelper::getColumn($stats, 'name', false);
            $totalOrders = ArrayHelper::getColumn($stats, 'total', false);
            $tableBuilder = new \MaddHatter\MarkdownTable\Builder();
            $tableBuilder->headers([craft::t('commerce', 'Country', [], $language), craft::t('commerce', 'Total', [], $language)])->align(['L', 'L']); // set column alignment
            $data = [];
            foreach ($labels as $key => $label) {
                $data[] = [$label, $totalOrders[$key]];
                $tableBuilder->row([$label, $totalOrders[$key]]);
            }
            $table = $tableBuilder->render();
            // title
            $tool = craft::t('commerce', self::Total_Orders_By_Country, [], $language);
            $time = craft::t('telegram-bridge', $_stat->getDateRangeWording(), [], $language);
            $timeFrame = Craft::t('app', 'Date Range', [], $language);
            $orderStatuses = array_map(static function ($orderStatus) use ($language) {
                return craft::t('site', $orderStatus, [], $language);
            }, $orderStatuses);
            $title = $tool . ' (' . craft::t('commerce', $totalOrdersCountryType, [], $language) . ')' . PHP_EOL . craft::t('commerce', 'Store', [], $language) . ': ' . $store->name . PHP_EOL . $timeFrame . ': ' . $time . PHP_EOL . craft::t('commerce', 'Order Status', [], $language) . ': ' . implode('-', $orderStatuses);
            $messageText = $title . PHP_EOL . PHP_EOL . '<pre>' . $table . '</pre>';

            if ($data && App::parseEnv('$SHOW_RESULT_CHART') == 'true') {
                $plot = new phplot(1280, 720);
                $plot->SetImageBorderType('plain');

                $plot->SetPlotType('pie');
                $plot->SetDataType('text-data-single');
                $plot->SetDataValues($data);

                # Set enough different colors;
                $plot->SetDataColors(array(
                    'red', 'green', 'blue', 'yellow', 'cyan',
                    'magenta', 'brown', 'lavender', 'pink',
                    'gray', 'orange',
                ));

                # Main plot title:
                $plot->SetTitle($title);
                $alias = Craft::getAlias('@vendor');
                $plot->SetDefaultTTFont($alias . '/vnali/craft-telegram-bridge/src/resources/fonts/OpenSans-Regular.ttf');
                $plot->SetFontTTF('generic', '', 14);
                $plot->SetFontTTF('title', '', 14);
                $plot->SetFontTTF('legend', '', 14);
                $plot->SetFontTTF('x_label', '', 14);
                $plot->SetFontTTF('y_label', '', 14);
                $plot->SetFontTTF('x_title', '', 14);
                $plot->SetFontTTF('y_title', '', 14);
                $plot->SetShading(0);
                # Build a legend from our data array.
                # Each call to SetLegend makes one line as "label: value".
                foreach ($data as $row) {
                    $plot->SetLegend(implode(': ', $row));
                }
                # Place the legend in the upper left corner:
                $plot->SetLegendPixels(5, 5);
                $temp = Assets::tempFilePath();
                $plot->SetOutputFile($temp);
                $plot->SetIsInline(true);
                $plot->DrawGraph();
            }
        } elseif ($toolType == self::Total_Revenue) {
            // Stat
            $_stat = new TotalRevenueStat(
                $dateRange,
                $startDate ? DateTimeHelper::toDateTime($startDate, true) : null,
                $endDate ? DateTimeHelper::toDateTime($endDate, true) : null,
                $storeId ?? null
            );
            // Order status
            $orderStatusesUid = self::orderStatusesUid($chatId);
            if ($orderStatusesUid) {
                $_stat->setOrderStatuses($orderStatusesUid);
            }
            $_stat->type = $totalRevenueType;
            $stats = $_stat->get();

            $revenue = ArrayHelper::getColumn($stats, 'revenue', false);
            $total = round(array_sum($revenue), 0, PHP_ROUND_HALF_DOWN);
            $formattedTotal = Currency::formatAsCurrency($total, null, false, true, true);
            $labels = ArrayHelper::getColumn($stats, 'datekey', false);
            if ($_stat->getDateRangeInterval() == 'month') {
                $labels = array_map(static function ($label) {
                    [$year, $month] = explode('-', $label);
                    $month = $month < 10 ? '0' . $month : $month;
                    return implode('-', [$year, $month, '01']);
                }, $labels);
            } elseif ($_stat->getDateRangeInterval() == 'week') {
                $labels = array_map(static function ($label) {
                    $year = substr($label, 0, 4);
                    $week = substr($label, -2);
                    return $year . 'W' . $week;
                }, $labels);
            }

            $revenue = ArrayHelper::getColumn($stats, 'revenue', false);
            $orderCount = ArrayHelper::getColumn($stats, 'count', false);
            $tableBuilder = new \MaddHatter\MarkdownTable\Builder();
            $tableBuilder->headers(['Time Range', 'Count', 'Revenue'])->align(['L', 'L', 'L']); // set column alignment
            $max1 = 1;
            $max2 = 1;
            $data1 = [];
            $data2 = [];
            $legends = [];
            foreach ($labels as $key => $label) {
                if ($max1 < $revenue[$key]) {
                    $max1 = $revenue[$key];
                }
                if ($max2 < $orderCount[$key]) {
                    $max2 = $orderCount[$key];
                }
            }

            // Table Render
            $labelsCount = count($labels);
            $i = 0;
            foreach ($labels as $key => $label) {
                if ($labelsCount < 16) {
                    $dataLabel = $label;
                } else {
                    if ($i % 4 == 0) {
                        $dataLabel = $label;
                    } else {
                        $dataLabel = '';
                    }
                }
                $formattedRevenue = Currency::formatAsCurrency($revenue[$key], null, false, true, true);
                $data1[] = [$dataLabel, $revenue[$key]];
                // Don't show 0
                $data2[] = [$dataLabel, $orderCount[$key]];
                $legends[] = $orderCount[$key];
                $tableBuilder->row([$label, $orderCount[$key], $formattedRevenue]);
                $i++;
            }
            $table = $tableBuilder->render();

            // Title
            $tool = craft::t('commerce', self::Total_Revenue, [], $language);
            $time = craft::t('telegram-bridge', $_stat->getDateRangeWording(), [], $language);
            $timeFrame = Craft::t('app', 'Date Range', [], $language);
            $orderStatuses = array_map(static function ($orderStatus) use ($language) {
                return craft::t('site', $orderStatus, [], $language);
            }, $orderStatuses);
            $totalRType = $cache->get('totalRevenueType_' . $chatId);
            $totalOrders = Craft::t('commerce', 'Total revenue', [], $language) . ': ' . $formattedTotal;
            $title = $tool . ' (' . craft::t('commerce', ucfirst($totalRType), [], $language) . ')' . PHP_EOL . craft::t('commerce', 'Store', [], $language) . ': ' . $store->name . PHP_EOL . $timeFrame . ': ' . $time . PHP_EOL . craft::t('commerce', 'Order Status', [], $language) . ': ' . implode('-', $orderStatuses);
            $messageText = $title . PHP_EOL . $totalOrders . PHP_EOL . '<pre>' . $table . '</pre>';

            if (App::parseEnv('$SHOW_RESULT_CHART') == 'true') {
                // Draw the plot
                $plot = new phplot(1280, 720);
                $alias = Craft::getAlias('@vendor');
                $plot->SetDefaultTTFont($alias . '/vnali/craft-telegram-bridge/src/resources/fonts/OpenSans-Regular.ttf');
                $plot->SetFontTTF('generic', '', 18);
                $plot->SetFontTTF('title', '', 16);
                $plot->SetFontTTF('legend', '', 12);
                $plot->SetFontTTF('x_label', '', 12);
                $plot->SetFontTTF('y_label', '', 12);
                $plot->SetFontTTF('x_title', '', 14);
                $plot->SetFontTTF('y_title', '', 14);
                $plot->SetImageBorderType('plain');
                # Disable auto-output:
                $plot->SetPrintImage(false);
                $plot->SetTitle($title);
                # Set up area for first plot:
                $plot->SetPlotAreaPixels(130, 100, 1200, 600);
                # Do the first plot:
                $plot->SetDataType('text-data');
                $plot->SetDataValues($data1);
                $plot->SetPlotAreaWorld(null, 0, null, null);
                $plot->SetDataColors(array('green'));
                // we show x ticks via second plot later
                $plot->SetXTickLabelPos('none');
                $plot->SetXTickPos('none');
                // when we want to set increment manual
                $plot->SetYTitle(craft::t('commerce', 'Revenue', [], $language));
                $plot->SetPlotType('bars');
                if ($labelsCount >= 16) {
                    $plot->SetXLabelAngle(-90);
                    $plot->SetShading(0);
                }
                $plot->DrawGraph();

                # Set up area for second plot:
                $plot->SetPlotAreaPixels(130, 100, 1200, 600);
                # the second plot:
                $plot->SetDataType('text-data');
                $plot->SetDataValues($data2);
                $plot->SetPlotAreaWorld(null, 0, null, $max2);
                // We should control number of y ticks.
                if ($max2 < 10) {
                    $num = $max2;
                } else {
                    $num = 10;
                }
                $plot->SetPrecisionY(0);
                $plot->SetNumYTicks($num);
                $plot->SetDataColors(array('blue'));
                $plot->SetPlotType('lines');
                $plot->SetYTitle(craft::t('telegram-bridge', 'Count', [], $language), "plotright");
                $plot->SetYTickLabelPos('plotright');
                $plot->SetYTickPos('plotright');
                $plot->SetYDataLabelPos('plotright');
                if ($labelsCount >= 16) {
                    $plot->SetXLabelAngle(-90);
                    $plot->SetShading(0);
                }
                // we don't want two grids in chart
                $plot->SetDrawYGrid(false);
                $plot->DrawGraph();
                # Output the image:
                $temp = Assets::tempFilePath();
                $plot->SetOutputFile($temp);
                $plot->SetIsInline(true);
                $plot->PrintImage();
            }
        } elseif ($toolType == self::New_Customers) {
            // Stat
            $_stat = new NewCustomersStat(
                $dateRange,
                $startDate ? DateTimeHelper::toDateTime($startDate, true) : null,
                $endDate ? DateTimeHelper::toDateTime($endDate, true) : null,
                $storeId ?? null
            );
            // Set Order status
            $orderStatusesUid = self::orderStatusesUid($chatId);
            if ($orderStatusesUid) {
                $_stat->setOrderStatuses($orderStatusesUid);
            }
            $newCustomers = $_stat->get();
            $tool = craft::t('commerce', self::New_Customers, [], $language);
            $time = craft::t('telegram-bridge', $_stat->getDateRangeWording(), [], $language);
            $timeFrame = Craft::t('app', 'Date Range', [], $language);
            $orderStatuses = array_map(static function ($orderStatus) use ($language) {
                return craft::t('site', $orderStatus, [], $language);
            }, $orderStatuses);
            $messageText = $tool . ': ' . '<b>' . $newCustomers . '</b>' . PHP_EOL . craft::t('commerce', 'Store', [], $language) . ': ' . $store->name . PHP_EOL . $timeFrame . ': ' . $time . PHP_EOL . craft::t('commerce', 'Order Status', [], $language) . ': ' . implode('-', $orderStatuses);
        } elseif ($toolType == self::Recent_Orders) {
            // Get orders
            $allOrderStatusIds = $cache->get('allOrderStatusIds_storeId_' . $storeId);
            if (isset($allOrderStatusIds[$orderStatus]) && $allOrderStatusIds[$orderStatus] != 'all') {
                $orders = CommerceTool::getOrders($allOrderStatusIds[$orderStatus], $limit, $storeId);
            } else {
                $orders = CommerceTool::getOrders('', $limit, $storeId);
            }
            // Table
            $tableBuilder = new \MaddHatter\MarkdownTable\Builder();
            $tableBuilder->headers([craft::t('commerce', 'Paid', [], $language), craft::t('commerce', 'Status', [], $language), craft::t('commerce', 'Date', [], $language)])->align(['L', 'L']); // set column alignment
            foreach ($orders as $key => $order) {
                $totalPaid = Currency::formatAsCurrency($order['totalPaid'], $order['currency']);
                $tableBuilder->row([$totalPaid, $order['orderStatus']['name'], $order['dateOrdered']->format('Y-m-d H:i:s')]);
            }
            $table = $tableBuilder->render();
            $tool = craft::t('commerce', self::Recent_Orders, [], $language);
            $title = craft::t('commerce', 'Store', [], $language) . ': ' . $store->name . PHP_EOL . craft::t('commerce', 'Order Status', [], $language) . ': ' . $orderStatus;
            $messageText = $tool . PHP_EOL . $title . PHP_EOL . PHP_EOL . '<pre>' . $table . '</pre>';
        } elseif ($toolType == self::Repeat_Customers) {
            // Stat
            $_stat = new RepeatingCustomersStat(
                $dateRange,
                $startDate ? DateTimeHelper::toDateTime($startDate, true) : null,
                $endDate ? DateTimeHelper::toDateTime($endDate, true) : null,
                $storeId ?? null
            );
            // Set order status
            $orderStatusesUid = self::orderStatusesUid($chatId);
            if ($orderStatusesUid) {
                $_stat->setOrderStatuses($orderStatusesUid);
            }
            $stats = $_stat->get();
            // Title
            $tool = craft::t('commerce', self::Repeat_Customers, [], $language);
            $time = craft::t('telegram-bridge', $_stat->getDateRangeWording(), [], $language);
            $timeFrame = Craft::t('app', 'Date Range', [], $language);
            $orderStatuses = array_map(static function ($orderStatus) use ($language) {
                return craft::t('site', $orderStatus, [], $language);
            }, $orderStatuses);
            $messageText = $tool . ': ' . '<b>' . $stats['percentage'] . '%' . '</b>' . PHP_EOL . craft::t('commerce', 'Store', [], $language) . ': ' . $store->name . PHP_EOL . $timeFrame . ': ' . $time . PHP_EOL . craft::t('commerce', 'Order Status', [], $language) . ': ' . implode('-', $orderStatuses);
        } elseif ($toolType == self::Top_Customers) {
            // Stat
            $_stat = new TopCustomersStat(
                $dateRange,
                strtolower($topCustomersType),
                $startDate ? DateTimeHelper::toDateTime($startDate, true) : null,
                $endDate ? DateTimeHelper::toDateTime($endDate, true) : null,
                $storeId ?? null
            );
            // Order status
            $orderStatusesUid = self::orderStatusesUid($chatId);
            if ($orderStatusesUid) {
                $_stat->setOrderStatuses($orderStatusesUid);
            }
            $stats = $_stat->get();

            // Table render
            $tableBuilder = new \MaddHatter\MarkdownTable\Builder();
            $tableBuilder->headers([craft::t('commerce', 'Customer', [], $language), craft::t('commerce', $topCustomersType, [], $language)])->align(['L', 'L']); // set column alignment
            foreach ($stats as $key => $stat) {
                $statIndex = strtolower($topCustomersType);
                $value = Currency::formatAsCurrency($stat[$statIndex], PluginCommerce::getInstance()->getPaymentCurrencies()->getPrimaryPaymentCurrencyIso());
                $tableBuilder->row([$stat['email'], $value]);
            }
            $table = $tableBuilder->render();

            // title
            $tool = craft::t('commerce', self::Top_Customers, [], $language);
            $time = craft::t('telegram-bridge', $_stat->getDateRangeWording(), [], $language);
            $timeFrame = Craft::t('app', 'Date Range', [], $language);
            $orderStatuses = array_map(static function ($orderStatus) use ($language) {
                return craft::t('site', $orderStatus, [], $language);
            }, $orderStatuses);
            $title = $tool . ' (' . craft::t('commerce', $topCustomersType, [], $language) . ')' . PHP_EOL . craft::t('commerce', 'Store', [], $language) . ': ' . $store->name . PHP_EOL . $timeFrame . ': ' . $time . PHP_EOL . craft::t('commerce', 'Order Status', [], $language) . ': ' . implode('-', $orderStatuses);
            $messageText = $title . PHP_EOL . '<pre>' . $table . '</pre>';
        } elseif ($toolType == self::Top_Product_Types) {
            // Stat
            $_stat = new TopProductTypesStat(
                $dateRange,
                $topProductTypesType,
                $startDate ? DateTimeHelper::toDateTime($startDate, true) : null,
                $endDate ? DateTimeHelper::toDateTime($endDate, true) : null,
                $storeId ?? null
            );
            // Order status
            $orderStatusesUid = self::orderStatusesUid($chatId);
            if ($orderStatusesUid) {
                $_stat->setOrderStatuses($orderStatusesUid);
            }
            $stats = $_stat->get();
            // Render table
            $tableBuilder = new \MaddHatter\MarkdownTable\Builder();
            $tableBuilder->headers([craft::t('commerce', 'Name', [], $language), craft::t('commerce', $topProductTypesType, [], $language)])->align(['L', 'L']); // set column alignment
            foreach ($stats as $key => $stat) {
                $formattedValue = $stat['qty'];
                if ($topProductTypesType == 'Revenue') {
                    $formattedValue = Currency::formatAsCurrency($stat['revenue'], null, false, true, true);
                }
                $tableBuilder->row([$stat['productType'] ? $stat['productType']['name'] : $stat['name'], $formattedValue]);
            }
            $table = $tableBuilder->render();
            // Title
            $tool = craft::t('commerce', self::Top_Product_Types, [], $language);
            $time = craft::t('telegram-bridge', $_stat->getDateRangeWording(), [], $language);
            $timeFrame = Craft::t('app', 'Date Range', [], $language);
            $orderStatuses = array_map(static function ($orderStatus) use ($language) {
                return craft::t('site', $orderStatus, [], $language);
            }, $orderStatuses);
            $title = $tool . ' (' . craft::t('commerce', $topProductTypesType, [], $language) . ')' . PHP_EOL . craft::t('commerce', 'Store', [], $language) . ': ' . $store->name . PHP_EOL . $timeFrame . ': ' . $time . PHP_EOL . craft::t('commerce', 'Order Status', [], $language) . ': ' . implode('-', $orderStatuses);
            $messageText = $title . PHP_EOL . PHP_EOL . '<pre>' . $table . '</pre>';
        } elseif ($toolType == self::Top_Products) {
            // Stat
            $_stat = new TopProductsStat(
                $dateRange,
                strtolower($topProductsType),
                $startDate ? DateTimeHelper::toDateTime($startDate, true) : null,
                $endDate ? DateTimeHelper::toDateTime($endDate, true) : null,
                $revenueOptions,
                $storeId ?? null
            );
            // Order status
            $orderStatusesUid = self::orderStatusesUid($chatId);
            if ($orderStatusesUid) {
                $_stat->setOrderStatuses($orderStatusesUid);
            }
            $stats = $_stat->get();
            // Table title
            $tableBuilder = new \MaddHatter\MarkdownTable\Builder();
            $tableBuilder->headers([craft::t('commerce', 'Title', [], $language), craft::t('commerce', $topProductsType, [], $language)])->align(['L', 'L']); // set column alignment
            foreach ($stats as $key => $stat) {
                $formattedValue = $stat['qty'];
                if ($topProductsType == 'Revenue') {
                    $formattedValue = Currency::formatAsCurrency($stat['revenue'], null, false, true, true);
                }
                $tableBuilder->row([$stat['product'] ? $stat['product']['title'] : $stat['title'], $formattedValue]);
            }
            $table = $tableBuilder->render();
            // Title
            $tool = craft::t('commerce', self::Top_Products, [], $language);
            $time = craft::t('telegram-bridge', $_stat->getDateRangeWording(), [], $language);
            $timeFrame = Craft::t('app', 'Date Range', [], $language);
            $orderStatuses = array_map(static function ($orderStatus) use ($language) {
                return craft::t('site', $orderStatus, [], $language);
            }, $orderStatuses);
            $revenueOptions = array_map(static function ($revenueOption) use ($language) {
                return craft::t('telegram-bridge', $revenueOption, [], $language);
            }, $revenueOptions);
            $revOptions = ($revenueOptions ? ' ' . craft::t('telegram-bridge', 'including', [], $language) . ': ' . implode('-', $revenueOptions) : '') . PHP_EOL;
            $title = $tool . ' (' . craft::t('commerce', $topProductsType, [], $language) . ')' . PHP_EOL . craft::t('commerce', 'Store', [], $language) . ': ' . $store->name . $revOptions . $timeFrame . ': ' . $time . PHP_EOL . craft::t('commerce', 'Order Status', [], $language) . ': ' . implode('-', $orderStatuses);
            $messageText = $title . PHP_EOL . PHP_EOL . '<pre>' . $table . '</pre>';
        } elseif ($toolType == self::Top_Purchasables) {
            // Stat
            $_stat = new TopPurchasablesStat(
                $dateRange,
                strtolower($topPurchasablesType),
                $startDate ? DateTimeHelper::toDateTime($startDate, true) : null,
                $endDate ? DateTimeHelper::toDateTime($endDate, true) : null,
                $storeId ?? null
            );
            // Set order status uids
            $orderStatusesUid = self::orderStatusesUid($chatId);
            if ($orderStatusesUid) {
                $_stat->setOrderStatuses($orderStatusesUid);
            }
            $stats = $_stat->get();
            // Render table
            $tableBuilder = new \MaddHatter\MarkdownTable\Builder();
            $tableBuilder->headers([craft::t('commerce', 'Title', [], $language), craft::t('commerce', $topPurchasablesType, [], $language)])->align(['L', 'L']); // set column alignment
            foreach ($stats as $key => $stat) {
                $formattedValue = $stat['qty'];
                if ($topPurchasablesType == 'Revenue') {
                    $formattedValue = Currency::formatAsCurrency($stat['revenue'], null, false, true, true);
                }
                $tableBuilder->row([$stat['description'], $formattedValue]);
            }
            $table = $tableBuilder->render();
            // Table title
            $tool = craft::t('commerce', self::Top_Purchasables, [], $language);
            $time = craft::t('telegram-bridge', $_stat->getDateRangeWording(), [], $language);
            $timeFrame = Craft::t('app', 'Date Range', [], $language);
            $orderStatuses = array_map(static function ($orderStatus) use ($language) {
                return craft::t('site', $orderStatus, [], $language);
            }, $orderStatuses);
            $title = $tool . ' (' . craft::t('commerce', $topPurchasablesType, [], $language) . ')' . PHP_EOL . craft::t('commerce', 'Store', [], $language) . ': ' . $store->name . PHP_EOL . $timeFrame . ': ' . $time . PHP_EOL . craft::t('commerce', 'Order Status', [], $language) . ': ' . implode('-', $orderStatuses);
            $messageText = $title . PHP_EOL . PHP_EOL . '<pre>' . $table . '</pre>';
        } else {
            throw new ServerErrorHttpException("Error Processing Request: " . $toolType);
        }
        return array($messageText, $temp);
    }

    /**
     * Get orders by order status id
     *
     * @param string $orderStatusId
     * @param int $limit
     * @param int $storeId
     * @return array
     */
    protected static function getOrders(string $orderStatusId, int $limit, int $storeId): array
    {
        $query = Order::find();
        $query->isCompleted(true);
        $query->dateOrdered(':notempty:');
        $query->limit($limit);
        $query->storeId($storeId);
        $query->orderBy('dateOrdered DESC');

        if ($orderStatusId) {
            $query->orderStatusId($orderStatusId);
        }

        return $query->all();
    }

    /**
     * Get uids of order statuses
     *
     * @param string $chatId
     * @return array
     */
    protected static function orderStatusesUid(string $chatId): array
    {
        $cache = Craft::$app->getCache();
        $orderStatusesUid = [];
        $orderStatuses = $cache->get('orderStatuses_' . $chatId);
        $storeId = $cache->get('storeId_' . $chatId);
        if (!empty($orderStatuses)) {
            $allOrderStatusUids = $cache->get('allOrderStatusUids_storeId_' . $storeId);
            if (!$allOrderStatusUids) {
                throw new ServerErrorHttpException('order status cache is not set');
            }
            foreach ($orderStatuses as $key => $orderStatus) {
                $orderStatusesUid[] = $allOrderStatusUids[$orderStatus];
            }
        }
        return $orderStatusesUid;
    }
}
