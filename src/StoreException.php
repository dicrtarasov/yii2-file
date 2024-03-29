<?php
/*
 * @copyright 2019-2022 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL-3.0-or-later
 * @version 05.01.22 01:42:48
 */

declare(strict_types = 1);
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
     * @param ?string $msg если не задано, то берется из error_get_last
     */
    public function __construct(?string $msg = null, ?Throwable $prev = null)
    {
        $msg = (string)$msg;
        if ($msg === '') {
            $error = error_get_last();
            error_clear_last();
            $msg = $error['message'] ?? 'Неопределенная ошибка';
        }

        parent::__construct($msg, 0, $prev);
    }
}
