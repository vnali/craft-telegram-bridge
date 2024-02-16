<?php

/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\telegrambridge\services;

use Craft;
use vnali\telegrambridge\tools\CommerceTool;
use vnali\telegrambridge\tools\CraftTool;
use yii\base\Component;

class toolService extends Component
{
    /**
     * Get tool types
     *
     * @return array
     */
    public function getAllToolTypes(): array
    {
        $toolTypes = [
            CraftTool::class,
        ];

        if (Craft::$app->plugins->isPluginInstalled('commerce') && Craft::$app->plugins->isPluginEnabled('commerce')) {
            $toolTypes[] = CommerceTool::class;
        }

        return $toolTypes;
    }
}
