<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../connector/__jpd.php';

final class JsonIncrementalMessageTest extends TestCase
{
    public function testRepeatedTextRemainsInIncrementalSuffix(): void
    {
        $this->assertSame(
            'っちもどっちだね。',
            __jpd_extract_incremental_message('ど', 'どっちもどっちだね。')
        );
    }

    public function testNormalCumulativeMessageReturnsOnlyNewSuffix(): void
    {
        $this->assertSame(
            ' world',
            __jpd_extract_incremental_message('Hello', 'Hello world')
        );
    }

    public function testFirstAndUnchangedMessagesAreHandled(): void
    {
        $this->assertSame('First message', __jpd_extract_incremental_message('', 'First message'));
        $this->assertSame('', __jpd_extract_incremental_message('Complete', 'Complete'));
    }

    public function testNonCumulativeRevisionDoesNotDuplicateSpeech(): void
    {
        $this->assertSame('', __jpd_extract_incremental_message('Previous', 'Revised message'));
    }
}
