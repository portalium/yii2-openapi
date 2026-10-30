<?php

namespace portalium\developer\tests\fixtures\demo\models;

use yii\db\ActiveRecord;

class DemoItem extends ActiveRecord
{
    public static function tableName()
    {
        return '{{%notification_notification}}';
    }

    public function rules()
    {
        return [
            [['id_to', 'title', 'text'], 'required'],
            [['id_to', 'status'], 'integer'],
            [['status'], 'in', 'range' => [0, 1]],
            [['title', 'text'], 'string', 'max' => 255],
        ];
    }
}
