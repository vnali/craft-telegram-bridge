<?php
/**
 * @copyright Copyright (c) vnali
 */

namespace vnali\telegrambridge\models;

use craft\base\Model;

class Settings extends Model
{
    public function rules(): array
    {
        $rules = parent::rules();
        return $rules;
    }
}
