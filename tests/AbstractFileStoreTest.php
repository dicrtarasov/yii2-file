<?php
/**
 * @copyright 2019-2019 Dicr http://dicr.org
 * @author Igor A Tarasov <develop@dicr.org>
 * @license GPL
 * @version 24.11.19 00:29:11
 */

declare(strict_types=1);

namespace dicr\tests;

use dicr\file\AbstractFileStore;
use dicr\file\StoreException;
use dicr\file\StoreFile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\base\InvalidConfigException;
use yii\console\Application;
use yii\di\Container;

/**
 * LocalStore Test
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
abstract class AbstractFileStoreTest extends TestCase
{
    /** @var string id компонента тестового файлового хранилища */
    public const STORE_ID = 'fileStore';

    /**
     * {@inheritdoc}
     *
     * @return Application
     * @throws InvalidConfigException
     * @throws InvalidConfigException
     */
    public static function setUpBeforeClass(): void
    {
        new Application([
            'id' => 'testapp',
            'basePath' => __DIR__,
            'vendorPath' => VENDOR,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public static function tearDownAfterClass(): void
    {
        /** @noinspection DisallowWritingIntoStaticPropertiesInspection */
        Yii::$app = null;
        /** @noinspection DisallowWritingIntoStaticPropertiesInspection */
        Yii::$container = new Container();
    }

    /**
     * Test store configured
     *
     * @throws InvalidConfigException
     */
    public function testComponentExists()
    {
        $store = static::store();

        self::assertInstanceOf(AbstractFileStore::class, $store);
        self::assertInstanceOf(StoreFile::class, $store->file(''));
    }

    /**
     * Возвращает тестовое хранилище.
     *
     * @return AbstractFileStore
     * @throws InvalidConfigException
     */
    protected static function store()
    {
        /** @noinspection PhpIncompatibleReturnTypeInspection */
        return Yii::$app->get(self::STORE_ID);
    }

    /**
     * Проверка на исключение когда путь выше родительского.
     *
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function testNormalizeInvalidPath()
    {
        $store = static::store();

        $this->expectException(StoreException::class);

        $store->normalizePath('/././../.');
    }

    /**
     * Проверка нормализации путей
     *
     * @throws InvalidConfigException
     * @throws InvalidConfigException
     * @throws InvalidConfigException
     * @throws InvalidConfigException
     * @throws InvalidConfigException
     * @throws InvalidConfigException
     * @throws InvalidConfigException
     * @throws InvalidConfigException
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function testNormalizePath()
    {
        $store = static::store();

        $tests = [
            '' => '',
            '/' => '',
            '/dir/to/file' => 'dir/to/file',
        ];

        foreach ($tests as $question => $answer) {
            self::assertSame($answer, $store->normalizePath($question));
        }

        $this->expectException(InvalidArgumentException::class);
        $store->file('')->child('')->path;

        self::assertSame('', $store->file('/')->path);
        self::assertSame('', $store->file('/')->child('/')->path);
        self::assertSame('123', $store->file('123')->child('')->path);
        self::assertSame('345', $store->file('')->child('345')->path);
        self::assertSame('123/345', $store->file('123')->child('345')->path);

        self::assertSame('d1/d2', $store->file('d1/d2/f1/')->parent->path);
        self::assertSame('f1.dat', $store->file('/d1/d2/f1.dat/')->name);

        $file = $store->file('d1/d2/f1');
        self::assertEquals($file, $file->setName('/f2/'));
        self::assertSame('d1/d2/f2', $file->path);
    }

    /**
     * @throws InvalidConfigException
     * @throws StoreException
     */
    public function testPathRelations()
    {
        $store = static::store();

        self::assertNull($store->file('')->parent);

        $file = $store->file('/1/2/3/');

        // parent
        self::assertSame('1/2', $file->parent->path);
        self::assertSame('', $file->parent->parent->parent->path);
        self::assertNull($file->parent->parent->parent->parent);

        // basename
        self::assertSame('3', $file->name);

        $this->expectException(StoreException::class);
        $store->file('')->name;

        // child
        self::assertSame('1/2/3/4', $file->child('4'));
    }

    /**
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function testType()
    {
        $store = static::store();

        $file = $store->file('test-dir');
        if (!$file->exists) {
            self::assertInstanceOf(StoreFile::class, $file->mkdir());
        }

        self::assertTrue($file->isDir);
        self::assertFalse($file->isFile);

        $file = $store->file('test-file');
        if (!$file->exists) {
            self::assertInstanceOf(StoreFile::class, $file->setContents(''));
        }

        self::assertFalse($file->isDir);
        self::assertTrue($file->isFile);

        self::assertFalse($store->file(md5((string)time()))->isDir);
        self::assertFalse($store->file(md5((string)time()))->isDir);
        self::assertFalse($store->file(md5((string)time()))->exists);
    }

    /**
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function testPublic()
    {
        $store = static::store();

        $file = $store->file('test-file');
        if (!$file->exists) {
            self::assertInstanceOf(StoreFile::class, $file->setContents(''));
        }

        self::assertInstanceOf(StoreFile::class, $file->setPublic(false));
        self::assertFalse($file->public);
        self::assertInstanceOf(StoreFile::class, $file->setPublic(true));
        self::assertTrue($file->public);

        $this->expectException(StoreException::class);
        $store->file(md5((string)time()))->public;

        $this->expectException(StoreException::class);
        $store->file(md5((string)time()))->public = true;
    }

    /**
     * @throws InvalidConfigException
     */
    public function testHidden()
    {
        $store = static::store();

        self::assertFalse($store->file('')->hidden);

        self::assertFalse($store->file('s/s')->hidden);
        self::assertFalse($store->file('.s/s')->hidden);
        self::assertTrue($store->file('.s')->hidden);
        self::assertTrue($store->file('s/.s')->hidden);
    }

    /**
     * @throws InvalidConfigException
     * @throws StoreException
     */
    public function testSize()
    {
        $store = static::store();

        $file = $store->file('test-file');

        self::assertInstanceOf(StoreFile::class, $file->setContents('1234567890'));
        self::assertSame(10, $file->size);
        self::assertInstanceOf(StoreFile::class, $file->setContents(''));
        self::assertSame(0, $file->size);

        $this->expectException(StoreException::class);
        $store->file(md5((string)time()))->size;
    }

    /**
     * @throws InvalidConfigException
     * @throws StoreException
     */
    public function testMtime()
    {
        $store = static::store();

        $file = $store->file('test-file');
        self::assertGreaterThanOrEqual(time(), $file->setContents('')->mtime);

        $this->expectException(StoreException::class);
        $store->file(md5((string)time()))->mtime;
    }

    /**
     * @throws InvalidConfigException
     */
    public function testMimeType()
    {
        $store = static::store();

        $file = $store->file('test-file');
        $file->contents = "text file\ntest content\n";
        self::assertContains($file->mimeType, ['text/plain', 'inode/x-empty']);

        $this->expectException(StoreException::class);
        $store->file(md5((string)time()))->mimeType;
    }

    /**
     * @throws InvalidConfigException
     * @throws StoreException
     */
    public function testContents()
    {
        $store = static::store();

        self::assertInstanceOf(StoreFile::class, $store->file('test-file')->setContents('12345'));
        self::assertSame(5, $store->file('test-file')->size);

        self::assertSame('12345', $store->file('test-file')->contents);

        $this->expectException(StoreException::class);
        $store->file(md5((string)time()))->contents;

        $this->expectException(StoreException::class);
        $store->file(md5((string)time()))->contents = 123;
    }

    /**
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function testStream()
    {
        $store = static::store();

        $stream = fopen('php://temp', 'wb');
        fwrite($stream, 'test');
        rewind($stream);

        self::assertInstanceOf(StoreFile::class, $store->file('test-file')->setStream($stream));
        self::assertSame(4, $store->file('test-file')->size);

        $stream = $store->file('test-file')->stream;
        self::assertIsResource($stream);
        self::assertSame('test', stream_get_contents($stream));

        $this->expectException(StoreException::class);
        $store->file(md5((string)time()))->contents;

        $this->expectException(StoreException::class);
        $store->file(md5((string)time()))->contents = 123;
    }

    /**
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function testExistsDelete()
    {
        $store = static::store();

        $dir = $store->file('test-dir');
        if (!$dir->exists) {
            self::assertInstanceOf(StoreFile::class, $dir->mkdir());
        }

        self::assertTrue($dir->isDir);

        self::assertInstanceOf(StoreFile::class, $dir->delete());
        self::assertFalse($dir->exists);

        $file = $store->file('test-file');
        if (!$file->exists) {
            self::assertInstanceOf(StoreFile::class, $file->setContents('123'));
        }

        self::assertTrue($file->exists);
        self::assertTrue($file->isFile);

        self::assertInstanceOf(StoreFile::class, $file->delete());
        self::assertFalse($file->exists);

        self::assertInstanceOf(StoreFile::class, $store->file(md5((string)time()))->delete());
    }

    /**
     * @throws StoreException
     * @throws InvalidConfigException
     */
    public function testFileListChild()
    {
        $store = static::store();

        $this->expectException(StoreException::class);
        $store->list('123');

        $dir = $store->file('test-dir');
        self::assertInstanceOf(StoreFile::class, $dir);
        if (!$dir->exists) {
            self::assertInstanceOf(StoreFile::class, $dir->mkdir());
        }

        $file = $store->file('test-file');
        self::assertInstanceOf(StoreFile::class, $file);
        if (!$file->exists) {
            self::assertInstanceOf(StoreFile::class, $file->setContents(''));
        }

        self::assertCount(2, $store->list('', [
            'regex' => '~^test\-~'
        ]));

        self::assertInstanceOf(StoreFile::class, $dir->delete());
        self::assertInstanceOf(StoreFile::class, $file->delete());
    }
}
