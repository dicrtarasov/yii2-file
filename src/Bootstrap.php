<?php
/*
 * @copyright 2019-2022 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL-3.0-or-later
 * @version 05.01.22 22:59:14
 */

declare(strict_types = 1);
namespace dicr\file;

use Yii;
use yii\base\BootstrapInterface;
use yii\i18n\PhpMessageSource;
use yii\web\Application;

/**
 * Автозагрузка при настройке пакета.
 */
class Bootstrap implements BootstrapInterface
{
    /**
     * @inheritDoc
     */
    public function bootstrap($app): void
    {
        $app->i18n->translations['dicr/file'] = [
            'class' => PhpMessageSource::class,
            'sourceLanguage' => 'ru',
            'basePath' => __DIR__ . '/messages'
        ];

        if (Yii::$app instanceof Application &&
            ! isset(Yii::$app->response->formatters[CSVResponseFormatter::FORMAT])) {
            Yii::$app->response->formatters[CSVResponseFormatter::FORMAT] = CSVResponseFormatter::class;
        }
    }
}
