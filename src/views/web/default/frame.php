<?php

use portalium\developer\bundles\SwaggerAsset;
use yii\helpers\Html;
use yii\helpers\Url;

/* @var $this yii\web\View */

SwaggerAsset::register($this);
$this->registerJs('window.DeveloperConfig = ' . json_encode([
    'url' => Url::to(['spec']),
    'options' => $this->context->module->swagger,
], JSON_UNESCAPED_SLASHES) . ';', \yii\web\View::POS_HEAD);
?>
<?php $this->beginPage() ?>
<!DOCTYPE html>
<html lang="<?= Yii::$app->language ?>">
<head>
    <meta charset="<?= Yii::$app->charset ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Html::encode($this->context->module->title) ?></title>
    <?php $this->head() ?>
</head>
<body>
<?php $this->beginBody() ?>
<div id="developer-reference"></div>
<?php $this->endBody() ?>
</body>
</html>
<?php $this->endPage() ?>
