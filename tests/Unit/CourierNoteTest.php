<?php

namespace Tests\Unit;

use App\Support\CourierNote;
use PHPUnit\Framework\TestCase;

class CourierNoteTest extends TestCase
{
    public function test_diet_info_is_removed_handoff_kept(): void
    {
        $this->assertSame(
            'Просить доставку 06:00-08:00',
            CourierNote::clean('БЕЗ свинини та субпродуктів. Англомовний клієнт. Просить доставку 06:00-08:00')
        );
        $this->assertSame('Залишити у консьєржа. Біла хвіртка біля аптеки', CourierNote::clean('Залишити у консьєржа. Не їсть чорнослив. Біла хвіртка біля аптеки'));
        $this->assertSame('З рук у руки', CourierNote::clean('З рук у руки'));
        $this->assertNull(CourierNote::clean('Без лактози, 1900 ккал'));
        $this->assertNull(CourierNote::clean(''));
    }
}
