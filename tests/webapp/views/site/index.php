<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 26.07.20 06:10:10
 */

/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types = 1);

use dicr\file\FileInputWidget;
use dicr\file\StoreFile;
use dicr\tests\webapp\TestModel;
use yii\bootstrap4\ActiveForm;
use yii\bootstrap4\BootstrapAsset;
use yii\bootstrap4\Html;
use yii\web\View;

/**
 * Test View
 *
 * @var View $this
 * @var TestModel $model
 */

BootstrapAsset::register($this);
?>

<?php $this->beginPage() ?>

<html style="font-size: 15px;" lang="ru">
<head>
    <meta charset="UTF-8"/>
    <title>dicr\\file\\Storage test</title>
    <!--suppress JSUnresolvedLibraryURL -->
    <link rel="stylesheet" href="https://use.fontawesome.com/releases/v5.6.3/css/all.css"/>

    <!--suppress CssUnusedSymbol -->
    <style type="text/css">
        .form-group .help-block:empty {
            display: none;
        }
    </style>
    <?php $this->head() ?>
</head>

<body style="font-size: 1rem;">
    <?php $this->beginBody() ?>

    <div class="container">
        <h1 style="margin-bottom: 2rem;">Тест загрузки файлов</h1>

        <h2>Редактирование файлов</h2>

        <?php $form = ActiveForm::begin([
            'layout' => 'horizontal',
            'fieldConfig' => [
                'horizontalCssClasses' => [
                    'label' => 'col-sm-1',
                    'offset' => 'offset-sm-1',
                    'wrapper' => 'col-sm-10',
                    'error' => '',
                    'hint' => '',
                ],
            ],
            'options' => [
                'style' => 'margin-bottom: 60px'
            ]
        ]) ?>

        <?= $form->field($model, 'icon')->widget(FileInputWidget::class, [
            'layout' => 'images',
            'limit' => 1,
            'accept' => 'image/*',
        ]) ?>

        <?= $form->field($model, 'pics')->widget(FileInputWidget::class, [
            'layout' => 'images'
        ]) ?>

        <?= $form->field($model, 'docs')->widget(FileInputWidget::class, [
            'layout' => 'files'
        ]) ?>

        <div class="form-group">
            <div class="col-sm-10 col-sm-offset-1">
                <?= Html::submitButton('Сохранить', [
                    'class' => 'btn btn-primary'
                ]) ?>
            </div>
        </div>

        <?php ActiveForm::end() ?>

        <h2>Просмотр превью картинок модели</h2>

        <?php if (! empty($model->icon) && is_a($model->icon, StoreFile::class)) { ?>
            <div style="margin-bottom: 30px;">
                <?= Html::img($model->icon->thumb([
                    'width' => 50,
                    'height' => 50
                ])->url) ?>
            </div>
        <?php } ?>

        <?php if (! empty($model->pics)) { ?>
            <div style="margin-bottom: 60px;">
                <?php foreach ($model->pics as $pic) { ?><?php if (is_a($pic, StoreFile::class)) { ?>
                    <?= Html::img($pic->thumb([
                        'width' => 50,
                        'height' => 50
                    ])->url) ?><?php } ?><?php } ?>
            </div>
        <?php } ?>
    </div>

    <?php $this->endBody() ?>
</body>
</html>

<?php $this->endPage() ?>
