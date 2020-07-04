<?php
/**
 * @copyright 2019-2020 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 04.07.20 13:06:24
 */

/** @noinspection PhpUsageOfSilenceOperatorInspection */
declare(strict_types=1);

namespace dicr\file;

use Throwable;
use yii\base\Exception;

/**
 * Ошибка хранилища файлов.
 */
class StoreException extends Exception
{
    /**
     * Конструктор
     *
     * @param string|null $msg если не задано, то берется из error_get_last
     * @param Throwable $prev
     */
    public function __construct(string $msg = '', Throwable $prev = null)
    {
        if ($msg === '') {
            $error = @error_get_last();
            /** @scrutinizer ignore-unhandled */
            @error_clear_last();
            $msg = $error['message'] ?? 'Неопределенная ошибка';
        }

        parent::__construct($msg, 0, $prev);
    }
}
