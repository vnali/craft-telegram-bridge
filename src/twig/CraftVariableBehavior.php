<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\telegrambridge\twig;

use vnali\telegrambridge\TelegramBridge;

use yii\base\Behavior;

class CraftVariableBehavior extends Behavior
{
    /**
     * @var TelegramBridge
     */
    public TelegramBridge $telegrambridge;

    public function init(): void
    {
        parent::init();

        $this->telegrambridge = TelegramBridge::getInstance();
    }
}
