<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\telegrambridge\assets;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * TelegramBridgeAsset Bundle
 */
class TelegramBridgeAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->sourcePath = '@vnali/telegrambridge/resources';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'css/custom.css',
        ];

        parent::init();
    }
}
