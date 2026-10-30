<?php

use portalium\db\Migration;
use yii\db\Query;

class m261030_000002_developer_menu extends Migration
{
    private const MAIN_MENU = 'web-main-menu';
    private const SLUG = 'developer';

    public function safeUp()
    {
        $menu = (new Query())->select(['id_menu', 'id_user'])->from('{{%menu_menu}}')
            ->where(['slug' => self::MAIN_MENU])->one($this->db);
        if ($menu === false) {
            return true;
        }
        $exists = (new Query())->from('{{%menu_item}}')
            ->where(['id_menu' => $menu['id_menu'], 'slug' => self::SLUG])->exists($this->db);
        if ($exists) {
            return true;
        }
        $sort = (new Query())->select('sort')->from('{{%menu_item}}')
            ->where(['id_menu' => $menu['id_menu'], 'slug' => 'language'])->scalar($this->db);
        $this->insert('{{%menu_item}}', [
            'label' => 'API',
            'slug' => self::SLUG,
            'type' => 2,
            'style' => json_encode([
                'icon' => 'fa fa-code', 'color' => '', 'iconSize' => '', 'display' => '1', 'childDisplay' => '', 'placement' => '1',
            ]),
            'data' => json_encode(['data' => [
                'module' => 'developer',
                'routeType' => 'action',
                'route' => '/developer/default/index',
                'model' => '',
                'menuRoute' => null,
                'menuType' => null,
            ]], JSON_UNESCAPED_SLASHES),
            'sort' => $sort === false || $sort === null ? 0 : (int) $sort,
            'id_menu' => $menu['id_menu'],
            'name_auth' => 'developerWebDefaultIndex',
            'id_user' => $menu['id_user'],
            'date_create' => date('Y-m-d H:i:s'),
            'date_update' => date('Y-m-d H:i:s'),
        ]);
    }

    public function safeDown()
    {
        $ids = (new Query())->select('i.id_item')->from(['i' => '{{%menu_item}}'])
            ->innerJoin(['m' => '{{%menu_menu}}'], 'm.id_menu = i.id_menu')
            ->where(['m.slug' => self::MAIN_MENU, 'i.slug' => self::SLUG])
            ->column($this->db);
        if ($ids !== []) {
            $this->delete('{{%menu_item_child}}', ['id_child' => $ids]);
            $this->delete('{{%menu_item}}', ['id_item' => $ids]);
        }
    }
}
