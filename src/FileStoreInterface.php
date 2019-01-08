<?php
namespace dicr\file;

/**
 * Храниище в файловой системе.
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 180624
 */
interface FileStoreInterface
{
    /**
     * Создает экземпяр файла
     *
     * @param string $path относительный путь файла
     * @return \dicr\file\File
     */
    public function file(string $path);

    /**
     * Возвращает список элементов (каталога)
     *
     * @param string $path относительный путь
     * @param array $options доп. опции
     * - bool|false $recursive
     * - string|null $type - фильтр типа элементов (File::TYPE_*)
     * - string|null $access - фильтр доступности (File::ACCESS_*)
     * - bool|null $hidden - возвращать скрытые (начинающиеся с точки)
     * - string|null $regex - регулярная маска имени
     * - callable|null $filter function(string $item) : bool филььтр элементов
     * @throws \dicr\file\StoreException
     * @return \dicr\file\File[]
     */
    public function list(string $path, array $options=[]);
}
