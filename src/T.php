<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 24.02.20 03:13:16
 */

declare(strict_types = 1);

namespace dicr\file;

use Yii;
use yii\i18n\PhpMessageSource;

/**
 * Транслятор текста.
 *
 * @package dicr\file
 */
class T extends PhpMessageSource
{
    /** @var self */
    private static $instance;

    /**
     * @inheritDoc
     */
    public function init()
    {
        $this->sourceLanguage = 'ru';
        $this->basePath = '@dicr/file/messages';

        parent::init();

        if (Yii::$app !== null) {
            Yii::$app->i18n->translations['dicr/file'] = $this;
        }

        self::$instance = $this;
    }

    /**
     * Перевод текста.
     *
     * @param string $msg
     * @return string
     */
    public static function t(string $msg)
    {
        if (self::$instance === null) {
            new self();
        }

        return Yii::t('dicr/file', $msg);
    }
}
