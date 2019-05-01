<?php
namespace dicr\tests;

use dicr\file\StoreFile;
use dicr\file\StoreException;
use dicr\file\AbstractFileStore;

/**
 * LocalStore Test
 *
 * @author Igor (Dicr) Tarasov <develop@dicr.org>
 * @version 2019
 */
abstract class AbstractFileStoreTest extends TestCase
{
    /** @var \dicr\file\AbstractFileStore */
    protected $store;

    /**
     * {@inheritDoc}
     * @see \dicr\tests\TestCase::setUp()
     */
    public function setUp()
    {
        parent::setUp();
        $this->store = \Yii::$app->fileStore;
    }

    /**
     * Test store configured
     */
    public function testComponentExists()
    {
        self::assertInstanceOf(AbstractFileStore::class, $this->store);
        self::assertInstanceOf(StoreFile::class, $this->store->file(''));
    }

    public function testNormalizePath()
    {
        $tests = [
            ''  => '',
            '/' => '',
            '/././../.' => '',
            '/dir/to/file' => 'dir/to/file',
        ];

        foreach ($tests as $quetion => $answer) {
            self::assertSame($answer, $this->store->normalizePath($quetion));
        }

        self::expectException(\InvalidArgumentException::class);
        $this->store->file('')->child('')->path;

        self::assertEquals('', $this->store->file('/')->path);
        self::assertEquals('', $this->store->file('/')->child('/')->path);
        self::assertEquals('123', $this->store->file('123')->child('')->path);
        self::assertEquals('345', $this->store->file('')->child('345')->path);
        self::assertEquals('123/345', $this->store->file('123')->child('345')->path);

        self::assertEquals('d1/d2', $this->store->file('d1/d2/f1/')->dir);
        self::assertEquals('f1.dat', $this->store->file('/d1/d2/f1.dat/')->name);

        $file = $this->store->file('d1/d2/f1');
        self::assertEquals($file, $file->setName('/f2/'));
        self::assertEquals('d1/d2/f2', $file->path);
    }

    public function testPathRelations()
    {
        self::assertNull($this->store->file('')->parent);

        $file = $this->store->file('/1/2/3/');

        // parent
        self::assertSame('1/2', $file->parent->path);
        self::assertSame('', $file->parent->parent->parent->path);
        self::assertNull($file->parent->parent->parent->parent);

        // basename
        self::assertSame('3', $file->name);

        self::expectException(StoreException::class);
        $this->store->file('')->name;

        // child
        self::assertSame('1/2/3/4', $file->child('4'));
    }

    public function testType()
    {
        $file = $this->store->file('test-dir');
        if (!$file->exists) {
            self::assertInstanceOf(StoreFile::class, $file->mkdir());
        }

        self::assertTrue($file->isDir);
        self::assertFalse($file->isFile);

        $file = $this->store->file('test-file');
        if (!$file->exists) {
            self::assertInstanceOf(StoreFile::class, $file->setContents(''));
        }

        self::assertFalse($file->isDir);
        self::assertTrue($file->isFile);

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->isDir;
    }

    public function testPublic()
    {
        $file = $this->store->file('test-file');
        if (!$file->exists) {
            self::assertInstanceOf(StoreFile::class, $file->setContents(''));
        }

        self::assertInstanceOf(StoreFile::class, $file->setPublic(false));

        self::assertFalse($file->public);

        self::assertInstanceOf(StoreFile::class, $file->setPublic(true));
        self::assertTrue($file->public);

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->public;

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->public = true;
    }

    public function testHidden()
    {
        self::expectException(StoreException::class);
        $this->store->file('')->hidden;

        self::assertFalse($this->store->file('s/s')->hidden);
        self::assertFalse($this->store->file('.s/s')->hidden);
        self::assertTrue($this->store->file('.s')->hidden);
        self::assertTrue($this->store->file('s/.s')->hidden);
    }

    public function testSize()
    {
        $file = $this->store->file('test-file');

        self::assertInstanceOf(StoreFile::class, $file->setContents('1234567890'));
        self::assertEquals(10, $file->size);
        self::assertInstanceOf(StoreFile::class, $file->setContents(''));
        self::assertEquals(0, $file->size);

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->size;
    }

    public function testMtime()
    {
        self::assertGreaterThanOrEqual(time(), $this->store->file('test-file')->setContents('')->mtime);

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->mtime;
    }

    public function testMimeType()
    {
        self::assertContains($this->store->file('test-file')->mimeType, ['text/plain', 'inode/x-empty']);

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->mimeType;
    }

    public function testContents()
    {
        self::assertInstanceOf(StoreFile::class, $this->store->file('test-file')->setContents('12345'));
        self::assertSame(5, $this->store->file('test-file')->size);

        self::assertSame('12345', $this->store->file('test-file')->contents);

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->contents;

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->contents = 123;
    }

    public function testStream()
    {
        $stream = fopen('php://temp', 'wt');
        fputs($stream, 'test');
        rewind($stream);

        self::assertInstanceOf(StoreFile::class, $this->store->file('test-file')->setStream($stream));
        self::assertSame(4, $this->store->file('test-file')->size);

        $stream = $this->store->file('test-file')->stream;
        self::assertInternalType('resource', $stream);
        self::assertSame('test', stream_get_contents($stream));

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->contents;

        self::expectException(StoreException::class);
        $this->store->file(md5(time()))->contents = 123;
    }

    public function testExistsDelete()
    {
        $dir = $this->store->file('test-dir');
        if (!$dir->exists) {
            self::assertInstanceOf(StoreFile::class, $dir->mkdir());
        }

        self::assertTrue($dir->isDir);

        self::assertInstanceOf(StoreFile::class, $dir->delete());
        self::assertFalse($dir->exists);

        $file = $this->store->file('test-file');
        if (!$file->exists) {
            self::assertInstanceOf(StoreFile::class, $file->setContents('123'));
        }

        self::assertTrue($file->exists);
        self::assertTrue($file->isFile);

        self::assertInstanceOf(StoreFile::class, $file->delete());
        self::assertFalse($file->exists);

        self::assertInstanceOf(StoreFile::class, $this->store->file(md5(time()))->delete());
    }

    public function testFileListChild()
    {
        self::expectException(StoreException::class);
        $this->store->list('123');

        $dir = $this->store->file('test-dir');
        self::assertInstanceOf(StoreFile::class, $dir);
        if (!$dir->exists) {
            self::assertInstanceOf(StoreFile::class, $dir->mkdir());
        }

        $file = $this->store->file('test-file');
        self::assertInstanceOf(StoreFile::class, $file);
        if (!$file->exists) {
            self::assertInstanceOf(StoreFile::class, $file->setContents(''));
        }

        self::assertCount(2, $this->store->list('', [
            'regex' => '~^test\-~'
        ]));

        self::assertInstanceOf(StoreFile::class, $dir->delete());
        self::assertInstanceOf(StoreFile::class, $file->delete());
    }
}
