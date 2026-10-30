<?php

use yii\db\Migration;

class m261030_000001_openapi_rbac extends Migration
{
    public function up()
    {
        $auth = Yii::$app->authManager;
        $role = Yii::$app->setting->getValue('site::admin_role');
        $admin = (isset($role) && $role != '') ? $auth->getRole($role) : $auth->getRole('admin');
        $user = $auth->getRole('user');
        $permissionNames = [
            'openapiWebDefaultIndex' => true,
            'openapiWebDefaultInternal' => false,
        ];
        foreach ($permissionNames as $permissionName => $forUsers) {
            $permission = $auth->getPermission($permissionName);
            if ($permission === null) {
                $permission = $auth->createPermission($permissionName);
                $permission->description = ucfirst(str_replace('openapi', '', $permissionName));
                $auth->add($permission);
            }
            if (!$auth->hasChild($admin, $permission)) {
                $auth->addChild($admin, $permission);
            }
            if ($forUsers && $user !== null && !$auth->hasChild($user, $permission)) {
                $auth->addChild($user, $permission);
            }
        }
    }

    public function down()
    {
        $auth = Yii::$app->authManager;
        foreach (['openapiWebDefaultIndex', 'openapiWebDefaultInternal'] as $permissionName) {
            $permission = $auth->getPermission($permissionName);
            if ($permission !== null) {
                $auth->remove($permission);
            }
        }
    }
}
