<?php
use PHPUnit\Framework\TestCase;
use DTApi\Helpers\TeHelper;

class TeHelperTest extends TestCase
{
    public function testWillExpireAtLessThan90Hours()
    {
        // Arrange
        $due_time = '2024-04-01 12:00:00';
        $created_at = '2024-03-30 12:00:00';

        // Act
        $result = TeHelper::willExpireAt($due_time, $created_at);

        // Assert
        $this->assertEquals('2024-04-01 12:00:00', $result);
    }

    public function testWillExpireAtLessThan24Hours()
    {
        // Arrange
        $due_time = '2024-04-01 12:00:00';
        $created_at = '2024-03-31 09:00:00'; // Less than 24 hours difference

        // Act
        $result = TeHelper::willExpireAt($due_time, $created_at);

        // Assert
        $this->assertEquals('2024-04-01 10:30:00', $result);
    }

    public function testWillExpireAtBetween24And72Hours()
    {
        // Arrange
        $due_time = '2024-04-01 12:00:00';
        $created_at = '2024-03-29 12:00:00'; // Between 24 and 72 hours difference

        // Act
        $result = TeHelper::willExpireAt($due_time, $created_at);

        // Assert
        $this->assertEquals('2024-03-31 04:00:00', $result);
    }

    public function testWillExpireAtMoreThan72Hours()
    {
        // Arrange
        $due_time = '2024-04-01 12:00:00';
        $created_at = '2024-03-20 12:00:00'; // More than 72 hours difference

        // Act
        $result = TeHelper::willExpireAt($due_time, $created_at);

        // Assert
        $this->assertEquals('2024-03-30 12:00:00', $result);
    }
}