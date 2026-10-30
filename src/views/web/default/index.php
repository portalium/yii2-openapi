<?php

use portalium\developer\bundles\DeveloperAsset;
use portalium\developer\Module;
use yii\helpers\Html;
use yii\helpers\Url;

/* @var $this yii\web\View */
/* @var $internal bool */

$this->title = Module::t('API Reference');
$this->params['breadcrumbs'][] = $this->title;

DeveloperAsset::register($this);
?>
<div class="developer">
    <div class="developer-bar">
        <span>
            <?= $internal
                ? Module::t('Internal operations are shown too; customers do not see them.')
                : Module::t('Requests run with your own access token and permissions.') ?>
        </span>
        <?= Html::a(Module::t('Download OpenAPI (JSON)'), ['spec', 'download' => 1], ['class' => 'btn btn-sm btn-info']) ?>
    </div>
    <?= Html::tag('iframe', '', [
        'class' => 'developer-frame',
        'src' => Url::to(['frame']),
        'title' => $this->title,
    ]) ?>
</div>
