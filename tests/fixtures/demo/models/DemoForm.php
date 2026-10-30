<?php

namespace portalium\developer\tests\fixtures\demo\models;

use yii\base\Model;

class DemoForm extends Model
{
    public $name;
    public $kind;
    public $email;
    public $agree;
    public $count;
    public $code;
    public $tags;
    public $note;

    public function rules()
    {
        return [
            [['name'], 'required'],
            [['name'], 'string', 'max' => 20],
            [['kind'], 'in', 'range' => ['a', 'b']],
            [['email'], 'email'],
            [['agree'], 'boolean'],
            [['count'], 'integer', 'min' => 1],
            [['code'], 'match', 'pattern' => '/^[a-z]+$/'],
            [['tags'], 'each', 'rule' => ['string']],
            [['note'], 'required', 'when' => fn ($model) => $model->kind === 'b'],
        ];
    }
}
